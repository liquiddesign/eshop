<?php

declare(strict_types=1);

namespace Eshop\Integration;

use Carbon\Carbon;
use Eshop\DB\CategoryRepository;
use Eshop\DB\EHubTransaction;
use Eshop\DB\EHubTransactionRepository;
use Eshop\DB\Order;
use Eshop\DB\OrderRepository;
use Eshop\Providers\Helpers;
use GuzzleHttp\Client;
use Nette\Utils\Arrays;
use Nette\Utils\Random;
use StORM\Collection;
use StORM\DIConnection;
use Tracy\Debugger;
use Tracy\ILogger;

class EHub
{
	/**
	 * 1..100
	 */
	protected const PER_PAGE = 100;

	protected const ORDER_STATUS_MAP = [
		Order::STATE_OPEN => EHubTransaction::STATUS_PENDING,
		Order::STATE_RECEIVED => EHubTransaction::STATUS_PENDING,
		Order::STATE_COMPLETED => EHubTransaction::STATUS_APPROVED,
		Order::STATE_CANCELED => EHubTransaction::STATUS_DECLINED,
	];

	/** @var array<string> */
	private array $queryParams;

	private string $advertiserId;

	private Client $transactionsClient;

	private Client $scriptsClient;

	private OrderRepository $orderRepository;

	private EHubTransactionRepository $EHubTransactionRepository;

	private CategoryRepository $categoryRepository;

	public function __construct(
		?string $apiUrl = null,
		?string $apikey = null,
		?string $advertiserId = null,
		?string $scriptsUrl = null,
		?OrderRepository $orderRepository = null,
		?EHubTransactionRepository $EHubTransactionRepository = null,
		?CategoryRepository $categoryRepository = null
	) {
		$this->orderRepository = $orderRepository;
		$this->EHubTransactionRepository = $EHubTransactionRepository;
		$this->categoryRepository = $categoryRepository;

		if ($scriptsUrl) {
			$this->scriptsClient = new Client([
				'base_uri' => $scriptsUrl,
				'timeout' => 10.0,
			]);
		}

		if (!$apiUrl || !$apikey || !$advertiserId) {
			return;
		}

		$this->transactionsClient = new Client([
			'base_uri' => $apiUrl,
			'timeout' => 10.0,
		]);

		$this->advertiserId = $advertiserId;
		$this->queryParams = ['apiKey' => $apikey];
	}

	public function checkTransactions(): bool
	{
		return isset($this->transactionsClient) && isset($this->advertiserId) && isset($this->queryParams);
	}

	public function checkSales(): bool
	{
		return isset($this->scriptsClient);
	}

	/**
	 * @return array<mixed>
	 * @throws \GuzzleHttp\Exception\GuzzleException|\Nette\Utils\JsonException
	 */
	public function getTransactionList(): array
	{
		if (!$this->checkTransactions()) {
			return [];
		}

		$response = $this->transactionsClient->get("advertisers/$this->advertiserId/transactions/", [
			'query' => $this->queryParams + [
					'perPage' => $this::PER_PAGE,
				],
		]);

		if ($response->getStatusCode() !== 200) {
			throw new \Exception('Invalid response from eHub.', $response->getStatusCode());
		}

		$result = Helpers::convertJsonToArray((string)$response->getBody());

		if ($result['totalItems'] > $this::PER_PAGE) {
			$loaded = $this::PER_PAGE;
			$page = 2;

			while ($loaded < $result['totalItems']) {
				$response = $this->transactionsClient->get("advertisers/$this->advertiserId/transactions/", [
					'query' => $this->queryParams + [
							'page' => $page,
							'perPage' => $this::PER_PAGE,
						],
				]);

				$result['transactions'] = \array_merge($result['transactions'], Helpers::convertJsonToArray((string)$response->getBody())['transactions']);

				$page++;
				$loaded += $this::PER_PAGE;
			}
		}

		return $result['transactions'];
	}

	/**
	 * @param \Eshop\DB\EHubTransaction $transaction
	 * @param string $status
	 * @return array<mixed>
	 * @throws \GuzzleHttp\Exception\GuzzleException
	 */
	public function updateTransaction(EHubTransaction $transaction, string $status): array
	{
		if (!$this->checkTransactions()) {
			return [];
		}

		$response = $this->transactionsClient->patch("advertisers/$this->advertiserId/transactions/$transaction->transactionId", [
			'query' => $this->queryParams,
			'json' => [
				'status' => $status,
			],
			'verify' => false,
		]);

		if ($response->getStatusCode() !== 200) {
			throw new \Exception('Invalid response from eHub.', $response->getStatusCode());
		}

		return Helpers::convertJsonToArray((string)$response->getBody());
	}

	/**
	 * @param \Eshop\DB\Order $order
	 * @return array<mixed>
	 * @throws \GuzzleHttp\Exception\GuzzleException|\StORM\Exception\NotFoundException
	 */
	public function updateTransactionByOrder(Order $order): array
	{
		if (!$this->checkTransactions() || !isset($this::ORDER_STATUS_MAP[$order->getState()])) {
			return [];
		}

		$status = $this::ORDER_STATUS_MAP[$order->getState()];

		if (!$order->invoices->isEmpty()) {
			$status = EHubTransaction::STATUS_INVOICED;
		}

		if ($order->getPayment() && $order->getPayment()->paidTs) {
			$status = EHubTransaction::STATUS_PAID;
		}

		$json = [
			'status' => $status,
			'orderAmount' => \round($order->getTotalPriceVat(), 2),
			'orderItems' => [],
			'currency' => $order->purchase->currency->code,
			'newCustomer' => $order->newCustomer,
		];

		foreach ($order->purchase->getItems() as $item) {
			$json['orderItems'][] = [
				'itemId' => $item->product ? $item->product->getFullCode() : $item->getFullCode(),
				'masterType' => $item->product && $item->product->primaryCategory ? Arrays::first($this->categoryRepository->getBranch($item->product->primaryCategory)) : null,
				'category' => $item->product && $item->product->primaryCategory ? $item->product->primaryCategory->name : null,
				'name' => $item->product ? $item->product->name : $item->productName,
				'unitPrice' => \round($item->priceVat, 2),
				'quantity' => $item->amount,
			];
		}

		do {
			$transactionId = Random::generate(8, '0-9a-f');
		} while ($this->EHubTransactionRepository->many()->where('transactionId', $transactionId)->first() !== null);

		$response = $this->transactionsClient->patch("advertisers/$this->advertiserId/transactions/$transactionId", [
			'query' => $this->queryParams,
			'json' => $json,
		]);

		if ($response->getStatusCode() !== 200) {
			throw new \Exception('Invalid response from eHub.', $response->getStatusCode());
		}

		return Helpers::convertJsonToArray((string)$response->getBody());
	}

	/**
	 * Download all transactions, save to DB, pair with orders if possible
	 * @throws \GuzzleHttp\Exception\GuzzleException
	 * @throws \Nette\Utils\JsonException
	 * @throws \StORM\Exception\NotFoundException
	 */
	public function syncTransactions(): void
	{
		if (!$this->checkTransactions()) {
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
			$transactionValues['createdTs'] = (new Carbon($transaction['dateTime']))->format('Y-m-d G:i');
			$transactionValues['clickDateTime'] = (new Carbon($transaction['clickDateTime']))->format('Y-m-d G:i');
			$transactionValues['orderAmount'] = (float) $transaction['orderAmount'];
			$transactionValues['originalOrderAmount'] = $transaction['originalOrderAmount'] ?? null;
			$transactionValues['originalCurrency'] = $transaction['originalCurrency'] ?? null;
			$transactionValues['commission'] = isset($transaction['commission']) ? (float) $transaction['commission'] : null;
			$transactionValues['type'] = $transaction['type'];
			$transactionValues['orderId'] = $transaction['orderId'] ?? null;
			$transactionValues['couponCode'] = $transaction['couponCode'] ?? null;
			$transactionValues['newCustomer'] = $transaction['newCustomer'] ?? null;

			if (isset($orders[$transaction['orderId']])) {
				$transactionValues['order'] = $orders[$transaction['orderId']];
			}

			$newTransactionsValues[] = $transactionValues;
		}

		$this->EHubTransactionRepository->syncMany($newTransactionsValues);
	}

	/**
	 * Send all or selected orders to eHub and create eHubTransactions in DB
	 * @param \StORM\Collection<\Eshop\DB\Order>|null $orders
	 */
	public function syncOrders(?Collection $orders = null): bool
	{
		if (!$this->checkSales()) {
			return false;
		}

		$orders->join(['eh_t' => 'eshop_ehubtransaction'], 'this.uuid = eh_t.fk_order')
			->where('eh_t.uuid IS NULL');

		$check = true;

		/** @var \Eshop\DB\Order $order */
		foreach ($orders as $order) {
			try {
				$this->sendSaleByOrder($order);
			} catch (\Exception $e) {
				Debugger::log($e->getMessage(), ILogger::WARNING);

				$check = false;
			}
		}

		return $check;
	}

	/**
	 * Change state of transactions that are valid by conditions from specified date to now and send to ehub
	 * @param callable $condition
	 * @param \DateTime|null $from
	 */
	public function updateTransactions(callable $condition, ?\DateTime $from = null, ?\DateTime $to = null, bool $syncTransactions = true): void
	{
		if ($syncTransactions) {
			$this->syncTransactions();
		}

		$transactions = $this->EHubTransactionRepository->many()
			->whereNot('this.status', EHubTransaction::STATUS_APPROVED)
			->whereNot('this.status', EHubTransaction::STATUS_DECLINED);

		if ($from) {
			$fromString = $from->format('Y-m-d\TH:i:s');

			$transactions->where('this.createdTs >= :from', ['from' => $fromString]);
		}

		if ($to) {
			$toString = $to->format('Y-m-d\TH:i:s');

			$transactions->where('this.createdTs <= :to', ['to' => $toString]);
		}

		while ($transaction = $transactions->fetch()) {
			/** @var \Eshop\DB\EHubTransaction $transaction */

			$approved = $condition($transaction);

			if ($approved === null) {
				continue;
			}

			if ($approved === true) {
				$this->updateTransaction($transaction, EHubTransaction::STATUS_APPROVED);

				continue;
			}

			$this->updateTransaction($transaction, EHubTransaction::STATUS_DECLINED);
		}

		if (!$syncTransactions) {
			return;
		}

		$this->syncTransactions();
	}

	private function sendSaleByOrder(Order $order): void
	{
		$json = [
			'visitId' => $order->eHubVisitId,
			'orderId' => $order->code,
			'orderAmount' => \round($order->getTotalPriceVat(), 2),
			'orderItems' => [],
			'currency' => $order->purchase->currency->code,
			'couponCode' => $order->getDiscountCoupon() ? $order->getDiscountCoupon()->code : null,
			'couponDiscount' => $order->getDiscountCoupon() ? \round($order->getDiscountPriceVat(), 2) : null,
			'paymentMethod' => $order->purchase->paymentType ? $order->purchase->paymentType->code : null,
			'newCustomer' => $order->newCustomer,
		];

		foreach ($order->purchase->getItems() as $item) {
			$json['orderItems'][] = [
				'id' => $item->product ? $item->product->getFullCode() : $item->getFullCode(),
				'masterType' => $item->product && $item->product->primaryCategory ? Arrays::first($this->categoryRepository->getBranch($item->product->primaryCategory)) : null,
				'category' => $item->product && $item->product->primaryCategory ? $item->product->primaryCategory->name : null,
				'name' => $item->product ? $item->product->name : $item->productName,
				'unitPrice' => \round($item->priceVat, 2),
				'quantity' => $item->amount,
			];
		}

		$response = $this->scriptsClient->post('sale.php', ['json' => $json]);

		if ($response->getStatusCode() !== 200) {
			throw new \Exception((string) $response->getBody());
		}
	}
}
