<?php
declare(strict_types=1);

namespace Eshop\Front\Eshop;

use Contributte\Comgate\Comgate;
use Eshop\DB\ComgateRepository;
use Eshop\DB\OrderRepository;
use Eshop\Front\FrontendPresenter;
use Eshop\Integration\Integrations;
use Nette\Application\BadRequestException;
use Tracy\Debugger;

abstract class ComgatePresenter extends FrontendPresenter
{
	public Comgate $comgate;

	/** @inject */
	public ComgateRepository $comgateRepository;

	/** @inject */
	public OrderRepository $orderRepository;

	/** @inject */
	public Integrations $integrations;

	private \Eshop\Services\Comgate $comgateService;

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
			throw new \Exception('Invalid request: Invalid data');
		}

		Debugger::log($data);

		/** @var \Eshop\DB\Comgate|null $payment */
		$payment = $this->comgateRepository->one(['transactionId' => $data['transId']]);

		if (!$payment) {
			throw new \Exception('Invalid request: Transaction not found');
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
		$result = $this->comgateService->getStatus($id);

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

	protected function startup(): void
	{
		parent::startup();

		/** @var \Eshop\Services\Comgate|null $comgateService */
		$comgateService = $this->integrations->getService(Integrations::COMGATE);

		if (!$comgateService) {
			throw new \Exception('Comgate service not found! Did you register it from "\Eshop\Services"?');
		}

		$this->comgateService = $comgateService;
	}
}
