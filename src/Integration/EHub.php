<?php

declare(strict_types=1);

namespace Eshop\Integration;

use Eshop\DB\EHubTransaction;
use Eshop\DB\EHubTransactionRepository;
use Eshop\DB\Order;
use Eshop\DB\OrderRepository;
use Eshop\Providers\Helpers;
use GuzzleHttp\Client;
use StORM\Collection;
use StORM\DIConnection;

class EHub
{
	/**
	 * 1..100
	 */
	protected const PER_PAGE = 100;

	/** @var array<string> */
	private array $queryParams;

	private string $advertiserId;

	private Client $client;

	private OrderRepository $orderRepository;

	private EHubTransactionRepository $EHubTransactionRepository;

	public function __construct(?string $url, ?string $apikey, ?string $advertiserId, ?OrderRepository $orderRepository, ?EHubTransactionRepository $EHubTransactionRepository)
	{
		$this->orderRepository = $orderRepository;
		$this->EHubTransactionRepository = $EHubTransactionRepository;

		if (!$url || !$apikey || !$advertiserId) {
			return;
		}

		$this->client = new Client([
			'base_uri' => $url,
			'timeout' => 10.0,
		]);

		$this->advertiserId = $advertiserId;
		$this->queryParams = ['apiKey' => $apikey];
	}

	public function check(): bool
	{
		return isset($this->client) && isset($this->advertiserId) && isset($this->queryParams);
	}

	/**
	 * @return array<mixed>
	 * @throws \GuzzleHttp\Exception\GuzzleException|\Nette\Utils\JsonException
	 */
	public function getTransactionList(): array
	{
		if (!$this->check()) {
			return [];
		}

		$response = $this->client->get("advertisers/$this->advertiserId/transactions/", [
			'query' => $this->queryParams + [
				'perPage' => $this::PER_PAGE,
			],
		]);

		if ($response->getStatusCode() !== 200) {
			throw new \Exception('Invalid response from eHub.', $response->getStatusCode());
		}

		$result = Helpers::convertJsonToArray((string) $response->getBody());

		if ($result['totalItems'] > $this::PER_PAGE) {
			$loaded = $this::PER_PAGE;
			$page = 2;

			while ($loaded < $result['totalItems']) {
				$response = $this->client->get("advertisers/$this->advertiserId/transactions/", [
					'query' => $this->queryParams + [
						'page' => $page,
						'perPage' => $this::PER_PAGE,
					],
				]);

				$result['transactions'] = \array_merge($result['transactions'], Helpers::convertJsonToArray((string) $response->getBody())['transactions']);

				$page++;
				$loaded += $this::PER_PAGE;
			}
		}

		return $result['transactions'];
	}

	/**
	 * @param \Eshop\DB\EHubTransaction $transaction
	 * @return array<mixed>
	 * @throws \GuzzleHttp\Exception\GuzzleException
	 */
	public function updateTransaction(EHubTransaction $transaction): array
	{
		if (!$this->check()) {
			return [];
		}

		$response = $this->client->patch("advertisers/$this->advertiserId/transactions/$transaction->transactionId", [
			'query' => $this->queryParams,
			'json' => [],
		]);

		if ($response->getStatusCode() !== 200) {
			throw new \Exception('Invalid response from eHub.', $response->getStatusCode());
		}

		return Helpers::convertJsonToArray((string) $response->getBody());
	}

	/**
	 * @param \Eshop\DB\Order $order
	 * @return array<mixed>
	 * @throws \GuzzleHttp\Exception\GuzzleException|\Nette\Utils\JsonException
	 */
	public function updateTransactionByOrder(Order $order): array
	{
		if (!$this->check()) {
			return [];
		}

		$response = $this->client->patch("advertisers/$this->advertiserId/transactions/$order->code", [
			'query' => $this->queryParams,
			'json' => [
				'status' => $order->getState(),
			],
		]);

		if ($response->getStatusCode() !== 200) {
			throw new \Exception('Invalid response from eHub.', $response->getStatusCode());
		}

		return Helpers::convertJsonToArray((string) $response->getBody());
	}

	/**
	 * Download all transactions, save to DB, pair with orders if possible
	 * @throws \GuzzleHttp\Exception\GuzzleException
	 * @throws \Nette\Utils\JsonException
	 * @throws \StORM\Exception\NotFoundException
	 */
	public function syncTransactions(): void
	{
		if (!$this->check()) {
			return;
		}

		$transactions = $this->getTransactionList();

		/** @var array<\Eshop\DB\Order> $orders */
		$orders = $this->orderRepository->many()->setIndex('this.code')->toArrayOf('uuid');
		/** @var array<\Eshop\DB\EHubTransaction> $existingTransactions */
		$existingTransactions = $this->EHubTransactionRepository->many()->toArray();

		$newTransactionsValues = [];

		foreach ($transactions as $transaction) {
			$transactionPK = DIConnection::generateUuid('eHubTransactionId', $transaction['id']);
			$transactionValues = [
				'order' => null,
			];

			if (isset($existingTransactions[$transactionPK])) {
				$transactionValues = $existingTransactions[$transactionPK]->toArray();
			}

			$transactionValues['transactionId'] = $transaction['id'];
			$transactionValues['status'] = $transaction['status'];

			if (isset($orders[$transaction['orderId']])) {
				$transactionValues['order'] = $orders[$transaction['orderId']];
			}

			$newTransactionsValues[] = $transactionValues;
		}

		$this->EHubTransactionRepository->syncMany($newTransactionsValues);
	}

	/**
	 * Send all or selected orders to eHub and create eHubTransactions in DB
	 * @param \StORM\Collection|null $orders
	 */
	public function syncOrders(?Collection $orders = null): void
	{
		if (!$this->check()) {
			return;
		}

		unset($orders);
	}
}
