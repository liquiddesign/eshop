<?php

declare(strict_types=1);

namespace Eshop\Admin\Controls;

use Admin\Controls\AdminGridFactory;
use Admin\Helpers;
use Eshop\DB\CustomerGroupRepository;
use Eshop\DB\DeliveryTypeRepository;
use Eshop\DB\Order;
use Eshop\DB\OrderLogItem;
use Eshop\DB\OrderLogItemRepository;
use Eshop\DB\OrderRepository;
use Eshop\DB\PaymentTypeRepository;
use Eshop\Shopper;
use Grid\Datagrid;
use League\Csv\Writer;
use Messages\DB\TemplateRepository;
use Nette\Application\Application;
use Nette\Application\Responses\FileResponse;
use Nette\Forms\Controls\Button;
use Nette\Mail\Mailer;
use Nette\Utils\DateTime;
use Nette\Utils\FileSystem;
use Nette\Utils\Html;
use StORM\Collection;
use StORM\ICollection;
use Tracy\Debugger;
use Tracy\ILogger;

class OrderGridFactory
{
	private OrderRepository $orderRepository;

	private AdminGridFactory $gridFactory;

	private TemplateRepository $templateRepository;

	private OrderLogItemRepository $orderLogItemRepository;

	private Mailer $mailer;

	private Application $application;

	private CustomerGroupRepository $customerGroupRepository;
	
	private DeliveryTypeRepository $deliveryTypeRepository;
	
	private PaymentTypeRepository $paymentTypeRepository;
	
	private Shopper $shopper;
	
	/** @var array<mixed> */
	private array $configuration;
	
	public function __construct(
		AdminGridFactory $adminGridFactory,
		OrderRepository $orderRepository,
		Application $application,
		TemplateRepository $templateRepository,
		Mailer $mailer,
		OrderLogItemRepository $orderLogItemRepository,
		CustomerGroupRepository $customerGroupRepository,
		DeliveryTypeRepository $deliveryTypeRepository,
		PaymentTypeRepository $paymentTypeRepository,
		Shopper $shopper
	) {
		$this->orderRepository = $orderRepository;
		$this->gridFactory = $adminGridFactory;
		$this->templateRepository = $templateRepository;
		$this->mailer = $mailer;
		$this->application = $application;
		$this->orderLogItemRepository = $orderLogItemRepository;
		$this->customerGroupRepository = $customerGroupRepository;
		$this->deliveryTypeRepository = $deliveryTypeRepository;
		$this->paymentTypeRepository = $paymentTypeRepository;
		
		$this->shopper = $shopper;
	}
	
	/**
	 * @param string $state
	 * @param array<mixed> $configuration
	 */
	public function create(string $state, array $configuration = []): Datagrid
	{
		$this->configuration = $configuration;

		$btnSecondary = 'btn btn-sm btn-outline-primary';

		$grid = $this->gridFactory->create(
			$this->orderRepository->getCollectionByState($state)
			->setGroupBy(['this.uuid'])
			->join(['comment' => 'eshop_internalcommentorder'], 'this.uuid = comment.fk_order')
			->select(['commentCount' => 'COUNT(DISTINCT comment.uuid)']),
			20,
			'this.createdTs',
			'DESC',
			true,
		);

		$grid->addColumnSelector();
		$grid->addColumn('Číslo a datum', function (Order $order, $grid) {
			$noteIcon = $order->purchase->note ? '<i class="fas fa-comment-dots ml-2"></i>' : '';

			return \sprintf(
				"<a id='%s' href='%s'>%s$noteIcon</a><br><small>%s</small>",
				$order->getPK(),
				$grid->getPresenter()->link('printDetail', $order),
				$order->code,
				(new DateTime($order->createdTs))->format('d.m.Y G:i'),
			);
		}, '%s', 'this.createdTs', ['class' => 'fit']);

		$grid->addColumn('Zákazník a adresa', [$this, 'renderCustomerColumn']);
		$contacts = '<a href="mailto:%1$s"><i class="far fa-envelope"></i> %1$s</a><br><small><a href="tel:%2$s"><i class="fa fa-phone-alt"></i> %2$s</a></small>';
		$grid->addColumnText('Kontakt', ['purchase.email', 'purchase.phone'], $contacts)->onRenderCell[] = [$grid, 'decoratorEmpty'];
		$grid->addColumnText('Voucher', ['purchase.coupon.label', 'purchase.coupon.code'], '%s<br><small>%s</small>');

		$grid->addColumn('Doprava', [$this, 'renderDeliveryColumn']);
		$grid->addColumn('Platba', [$this, 'renderPaymentColumn']);

		$properties = [];

		if ($this->shopper->getShowWithoutVat() && $this->shopper->getShowVat()) {
			$properties = ['getTotalPrice|price:currency.code', 'getTotalPriceVat|price:currency.code'];

			$grid->addColumnText('Cena', $properties, '%s<br><small>%s s DPH</small>', null, ['class' => 'text-right fit'])->onRenderCell[] = [$grid, 'decoratorNumber'];
		} elseif ($this->shopper->getShowWithoutVat()) {
			$properties[] = 'getTotalPrice|price:currency.code';

			$grid->addColumnText('Cena', $properties, '%s', null, ['class' => 'text-right fit'])->onRenderCell[] = [$grid, 'decoratorNumber'];
		} elseif ($this->shopper->getShowVat()) {
			$properties[] = 'getTotalPriceVat|price:currency.code';

			$grid->addColumnText('Cena', $properties, '%s', null, ['class' => 'text-right fit'])->onRenderCell[] = [$grid, 'decoratorNumber'];
		}
		
		if ($state === 'open') {
			$stateReceived = $configuration['orderStates']['received'] ?? 'Přijaté';
			$actionIco = "<a href='%s' class='$btnSecondary' onclick='return confirm(\"Opravdu?\")' title='Přesunout do stavu " . $stateReceived . "'><i class='fa fa-sm fa-check'></i></a>";
			$grid->addColumnAction('', $actionIco, [$this, 'receiveOrder'], [], null, ['class' => 'minimal']);
			
			$stateFinished = $configuration['orderStates']['finished'] ?? 'Odeslané';
			$actionIco = "<a href='%s' class='$btnSecondary' onclick='return confirm(\"Opravdu?\")' title='Přesunout do stavu " . $stateFinished . "'><i class='fas fa-sm fa-check-double'></i></a>";
			$grid->addColumnAction('', $actionIco, [$this, 'receiveAndCompleteOrder'], [], null, ['class' => 'minimal']);
		}
		
		if ($state !== 'finished' && $state !== 'open') {
			//			$grid->addColumn('Schváleno', [$this, 'renderApprovalColumn'], '%s', null, ['class' => 'minimal']);
			
			$stateFinished = $configuration['orderStates']['finished'] ?? 'Odeslané';
			$actionIco = "<a href='%s' class='$btnSecondary' onclick='return confirm(\"Opravdu?\")' title='Přesunout do stavu " . $stateFinished . "'><i class='fa fa-sm fa-check'></i></a>";
			$grid->addColumnAction('', $actionIco, [$this, 'completeOrder'], [], null, ['class' => 'minimal']);
		}
		
		if ($state !== 'canceled' && $state !== 'open') {
			$stateCanceled = $configuration['orderStates']['canceled'] ?? 'Stornované';
			$actionIco = "<a href='%s' class='$btnSecondary' onclick='return confirm(\"Opravdu?\")' title='Přesunout do stavu " . $stateCanceled . "'><i class='fa fa-sm fa-times'></i></a>";
			$grid->addColumnAction('', $actionIco, [$this, 'cancelOrder'], [], null, ['class' => 'minimal']);
		}

//		$grid->addColumnLink('orderEmail', '<i class="far fa-envelope"></i>', null, ['class' => 'minimal']);

		$downloadIco = "<a href='%s' class='$btnSecondary' title='Stáhnout'><i class='fa fa-sm fa-download'></i></a>";

		if (isset($configuration['exportEdi']) && $configuration['exportEdi']) {
			$grid->addColumnAction('EDI', $downloadIco, [$this, 'downloadEdi'], [], null, ['class' => 'minimal']);
		}

		if (isset($configuration['exportCsv']) && $configuration['exportCsv']) {
			$grid->addColumnAction('CSV', $downloadIco, [$this, 'downloadCsv'], [], null, ['class' => 'minimal']);
		}

		$grid->addColumn('', function ($object, $grid) {
			return '<a class="btn btn-outline-primary btn-sm text-xs" style="white-space: nowrap" href="' .
				$grid->getPresenter()->link('comments', $object) . '"><i title="Komentáře" class="far fa-comment"></i>&nbsp;' . $object->commentCount .
				'</a>';
		});

		$grid->addColumn('', function (Order $order) use ($grid) {
			return $grid->getPresenter()->link('printDetail', $order);
		}, "<a class='$btnSecondary' href='%s'><i class='fa fa-search'></i> Detail</a>", null, ['class' => 'minimal']);

		// filters
		$grid->addFilterTextInput('search_order', ['this.code'], null, 'Č. objednávky');
		$searchExpressions = ['customer.fullname', 'purchase.fullname', 'customer.ic', 'purchase.ic', 'customer.email', 'purchase.email', 'customer.phone', 'purchase.phone',];
		$grid->addFilterTextInput('search_q', $searchExpressions, null, 'Jméno zákazníka, IČO, e-mail, telefon');
		$grid->addFilterButtons(['default']);

		$grid->addFilterDatetime(function (ICollection $source, $value): void {
			$source->where('this.createdTs >= :created_from', ['created_from' => $value]);
		}, '', 'date_from', null)->setHtmlAttribute('class', 'form-control form-control-sm flatpicker')->setHtmlAttribute('placeholder', 'Datum od');

		$grid->addFilterDatetime(function (ICollection $source, $value): void {
			$source->where('this.createdTs <= :created_to', ['created_to' => $value]);
		}, '', 'created_to', null)->setHtmlAttribute('class', 'form-control form-control-sm flatpicker')->setHtmlAttribute('placeholder', 'Datum do');

		if ($customerGroups = $this->customerGroupRepository->getArrayForSelect()) {
			$customerGroups += ['0' => 'X - bez skupiny'];
			$grid->addFilterDataSelect(function (Collection $source, $value): void {
				if ($value === '0') {
					$source->where('purchase.fk_customer IS NULL OR customer.fk_group IS NULL');
				} else {
					$source->where('customer.fk_group', $value);
				}
			}, '', 'customerGroup', null, $customerGroups + [])->setPrompt('- Skupina zákazníků -');
		}
		
		$deliveryTypes = $this->deliveryTypeRepository->getArrayForSelect();
		$grid->addFilterDataSelect(function (Collection $source, $value): void {
			$source->where('purchase.fk_deliveryType', $value);
		}, '', 'deliveryType', null, $deliveryTypes)->setPrompt('- Způsob dopravy -');
		
		$paymentTypes = $this->paymentTypeRepository->getArrayForSelect();
		$grid->addFilterDataSelect(function (Collection $source, $value): void {
			$source->where('purchase.fk_paymentType', $value);
		}, '', 'paymentType', null, $paymentTypes)->setPrompt('- Způsob platby -');

		if ($state === 'open') {
			$submit = $grid->getForm()->addSubmit('receiveMultiple', Html::fromHtml('<i class="fa fa-check"></i> Přijmout'))->setHtmlAttribute('class', $btnSecondary);
			$submit->onClick[] = [$this, 'receiveOrderMultiple'];

			$submit = $grid->getForm()->addSubmit('receiveAndCompleteMultiple', Html::fromHtml('<i class="fas fa-check-double"></i> Přijmout a zpracovat'));
			$submit->setHtmlAttribute('class', $btnSecondary);
			$submit->onClick[] = [$this, 'receiveAndCompleteMultiple'];
		}

		if ($state !== 'finished' && $state !== 'open') {
			$submit = $grid->getForm()->addSubmit('completeMultiple', Html::fromHtml('<i class="fa fa-check"></i> Zpracovat'));
			$submit->setHtmlAttribute('class', $btnSecondary);
			$submit->onClick[] = [$this, 'completeOrderMultiple'];
		}

		if ($state !== 'canceled' && $state !== 'open') {
			$submit = $grid->getForm()->addSubmit('cancelMultiple', Html::fromHtml('<i class="fa fa-times"></i> Stornovat'));
			$submit->setHtmlAttribute('class', $btnSecondary);
			$submit->onClick[] = [$this, 'cancelOrderMultiple'];
		}

		$submit = $grid->getForm()->addSubmit('exportZasilkovna', 'Export pro Zásilkovnu');
		$submit->setHtmlAttribute('class', $btnSecondary)->getControlPrototype()->setName('button')->setHtml('<i class="fa fa-download"></i> Export pro Zásilkovnu');
		$submit->onClick[] = [$this, 'exportZasilkovna'];

		if (isset($configuration['exportPPC']) && $configuration['exportPPC']) {
			$submit = $grid->getForm()->addSubmit('export', 'Exportovat pro PPC (CSV)')->setHtmlAttribute('class', 'btn btn-outline-primary btn-sm');

			$submit->onClick[] = function ($button) use ($grid): void {
				$grid->getPresenter()->redirect('exportPPC', [$grid->getSelectedIds()]);
			};
		}

		if (isset($configuration['targito']) && $configuration['targito']) {
			$submit = $grid->getForm()->addSubmit('export', 'Exportovat pro Targito (CSV)')->setHtmlAttribute('class', 'btn btn-outline-primary btn-sm');

			$submit->onClick[] = function ($button) use ($grid): void {
				$grid->getPresenter()->redirect('exportTargito', [$grid->getSelectedIds()]);
			};
		}

		if (Helpers::isConfigurationActive($configuration, 'eHub')) {
			$grid->addBulkAction('sendEHub', 'EHubSendOrders', 'Odeslat do eHUB');
		}

		return $grid;
	}

	public function renderPaymentColumn(Order $order, Datagrid $grid): string
	{
		$link = $grid->getPresenter()->link('payment', [$order]);

		if (!$payment = $order->getPayment()) {
			return '<a href="' . $link . '" class="btn btn-sm btn-outline-primary"><i class="fa fa-sm fa-plus m-1"></i>Zvolte platbu</a>';
		}

		$linkPay = $grid->getPresenter()->link('changePayment!', ['payment' => (string)$payment, 'paid' => true]);
		$linkPayPlusEmail = $grid->getPresenter()->link('changePayment!', ['payment' => (string)$payment, 'paid' => true, 'email' => true]);
		$linkCancel = $grid->getPresenter()->link('changePayment!', ['payment' => (string)$payment, 'paid' => false]);

		if ($payment->paidTs) {
			$date = $grid->template->getLatte()->invokeFilter('date', [$payment->paidTs]);
			$paymentInfo = "<br><small title='Zaplaceno'><i class='fas fa-check fa-xs' style='color: green;'></i> $date <a href='$linkCancel'><i class='far fa-times-circle'></i></a></small>";
		} else {
			$paymentInfo = "<br><small title='Nezaplaceno'><i class='fas fa-stop fa-xs' style='color: gray'></i> 
<a href='$linkPay'>Zaplatit</a>" . (isset($this->configuration['showExtendedPay']) && $this->configuration['showExtendedPay'] ?
					"| <a href='$linkPayPlusEmail'>Zaplatit + e-mail</a>" : '') . '</small>';
		}

		return "<a href='$link'>" . $payment->getTypeName() . '</a>' . $paymentInfo;
	}

	public function renderDeliveryColumn(Order $order, Datagrid $grid): string
	{
		$link = $grid->getPresenter()->link('delivery', [$order]);

		if (!$delivery = $order->getLastDelivery()) {
			return '<a href="' . $link . '" class="btn btn-sm btn-outline-primary"><i class="fa fa-sm fa-plus m-1"></i>Zvolte dopravu</a>';
		}

		$linkShip = $grid->getPresenter()->link('changeDelivery!', ['delivery' => (string)$delivery, 'shipped' => true, 'email' => false]);
		$linkShipPlusEmail = $grid->getPresenter()->link('changeDelivery!', ['delivery' => (string)$delivery, 'shipped' => true, 'email' => true]);
		$linkCancel = $grid->getPresenter()->link('changeDelivery!', ['delivery' => (string)$delivery, 'shipped' => false]);

		if ($delivery->shippedTs) {
			$from = $order->deliveries->clear(true)->where('shippedTs IS NOT NULL')->enum();
			$to = $order->deliveries->clear(true)->enum();
			$date = $grid->template->getLatte()->invokeFilter('date', [$delivery->shippedTs]);
			$deliveryInfo = "<br><small title='Expedováno'><i class='fas fa-play fa-xs' style='color: gray;'>
</i> $from / $to | $date <a href='$linkCancel'><i class='far fa-times-circle'></i></a></small>";
		} else {
			$deliveryInfo = "<br><small title='Neexpedováno'><i class='fas fa-stop fa-xs' style='color: gray'></i>
 <a href='$linkShip'>Expedovat</a>" . (isset($this->configuration['showExtendedDispatch']) && $this->configuration['showExtendedDispatch'] ?
					"  | <a href='$linkShipPlusEmail'>Expedovat + e-mail</a>" : '') . '</small>';
		}

		$date = $delivery->shippingDate ? '<i style=\'color: gray;\' class=\'fa fa-shipping-fast\'></i> ' . $grid->template->getLatte()->invokeFilter('date', [$delivery->shippingDate]) : '';

		if ($order->purchase->pickupPointId) {
			if ($order->purchase->pickupPoint) {
				return "<a href='$link'>" . $delivery->getTypeName() . '</a> - ' . $order->purchase->pickupPoint->name . " <small> $date</small>" . $deliveryInfo;
			}

			return "<a href='$link'>" . $delivery->getTypeName() . '</a> - ' . $order->purchase->pickupPointName . " <small> $date</small>" . $deliveryInfo;
		}

		if ($order->purchase->zasilkovnaId) {
			return "<a href='$link'>" . $delivery->getTypeName() . '</a> - ' . $order->purchase->zasilkovnaId . " <small> $date</small>" . $deliveryInfo;
		}

		return "<a href='$link'>" . $delivery->getTypeName() . "</a> <small> $date</small>" . $deliveryInfo;
	}

	public function renderApprovalColumn(Order $order, Datagrid $grid): string
	{
		unset($grid);

		$approved = $this->orderRepository->isOrderApproved($order);

		return $approved === true ? 'Ano' : ($approved === false ? 'Ne' : 'Čeká');
	}

	public function renderCustomerColumn(Order $order, Datagrid $grid): ?string
	{
		$address = $order->purchase->deliveryAddress ? $order->purchase->deliveryAddress->getFullAddress() : ($order->purchase->billAddress ? $order->purchase->billAddress->getFullAddress() : '');

		if ($order->purchase->customer) {
			$fullName = $order->purchase->customer->fullname ?: ($order->purchase->fullname ?: '');
			$link = $grid->getPresenter()->link(':Eshop:Admin:Customer:edit', [$order->purchase->customer]);

			return "<a href='$link' style='white-space: nowrap;'>$fullName</a><br><small>$address</small>";
		}

		return $order->purchase->fullname ? "<span style='white-space: nowrap;'>" . $order->purchase->fullname . "</span><br><small>$address</small>" : '';
	}

	public function cancelOrderMultiple(Button $button): void
	{
		/** @var \Grid\Datagrid $grid */
		$grid = $button->lookup(Datagrid::class);

		foreach ($grid->getSelectedIds() as $id) {
			$order = $this->orderRepository->one($id, true);

			/** @var \Eshop\BackendPresenter $presenter */
			$presenter = $grid->getPresenter();

			/** @var \Admin\DB\Administrator|null $admin */
			$admin = $presenter->admin->getIdentity();

			$this->orderRepository->completeOrder($order);

			try {
				$mail = $this->templateRepository->createMessage(
					'order.canceled',
					['orderCode' => $order->code],
					$order->purchase->email,
					null,
					null,
					$order->purchase->getCustomerPrefferedMutation(),
				);

				$this->mailer->send($mail);

				$this->orderLogItemRepository->createLog($order, OrderLogItem::EMAIL_SENT, OrderLogItem::CANCELED, $admin);
			} catch (\Throwable $e) {
			}
		}

		$grid->getPresenter()->flashMessage('Provedeno', 'success');
		$grid->getPresenter()->redirect('this');
	}

	public function exportZasilkovna(Button $button): void
	{
		/** @var \Grid\Datagrid $grid */
		$grid = $button->lookup(Datagrid::class);

		/** @var \Eshop\BackendPresenter $presenter */
		$presenter = $grid->getPresenter();

		$tempFilename = \tempnam($presenter->tempDir, 'csv');
		$this->application->onShutdown[] = function () use ($tempFilename): void {
			try {
				FileSystem::delete($tempFilename);
			} catch (\Throwable $e) {
				Debugger::log($e, ILogger::WARNING);
			}
		};
		$this->orderRepository->csvExportZasilkovna($grid->getSelectedIds(), Writer::createFromPath($tempFilename, 'w+'));
		$response = new FileResponse($tempFilename, 'zasilkovna.csv', 'text/csv');
		$presenter->sendResponse($response);
	}

	public function cancelOrder(Order $order, ?Datagrid $grid = null): void
	{
		/** @var \Eshop\BackendPresenter $presenter */
		$presenter = $grid->getPresenter();

		/** @var \Admin\DB\Administrator|null $admin */
		$admin = $presenter->admin->getIdentity();

		$this->orderRepository->cancelOrder($order, $admin);

		$this->orderLogItemRepository->createLog($order, OrderLogItem::CANCELED, null, $admin);

		try {
			$mail = $this->templateRepository->createMessage(
				'order.canceled',
				['orderCode' => $order->code],
				$order->purchase->email,
				null,
				null,
				$order->purchase->getCustomerPrefferedMutation(),
			);

			$this->mailer->send($mail);

			$this->orderLogItemRepository->createLog($order, OrderLogItem::EMAIL_SENT, OrderLogItem::CANCELED, $admin);
		} catch (\Throwable $e) {
		}

		$grid->getPresenter()->flashMessage('Provedeno', 'success');
		$grid->getPresenter()->redirect('this');
	}

	public function completeOrderMultiple(Button $button): void
	{
		/** @var \Grid\Datagrid $grid */
		$grid = $button->lookup(Datagrid::class);

		foreach ($grid->getSelectedIds() as $id) {
			$this->completeOrder($grid->getSource()->where('this.uuid', $id)->first(), $grid, false);
		}

		$grid->getPresenter()->flashMessage('Provedeno', 'success');
		$grid->getPresenter()->redirect('this');
	}

	public function completeOrder(Order $object, ?Datagrid $grid = null, bool $redirectAfter = true): void
	{
		/** @var \Eshop\BackendPresenter $presenter */
		$presenter = $grid->getPresenter();

		/** @var \Admin\DB\Administrator|null $admin */
		$admin = $presenter->admin->getIdentity();

		$this->orderRepository->completeOrder($object, $admin);

		try {
			$mail = $this->templateRepository->createMessage('order.confirmed', [
				'orderCode' => $object->code,
			], $object->purchase->email, null, null, $object->purchase->getCustomerPrefferedMutation());

			$this->mailer->send($mail);

			$this->orderLogItemRepository->createLog($object, OrderLogItem::EMAIL_SENT, OrderLogItem::COMPLETED, $admin);
		} catch (\Throwable $e) {
		}

		if (!$redirectAfter) {
			return;
		}

		$grid->getPresenter()->flashMessage('Provedeno', 'success');
		$grid->getPresenter()->redirect('this');
	}

	public function receiveOrderMultiple(Button $button): void
	{
		/** @var \Grid\Datagrid $grid */
		$grid = $button->lookup(Datagrid::class);

		foreach ($grid->getSelectedIds() as $id) {
			$this->receiveOrder($grid->getSource()->where('this.uuid', $id)->first(), $grid, false);
		}

		$grid->getPresenter()->flashMessage('Provedeno', 'success');
		$grid->getPresenter()->redirect('this');
	}

	public function receiveOrder(Order $object, ?Datagrid $grid = null, bool $redirectAfter = true): void
	{
		/** @var \Eshop\BackendPresenter $presenter */
		$presenter = $grid->getPresenter();

		/** @var \Admin\DB\Administrator|null $admin */
		$admin = $presenter->admin->getIdentity();

		$this->orderRepository->receiveOrder($object, $admin);
		
		try {
			$mail = $this->templateRepository->createMessage('order.received', [
				'orderCode' => $object->code,
			], $object->purchase->email, null, null, $object->purchase->getCustomerPrefferedMutation());
			
			$this->mailer->send($mail);
			
			$this->orderLogItemRepository->createLog($object, OrderLogItem::EMAIL_SENT, OrderLogItem::RECEIVED, $admin);
		} catch (\Throwable $e) {
		}

		if (!$redirectAfter) {
			return;
		}

		$grid->getPresenter()->flashMessage('Provedeno', 'success');
		$grid->getPresenter()->redirect('this');
	}

	public function receiveAndCompleteMultiple(Button $button): void
	{
		/** @var \Grid\Datagrid $grid */
		$grid = $button->lookup(Datagrid::class);

		foreach ($grid->getSelectedIds() as $id) {
			$this->receiveAndCompleteOrder($grid->getSource()->where('this.uuid', $id)->first(), $grid, false);
		}

		$grid->getPresenter()->flashMessage('Provedeno', 'success');
		$grid->getPresenter()->redirect('this');
	}

	public function receiveAndCompleteOrder(Order $object, ?Datagrid $grid = null, bool $redirectAfter = true): void
	{
		/** @var \Eshop\BackendPresenter $presenter */
		$presenter = $grid->getPresenter();

		/** @var \Admin\DB\Administrator|null $admin */
		$admin = $presenter->admin->getIdentity();

		$this->orderRepository->receiveAndCompleteOrder($object, $admin);

		try {
			$mail = $this->templateRepository->createMessage('order.confirmed', [
				'orderCode' => $object->code,
			], $object->purchase->email, null, null, $object->purchase->getCustomerPrefferedMutation());

			$this->mailer->send($mail);

			$this->orderLogItemRepository->createLog($object, OrderLogItem::EMAIL_SENT, OrderLogItem::COMPLETED, $admin);
		} catch (\Throwable $e) {
		}

		if (!$redirectAfter) {
			return;
		}

		$grid->getPresenter()->flashMessage('Provedeno', 'success');
		$grid->getPresenter()->redirect('this');
	}

	/**
	 * Can be called only from \Eshop\Admin\OrderPresenter|\Eshop\Admin\ExportPresenter
	 * @param \Eshop\DB\Order $object
	 * @param \Grid\Datagrid $grid
	 */
	public function downloadEdi(Order $object, Datagrid $grid): void
	{
		/** @var \Eshop\Admin\OrderPresenter|\Eshop\Admin\ExportPresenter $presenter */
		$presenter = $grid->getPresenter();
		$presenter->handleExportEdi($object->getPK());
	}

	/**
	 * Can be called only from \Eshop\Admin\OrderPresenter
	 * @param \Eshop\DB\Order $object
	 * @param \Grid\Datagrid $grid
	 */
	public function downloadCsv(Order $object, Datagrid $grid): void
	{
		/** @var \Eshop\Admin\OrderPresenter $presenter */
		$presenter = $grid->getPresenter();
		$presenter->handleExportCsv($object->getPK());
	}
}
