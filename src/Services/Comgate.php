<?php

declare(strict_types=1);

namespace Eshop\Services;

use Brick\Math\RoundingMode;
use Brick\Money\Money;
use Contributte\Comgate\Entity\Codes\PaymentMethodCode;
use Contributte\Comgate\Entity\Payment;
use Contributte\Comgate\Entity\PaymentStatus;
use Contributte\Comgate\Gateway\PaymentService;
use Eshop\Admin\SettingsPresenter;
use Eshop\Common\IPaymentIntegration;
use Eshop\DB\Order;
use Eshop\DB\OrderRepository;
use Eshop\DB\PaymentResult;
use Eshop\DB\PaymentResultRepository;
use Eshop\DB\PaymentTypeRepository;
use Nette\Application\BadRequestException;
use Nette\DI\Container;
use Nette\Http\Request;
use Tracy\Debugger;
use Tracy\ILogger;
use Web\DB\SettingRepository;

class Comgate implements IPaymentIntegration
{
	/**
	 * @var (callable(\Eshop\DB\Order $order): float)|null
	 */
	public $onGetOrderTotalPriceVat = null;

	private \Contributte\Comgate\Comgate $contributteComgate;

	public function __construct(
		public PaymentResultRepository $paymentResultRepository,
		public OrderRepository $orderRepository,
		public PaymentService $paymentService,
		public SettingRepository $settingRepository,
		public PaymentTypeRepository $paymentTypeRepository,
		Container $container
	) {
		/** @var \Contributte\Comgate\Comgate $contributteComgate */
		$contributteComgate = $container->getByName('comgate.comgate');

		$this->contributteComgate = $contributteComgate;
	}

	public function processPayment(Order $order): void
	{
		/** @var \Eshop\DB\Order $order */
		$order = $this->orderRepository->one($order->getPK(), true);

		$comgatePaymentTypes = $this->settingRepository->getValuesByName(SettingsPresenter::COMGATE_PAYMENT_TYPE);

		if (!$comgatePaymentTypes) {
			return;
		}

		$comgatePaymentTypes = $this->paymentTypeRepository->many()->where('this.uuid', $comgatePaymentTypes)->toArray();

		$orderPaymentType = $order->getPayment()?->type;

		if (!$orderPaymentType) {
			return;
		}

		if (!isset($comgatePaymentTypes[$orderPaymentType->getPK()])) {
			return;
		}

		$response = $this->createPayment($order, $orderPaymentType->comgateMethod ?: PaymentMethodCode::ALL);

		if ($response['code'] === '0') {
			$this->paymentResultRepository->saveTransaction(
				$response['transId'],
				$this->getOrderTotalPriceVat($order),
				$order->getPayment()?->currency->code ?: 'CZK',
				'PENDING',
				'comgate',
				$order,
				$this->contributteComgate->isTest(),
			);

			\header('location: ' . $response['redirect']);
			exit;
		}

		Debugger::log($response, ILogger::WARNING);
	}

	/**
	 * @param \Eshop\DB\Order $order
	 * @return array<string>
	 * @throws \Brick\Money\Exception\UnknownCurrencyException
	 */
	public function createPayment(Order $order, string $method = PaymentMethodCode::ALL): array
	{
		$price = $this->getOrderTotalPriceVat($order);
		$currency = $order->getPayment()?->currency->code;

		if (!$currency) {
			throw new \Exception("Comgate: Order '$order->code' has no payment.");
		}

		$customerEmail = (string) $order->purchase->email;
		$customerFullName = (string) $order->purchase->fullname;

		$payment = Payment::of(
			Money::of($price, $currency, new \Brick\Money\Context\CustomContext(2), RoundingMode::HALF_EVEN),
			$order->code,
			$order->code,
			$customerEmail,
			$customerFullName,
			$method,
		);

		$res = $this->paymentService->create($payment);

		// $res->isOk();
		return $res->getData();
	}

	/**
	 * @param string $transaction
	 * @return array<string>
	 */
	public function getStatus(string $transaction): array
	{
		$res = $this->paymentService->status(PaymentStatus::of($transaction));

		return $res->getData();
	}

	/**
	 * @param \Eshop\DB\PaymentResult $paymentResult
	 * @param array<mixed> $result
	 */
	public function getUrl(PaymentResult $paymentResult, array $result): string
	{
		unset($result);

		return "https://payments.comgate.cz/client/instructions?id=$paymentResult->id";
	}

	/**
	 * @inheritDoc
	 */
	public function processPaymentSummary(string $id): array
	{
		$result = $this->getStatus($id);

		if ($result['merchant'] !== $this->contributteComgate->getMerchant() || $result['secret'] !== $this->contributteComgate->getSecret()) {
			throw new \Exception('Invalid request');
		}

		$paymentResult = $this->paymentResultRepository->one(['id' => $result['transId']]);

		if (!$paymentResult) {
			throw new \Exception('Invalid request');
		}

		$order = $this->orderRepository->one(['code' => $result['refId']], true);

		return [
			'status' => $result['status'],
			'order' => $order,
			'paymentResultId' => $result['transId'],
			'url' => $this->getUrl($paymentResult, $result),
			'customer' => $order->purchase->customer,
			'merchant' => $order->purchase->merchant,
		];
	}

	/**
	 * @inheritDoc
	 */
	public function processPaymentResult(Request $request): array
	{
		if ($request->getMethod() !== 'POST') {
			throw new BadRequestException('Bad request method');
		}

		$data = $request->getPost();

		if ($data['merchant'] !== $this->contributteComgate->getMerchant() || $data['secret'] !== $this->contributteComgate->getSecret()) {
			Debugger::log($data, ILogger::DEBUG);

			throw new \Exception('Invalid request');
		}

		/** @var \Eshop\DB\PaymentResult|null $paymentResult */
		$paymentResult = $this->paymentResultRepository->one(['id' => $data['transId']]);

		if (!$paymentResult) {
			Debugger::log($data, ILogger::WARNING);

			throw new \Exception("Payment with id '{$data['transId']}' not found");
		}

		$paymentResult->update([
			'status' => $data['status'],
		]);

		if ($data['status'] === 'PAID') {
			$paymentResult = $paymentResult->order?->getPayment();

			if ($paymentResult) {
				$this->orderRepository->changePayment((string) $paymentResult->getPK(), true, true);
			}
		}

		return [
			'code' => 0,
			'message' => 'OK',
		];
	}

	protected function getOrderTotalPriceVat(Order $order): float
	{
		if ($this->onGetOrderTotalPriceVat) {
			try {
				return \call_user_func($this->onGetOrderTotalPriceVat, $order);
			} catch (\Exception $e) {
				Debugger::log($e, ILogger::EXCEPTION);

				return $order->getTotalPriceVat();
			}
		}

		return $order->getTotalPriceVat();
	}
}
