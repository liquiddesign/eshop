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
use Tracy\Debugger;

class Comgate
{
	/** @inject */
	public CheckoutManager $checkoutManager;

	/** @inject */
	public PaymentService $paymentService;

	/** @inject */
	public ComgateRepository $comgateRepository;

	/** @inject */
	public OrderRepository $orderRepository;

	public function processPayment(): void
	{
		$this->checkoutManager->onOrderCreate[] = function (Order $order): void {
			/** @var \Eshop\DB\Order $order */
			$order = $this->orderRepository->one($order->getPK(), true);

			if ($order->getPayment()->type->code === 'CG') {
				$response = $this->createPayment($order);
				Debugger::log($response);
				\bdump($response);

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
