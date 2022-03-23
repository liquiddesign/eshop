<?php

namespace Eshop\Integration;

use Brick\Money\Money;
use Contributte\Comgate\Entity\Codes\PaymentMethodCode;
use Contributte\Comgate\Entity\Payment;
use Contributte\Comgate\Entity\PaymentStatus;
use Contributte\Comgate\Gateway\PaymentService;
use Eshop\CheckoutManager;
use Eshop\DB\ComgateRepository;
use Eshop\DB\Order;
use Eshop\DB\OrderRepository;
use Eshop\DB\PaymentTypeRepository;
use Web\DB\SettingRepository;

class Comgate
{
	public CheckoutManager $checkoutManager;

	public PaymentService $paymentService;

	public ComgateRepository $comgateRepository;

	public OrderRepository $orderRepository;

	public SettingRepository $settingRepository;

	public PaymentTypeRepository $paymentTypeRepository;

	public function __construct(
		CheckoutManager $checkoutManager,
		ComgateRepository $comgateRepository,
		OrderRepository $orderRepository,
		PaymentService $paymentService,
		SettingRepository $settingRepository,
		PaymentTypeRepository $paymentTypeRepository
	) {
		$this->checkoutManager = $checkoutManager;
		$this->comgateRepository = $comgateRepository;
		$this->orderRepository = $orderRepository;
		$this->paymentService = $paymentService;
		$this->settingRepository = $settingRepository;
		$this->paymentTypeRepository = $paymentTypeRepository;
	}

	public function processPayment(): void
	{
		$this->checkoutManager->onOrderCreate[] = function (Order $order): void {
			/** @var \Eshop\DB\Order $order */
			$order = $this->orderRepository->one($order->getPK(), true);

			$paymentType = $this->settingRepository->getValueByName('comgatePaymentType');

			if (!$paymentType) {
				return;
			}

			$paymentType = $this->paymentTypeRepository->one($paymentType);

			if (!$paymentType || $order->getPayment()->type->code !== $paymentType->code) {
				return;
			}

			$response = $this->createPayment($order);

			if ($response['code'] === '0') {
				$this->comgateRepository->saveTransaction($response['transId'], $order->getTotalPriceVat(), $order->getPayment()->currency->code, 'PENDING', $order);
				\header('location: ' . $response['redirect']);
				exit;
			}
		};
	}

	/**
	 * @param \Eshop\DB\Order $order
	 * @return string[]
	 * @throws \Brick\Money\Exception\UnknownCurrencyException
	 */
	public function createPayment(Order $order): array
	{
		$price = $order->getTotalPriceVat();
		$currency = $order->getPayment()->currency->code;
		$customer = $order->purchase->email;
		$payment = Payment::of(
			Money::of($price, $currency),
			$order->code,
			$order->code,
			$customer,
			PaymentMethodCode::ALL,
		);

		$res = $this->paymentService->create($payment);

		// $res->isOk();
		return $res->getData();
	}

	/**
	 * @param string $transaction
	 * @return string[]
	 */
	public function getStatus(string $transaction): array
	{
		$res = $this->paymentService->status(PaymentStatus::of($transaction));

		return $res->getData();
	}
}
