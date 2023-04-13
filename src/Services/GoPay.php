<?php

declare(strict_types=1);

namespace Eshop\Services;

use Contributte\GopayInline\Api\Entity\PaymentFactory;
use Contributte\GopayInline\Api\Lists\Language;
use Contributte\GopayInline\Client;
use Contributte\GopayInline\Http\Response;
use Eshop\Admin\SettingsPresenter;
use Eshop\CheckoutManager;
use Eshop\Common\IPaymentIntegration;
use Eshop\DB\Order;
use Eshop\DB\OrderRepository;
use Eshop\DB\PaymentResult;
use Eshop\DB\PaymentResultRepository;
use Eshop\DB\PaymentTypeRepository;
use Nette\Http\Request;
use Tracy\Debugger;
use Tracy\ILogger;
use Web\DB\SettingRepository;

class GoPay implements IPaymentIntegration
{
	public Client $client;

	public CheckoutManager $checkoutManager;

	public OrderRepository $orderRepository;

	public SettingRepository $settingRepository;

	public PaymentTypeRepository $paymentTypeRepository;

	public PaymentResultRepository $paymentResultRepository;

	public Request $request;

	public function __construct(
		Client $client,
		CheckoutManager $checkoutManager,
		OrderRepository $orderRepository,
		SettingRepository $settingRepository,
		PaymentTypeRepository $paymentTypeRepository,
		PaymentResultRepository $paymentResultRepository,
		Request $request
	) {
		$this->client = $client;
		$this->checkoutManager = $checkoutManager;
		$this->orderRepository = $orderRepository;
		$this->settingRepository = $settingRepository;
		$this->paymentTypeRepository = $paymentTypeRepository;
		$this->paymentResultRepository = $paymentResultRepository;
		$this->request = $request;
	}

	public function createPayment(Order $order): Response
	{
		$purchase = $order->purchase;

		$baseUrl = $this->request->getUrl()->getBaseUrl();

		$payment = [
			'payer' => [
				'contact' => [
					'last_name' => $purchase->fullname,
					'email' => $purchase->email,
					'phone_number' => $purchase->phone,
					'city' => $purchase->billAddress->city,
					'street' => $purchase->billAddress->street,
					'postal_code' => $purchase->billAddress->zipcode,
					'country_code' => $purchase->billAddress->state ?? 'CZE',
				],
			],
			'amount' => \Money\Money::CZK((int) ($order->getTotalPriceVat() * 100)),
			'order_number' => $order->code,
			'callback' => [
				'return_url' => $baseUrl . 'payment-summary',
				'notify_url' => $baseUrl . 'payment-result',
			],
			'items' => [],
			'lang' => Language::CZ,
		];

		foreach ($order->purchase->getItems() as $item) {
			$payment['items'][] = [
				'type' => 'ITEM',
				'name' => $item->productName,
				'count' => $item->amount,
				'amount' => \Money\Money::CZK((int) ($item->getPriceVatSum() * 100)),
			];
		}

		$deliveryPaymentPrice = $order->getPaymentPriceVatSum() + $order->getDeliveryPriceVatSum();

		if ($deliveryPaymentPrice > 0 && ($deliveryType = $order->purchase->deliveryType)) {
			$payment['items'][] = [
				'type' => 'DELIVERY',
				'name' => $deliveryType->name,
				'count' => 1,
				'amount' => \Money\Money::CZK((int) ($deliveryPaymentPrice * 100)),
			];
		}

		return $this->client->payments->createPayment(PaymentFactory::create($payment));
	}

	public function processPaymentCallback(): void
	{
		$this->checkoutManager->onOrderCreate[] = function (Order $order): void {
			$this->processPayment($order);
		};
	}

	public function processPayment(Order $order): void
	{
		/** @var \Eshop\DB\Order $order */
		$order = $this->orderRepository->one($order->getPK(), true);

		$paymentTypes = $this->settingRepository->getValuesByName(SettingsPresenter::GO_PAY_PAYMENT_TYPE);

		if (!$paymentTypes) {
			return;
		}

		$paymentTypes = $this->paymentTypeRepository->many()->where('this.uuid', $paymentTypes)->toArray();

		$orderPaymentType = $order->getPayment()->type;

		if (!$orderPaymentType) {
			return;
		}

		if (!isset($paymentTypes[$orderPaymentType->getPK()])) {
			return;
		}

		$response = $this->createPayment($order);

		if ($response->isSuccess()) {
			$data = $response->getData();
			$url = $data['gw_url'];

			$this->paymentResultRepository->saveTransaction((string) $data['id'], $order->getTotalPriceVat(), $order->getPayment()->currency->code, $data['state'], 'goPay', $order);

			\header('location: ' . $url);
			exit;
		}

		Debugger::log($response, ILogger::WARNING);
	}

	/**
	 * @param string $id
	 * @return array<mixed>|null
	 */
	public function checkPaymentStatus(string $id): ?array
	{
		$result = $this->client->payments->verify($id);

		if (!$result->isSuccess()) {
			return null;
		}

		return $result->getData();
	}

	public function savePaymentStatus(string $id): void
	{
		unset($id);

		return;
	}

	public function getUrl(PaymentResult $paymentResult, array $result): string
	{
		unset($paymentResult);

		return $result['gw_url'];
	}

	/**
	 * @inheritDoc
	 */
	public function processPaymentSummary(string $id): array
	{
		/** @var \Eshop\DB\PaymentResult|null $paymentResult */
		$paymentResult = $this->paymentResultRepository->many()->where('id', $id)->first();

		if (!$paymentResult || $paymentResult->service !== 'goPay') {
			throw new \Exception("Payment '$id' not found!");
		}

		$result = $this->checkPaymentStatus($id);

		$order = $paymentResult->order;

		if (!$order) {
			throw new \Exception('Order not found!');
		}

		return [
			'status' => (string) $result['state'],
			'order' => $order,
			'paymentResultId' => $id,
			'url' => $this->getUrl($paymentResult, $result),
			'customer' => $paymentResult->order->purchase->customer,
			'merchant' => $paymentResult->order->purchase->merchant,
		];
	}

	/**
	 * @inheritDoc
	 */
	public function processPaymentResult(Request $request): array
	{
		$id = $request->getQuery('id');

		if (!$id) {
			throw new \Exception("Required parameter '$id' not found!");
		}

		/** @var \Eshop\DB\PaymentResult|null $paymentResult */
		$paymentResult = $this->paymentResultRepository->one(['id' => $id]);

		if (!$paymentResult) {
			throw new \Exception("Payment '$id' not found!");
		}

		if ($paymentResult->service !== 'goPay') {
			throw new \Exception('Not a GoPay payment!');
		}

		$paymentStatus = $this->checkPaymentStatus($id);

		if (!$paymentStatus) {
			throw new \Exception("Payment '$id': Can't load status from API!");
		}

		$paymentResult->update([
			'status' => $paymentStatus['state'],
		]);

		if ($paymentStatus['state'] !== 'PAID') {
			return [];
		}

		$paymentResult = $paymentResult->order->getPayment();

		if (!$paymentResult) {
			return [];
		}

		$this->orderRepository->changePayment($paymentResult->getPK(), true, true);

		return [];
	}
}
