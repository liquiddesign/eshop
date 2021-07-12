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
use Nette\Utils\DateTime;
use Tracy\Debugger;

class Comgate
{
	public CheckoutManager $checkoutManager;

	public PaymentService $paymentService;

	public ComgateRepository $comgateRepository;

	public OrderRepository $orderRepository;

	public function __construct(CheckoutManager $checkoutManager, ComgateRepository $comgateRepository, OrderRepository $orderRepository, PaymentService $paymentService)
	{
		$this->checkoutManager = $checkoutManager;
		$this->comgateRepository = $comgateRepository;
		$this->orderRepository = $orderRepository;
		$this->paymentService = $paymentService;
	}

	public function processPayment(): void
	{
		$this->checkoutManager->onOrderCreate[] = function (Order $order): void {
			/** @var \Eshop\DB\Order $order */
			$order = $this->orderRepository->one($order->getPK(), true);

			if ($order->getPayment()->type->code === 'CG') {
				$response = $this->createPayment($order);
				$order->receivedTs = new DateTime();
				$order->update(['receivedTs' => new DateTime()]);

				if ($response['code'] === '0') {
					$this->comgateRepository->saveTransaction($response['transId'], $order->getTotalPriceVat(), $order->getPayment()->currency->code, 'PENDING', $order);
					\header('location: ' . $response['redirect']);
					exit;
				}
			}
		};
	}

	public function createPayment(Order $order): array
	{
		$price = $order->getTotalPriceVat();
		$currency = $order->getPayment()->currency->code;
		$customer = $order->purchase->email;
		$payment = Payment::of(
			Money::of($price ?? 50, $currency ?? 'CZK'),
			$order->code,
			$order->code,
			$customer,
			PaymentMethodCode::ALL
		);

		$res = $this->paymentService->create($payment);

		// $res->isOk();
		return $res->getData();
	}

	public function getStatus(string $transaction): array
	{
		$res = $this->paymentService->status(PaymentStatus::of($transaction));

		return $res->getData();
	}
}
