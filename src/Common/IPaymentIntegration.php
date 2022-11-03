<?php

namespace Eshop\Common;

use Eshop\DB\Order;
use Eshop\DB\PaymentResult;
use Nette\Http\Request;

interface IPaymentIntegration
{
	/**
	 * Process if order is using this payment service and redirect to service
	 */
	public function processPayment(Order $order): void;

	/**
	 * Get URL to continue payment in service
	 */
	public function getUrl(PaymentResult $paymentResult, array $result): string;

	/**
	 * Show payments results to customer after returning from service
	 * @param string $id
	 * @return array{status: string, order: \Eshop\DB\Order, paymentResultId: string, url: string, customer: \Eshop\DB\Customer|null, merchant: \Eshop\DB\Merchant|null}
	 */
	public function processPaymentSummary(string $id): array;

	/**
	 * Save payment result from service
	 * @return array<mixed>
	 */
	public function processPaymentResult(Request $request): array;
}
