<?php

declare(strict_types=1);

namespace Eshop\Services;

use Contributte\GopayInline\Api\Entity\PaymentFactory;
use Contributte\GopayInline\Api\Lists\Language;
use Contributte\GopayInline\Client;
use Contributte\GopayInline\Http\Response;
use Eshop\Admin\SettingsPresenter;
use Eshop\CheckoutManager;
use Eshop\DB\Order;
use Eshop\DB\OrderRepository;
use Eshop\DB\PaymentResultRepository;
use Eshop\DB\PaymentTypeRepository;
use Nette\Http\Request;
use Tracy\Debugger;
use Tracy\ILogger;
use Web\DB\SettingRepository;

class GoPay
{
	public Client $client;

	public CheckoutManager $checkoutManager;

	public OrderRepository $orderRepository;

	public SettingRepository $settingRepository;

	public PaymentTypeRepository $paymentTypeRepository;

	public PaymentResultRepository $goPayRepository;

	public Request $request;

	public function __construct(
		Client $client,
		CheckoutManager $checkoutManager,
		OrderRepository $orderRepository,
		SettingRepository $settingRepository,
		PaymentTypeRepository $paymentTypeRepository,
		PaymentResultRepository $goPayRepository,
		Request $request
	) {
		$this->client = $client;
		$this->checkoutManager = $checkoutManager;
		$this->orderRepository = $orderRepository;
		$this->settingRepository = $settingRepository;
		$this->paymentTypeRepository = $paymentTypeRepository;
		$this->goPayRepository = $goPayRepository;
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
			'amount' => \Money\Money::CZK((int)($order->getTotalPriceVat() * 100)),
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
				'amount' => \Money\Money::CZK((int)($item->getPriceVatSum() * 100)),
			];
		}

		return $this->client->payments->createPayment(PaymentFactory::create($payment));
	}

	public function processPayment(): void
	{
		$this->checkoutManager->onOrderCreate[] = function (Order $order): void {
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

				$this->goPayRepository->saveTransaction((string)$data['id'], $order->getTotalPriceVat(), $order->getPayment()->currency->code, $data['state'], 'goPay', $order);

				\header('location: ' . $url);
				exit;
			}

			Debugger::log($response, ILogger::WARNING);
		};
	}
}
