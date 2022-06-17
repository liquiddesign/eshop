<?php
declare(strict_types=1);

namespace Eshop\Front\Eshop;

use Contributte\Comgate\Comgate;
use Eshop\DB\ComgateRepository;
use Eshop\DB\OrderRepository;
use Eshop\Front\FrontendPresenter;
use Nette\Application\BadRequestException;
use Tracy\Debugger;

abstract class ComgatePresenter extends FrontendPresenter
{
	public Comgate $comgate;

	/** @inject */
	public ComgateRepository $comgateRepository;

	/** @inject */
	public \Eshop\Integration\Comgate $CG;

	/** @inject */
	public OrderRepository $orderRepository;

	public function actionPaymentResult(): void
	{
		if ($this->request->getMethod() !== 'POST') {
			throw new BadRequestException('Bad request method');
		}

		$data = $this->request->getPost();

		if (!isset($data)) {
			throw new \Exception('No data from server');
		}

		if ($data['merchant'] !== $this->comgate->getMerchant() || $data['secret'] !== $this->comgate->getSecret()) {
			throw new \Exception('Invalid request');
		}

		Debugger::log($data);

		/** @var \Eshop\DB\Comgate|null $payment */
		$payment = $this->comgateRepository->one(['transactionId' => $data['transId']]);

		if (!$payment) {
			throw new \Exception('Invalid request');
		}

		$payment->update([
			'status' => $data['status'],
		]);

		if ($data['status'] === 'PAID') {
			$payment = $payment->order->getPayment();

			if ($payment) {
				$this->orderRepository->changePayment($payment->getPK(), true, true);
			}
		}

		$this->sendJson(\json_encode([
			'code' => 0,
			'message' => 'OK',
		]));
	}

	public function actionPaymentSummary(string $id): void
	{
		$result = $this->CG->getStatus($id);

		if ($result['merchant'] !== $this->comgate->getMerchant() || $result['secret'] !== $this->comgate->getSecret()) {
			throw new \Exception('Invalid request');
		}

		$payment = $this->comgateRepository->one(['transactionId' => $result['transId']]);

		if (!$payment) {
			throw new \Exception('Invalid request');
		}

		$order = $this->orderRepository->one(['code' => $result['refId']], true);

		$this->template->status = $result['status'];
		$this->template->order = $order;
		$this->template->transId = $result['transId'];

		$this->template->sendOrderToEHub = $this->getSession()->getSection('frontend')->get('sendOrderToEHub');
		$this->getSession()->getSection('frontend')->remove('sendOrderToEHub');
	}
}
