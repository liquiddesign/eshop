<?php

declare(strict_types=1);

namespace Eshop\Front\Eshop;

use Contributte\Comgate\Comgate;
use Eshop\DB\OrderRepository;
use Eshop\Front\FrontendPresenter;
use Eshop\Integration\Integrations;
use Nette\DI\Attributes\Inject;
use Nette\Http\Request;

abstract class ComgatePresenter extends FrontendPresenter
{
	public Comgate $comgate;

	#[Inject]
	public OrderRepository $orderRepository;

	#[Inject]
	public Integrations $integrations;

	#[Inject]
	public Request $request;

	#[Inject]
	public \Eshop\Services\Comgate $comgateService;

	public function actionPaymentResult(): void
	{
		$json = $this->comgateService->processPaymentResult($this->request);

		$this->sendJson($json);
	}

	public function actionPaymentSummary(string $id): void
	{
		$templateData = $this->comgateService->processPaymentSummary($id);

		/** @var \Eshop\DB\Order $order */
		$order = $templateData['order'];

		$this->template->status = $templateData['status'];
		$this->template->order = $order;
		$this->template->paymentResultId = $templateData['paymentResultId'];
		$this->template->url = $templateData['url'];
		$this->template->customer = $order->purchase->customer;
		$this->template->merchant = $order->purchase->merchant;
		$this->template->zbozi = $this->integrations->getService(Integrations::ZBOZI);
	}
}
