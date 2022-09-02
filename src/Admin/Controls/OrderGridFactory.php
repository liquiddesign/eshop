<?php

declare(strict_types=1);

namespace Eshop\Admin\Controls;

use Admin\Controls\AdminGrid;
use Admin\Controls\AdminGridFactory;
use Admin\Helpers;
use Eshop\DB\CustomerGroupRepository;
use Eshop\DB\DeliveryTypeRepository;
use Eshop\DB\Order;
use Eshop\DB\OrderLogItem;
use Eshop\DB\OrderLogItemRepository;
use Eshop\DB\OrderRepository;
use Eshop\DB\PaymentTypeRepository;
use Eshop\Integration\Integrations;
use Eshop\Services\DPD;
use Eshop\Services\PPL;
use Eshop\Shopper;
use Grid\Datagrid;
use League\Csv\Writer;
use Nette\Application\Application;
use Nette\Application\Responses\FileResponse;
use Nette\Application\UI\Presenter;
use Nette\DI\Container;
use Nette\Forms\Controls\Button;
use Nette\IOException;
use Nette\Utils\Arrays;
use Nette\Utils\DateTime;
use Nette\Utils\FileSystem;
use Nette\Utils\Html;
use StORM\Collection;
use StORM\ICollection;
use Tracy\Debugger;
use Tracy\ILogger;

class OrderGridFactory
{
	/** @var array<callable(\StORM\Collection): void> */
	public array $onCollectionCreation = [];

	/** @var array<callable(\Admin\Controls\AdminGrid, array<string>): void> */
	public array $onBulkActionsCreated = [];

	private OrderRepository $orderRepository;

	private AdminGridFactory $gridFactory;

	private OrderLogItemRepository $orderLogItemRepository;

	private Application $application;

	private CustomerGroupRepository $customerGroupRepository;
	
	private DeliveryTypeRepository $deliveryTypeRepository;
	
	private PaymentTypeRepository $paymentTypeRepository;

	private Integrations $integrations;
	
	private Shopper $shopper;

	private ?DPD $dpd = null;

	private ?PPL $ppl = null;

	private Container $container;
	
	/** @var array<mixed> */
	private array $configuration;
	
	public function __construct(
		AdminGridFactory $adminGridFactory,
		OrderRepository $orderRepository,
		Application $application,
		OrderLogItemRepository $orderLogItemRepository,
		CustomerGroupRepository $customerGroupRepository,
		DeliveryTypeRepository $deliveryTypeRepository,
		PaymentTypeRepository $paymentTypeRepository,
		Shopper $shopper,
		Integrations $integrations,
		Container $container
	) {
		$this->orderRepository = $orderRepository;
		$this->gridFactory = $adminGridFactory;
		$this->application = $application;
		$this->orderLogItemRepository = $orderLogItemRepository;
		$this->customerGroupRepository = $customerGroupRepository;
		$this->deliveryTypeRepository = $deliveryTypeRepository;
		$this->paymentTypeRepository = $paymentTypeRepository;
		$this->integrations = $integrations;
		$this->shopper = $shopper;
		$this->container = $container;
	}

	/**
	 * @param string $state
	 * @param array<mixed> $configuration
	 * @param array<mixed> $orderStatesNames
	 * @param array<mixed> $orderStatesEvents
	 */
	public function create(string $state, array $configuration = [], array $orderStatesNames = [], array $orderStatesEvents = []): Datagrid
	{
		$this->configuration = $configuration;
		$this->dpd = $this->integrations->getService('dpd');
		$this->ppl = $this->integrations->getService('ppl');

		$stateOpen = $configuration['orderStates'][Order::STATE_OPEN] ?? $orderStatesNames[Order::STATE_OPEN] ?? 'Otevřít';
		$stateReceived = $configuration['orderStates'][Order::STATE_RECEIVED] ?? $orderStatesNames[Order::STATE_RECEIVED] ?? 'Přijmout';
		$stateFinished = $configuration['orderStates'][Order::STATE_COMPLETED] ?? $orderStatesNames[Order::STATE_COMPLETED] ?? 'Zpracovat';
		$stateCanceled = $configuration['orderStates'][Order::STATE_CANCELED] ?? $orderStatesNames[Order::STATE_CANCELED] ?? 'Stornovat';

		$btnSecondary = 'btn btn-sm btn-outline-primary';

		$collection = $this->orderRepository->getCollectionByState($state)
			->setGroupBy(['this.uuid'])
			->join(['comment' => 'eshop_internalcommentorder'], 'this.uuid = comment.fk_order')
			->join(['payment' => 'eshop_payment'], 'this.uuid = payment.fk_order')
			->select(['commentCount' => 'COUNT(DISTINCT comment.uuid)']);

		Arrays::invoke($this->onCollectionCreation, $collection);

		$grid = $this->gridFactory->create(
			$collection,
			20,
			'this.createdTs',
			'DESC',
			true,
		);

		$grid->addColumnSelector();
		$grid->addColumn('Číslo a datum', function (Order $order, $grid) {
			$color = 'color: ' . ($this->configuration['noteIconColor'] ?? null);
			$noteIcon = $order->purchase->note ? "<i style='$color;' class='fas fa-comment-dots ml-2'></i>" : '';

			return \sprintf(
				"<a id='%s' href='%s'>%s$noteIcon</a><br><small>%s</small>",
				$order->getPK(),
				$grid->getPresenter()->link('printDetail', $order),
				$order->code,
				(new DateTime($order->createdTs))->format('d.m.Y G:i'),
			);
		}, '%s', 'this.createdTs', ['class' => 'fit'])->onRenderCell[] = [$grid, 'decoratorNowrap'];

		$grid->addColumn('Zákazník a adresa', [$this, 'renderCustomerColumn']);
		$contacts = '<a href="mailto:%1$s"><i class="far fa-envelope"></i> %1$s</a><br><small><a href="tel:%2$s"><i class="fa fa-phone-alt"></i> %2$s</a></small>';
		$grid->addColumnText('Kontakt', ['purchase.email', 'purchase.phone'], $contacts)->onRenderCell[] = [$grid, 'decoratorEmpty'];
		$grid->addColumnText('Voucher', ['purchase.coupon.label', 'purchase.coupon.code'], '%s<br><small>%s</small>');

		$grid->addColumn('Doprava', [$this, 'renderDeliveryColumn']);
		$grid->addColumn('Platba', [$this, 'renderPaymentColumn']);

		$properties = [];

		if ($this->shopper->getShowWithoutVat() && $this->shopper->getShowVat()) {
			$properties = ['getTotalPrice|price:currency.code', 'getTotalPriceVat|price:currency.code'];

			$smallVatText = 's DPH';

			if ($this->shopper->getPriorityPrice() === 'withVat') {
				$properties = \array_reverse($properties);
				$smallVatText = 'bez DPH';
			}

			$grid->addColumnText('Cena', $properties, "%s<br><small>%s $smallVatText</small>", null, ['class' => 'text-right fit'])->onRenderCell[] = [$grid, 'decoratorNumber'];
		} elseif ($this->shopper->getShowWithoutVat()) {
			$properties[] = 'getTotalPrice|price:currency.code';

			$grid->addColumnText('Cena', $properties, '%s', null, ['class' => 'text-right fit'])->onRenderCell[] = [$grid, 'decoratorNumber'];
		} elseif ($this->shopper->getShowVat()) {
			$properties[] = 'getTotalPriceVat|price:currency.code';

			$grid->addColumnText('Cena', $properties, '%s', null, ['class' => 'text-right fit'])->onRenderCell[] = [$grid, 'decoratorNumber'];
		}

		$openOrderButton = function () use ($grid, $stateOpen, $btnSecondary): void {
			try {
				$actionIco = "<a href='%s' class='$btnSecondary' onclick='return confirm(\"Opravdu?\")' title='" . $stateOpen . "'><i class='fa fa-sm fa-angle-double-left'></i></a>";
				$grid->addColumnAction('', $actionIco, [$this, 'openOrder'], [], null, ['class' => 'minimal']);
			} catch (\Throwable $e) {
				Debugger::log($e, ILogger::ERROR);
			}
		};

		$receiveOrderButton = function () use ($grid, $stateReceived, $btnSecondary): void {
			try {
				$actionIco = "<a href='%s' class='$btnSecondary' onclick='return confirm(\"Opravdu?\")' title='" . $stateReceived . "'><i class='fa fa-sm fa-check'></i></a>";
				$grid->addColumnAction('', $actionIco, [$this, 'receiveOrder'], [], null, ['class' => 'minimal']);
			} catch (\Throwable $e) {
				Debugger::log($e, ILogger::ERROR);
			}
		};

		$receiveAndCompleteOrderButton = function () use ($grid, $stateFinished, $btnSecondary): void {
			try {
				$actionIco = "<a href='%s' class='$btnSecondary' onclick='return confirm(\"Opravdu?\")' title='" . $stateFinished . "'><i class='fas fa-sm fa-check-double'></i></a>";
				$grid->addColumnAction('', $actionIco, [$this, 'receiveAndCompleteOrder'], [], null, ['class' => 'minimal']);
			} catch (\Throwable $e) {
				Debugger::log($e, ILogger::ERROR);
			}
		};

		$cancelOrderButton = function () use ($grid, $stateCanceled, $btnSecondary): void {
			try {
				$actionIco = "<a href='%s' class='$btnSecondary' onclick='return confirm(\"Opravdu?\")' title='" . $stateCanceled . "'><i class='fa fa-sm fa-times'></i></a>";
				$grid->addColumnAction('', $actionIco, [$this, 'cancelOrder'], [], null, ['class' => 'minimal']);
			} catch (\Throwable $e) {
				Debugger::log($e, ILogger::ERROR);
			}
		};

		$completeOrderButton = function () use ($grid, $stateFinished, $btnSecondary): void {
			try {
				$actionIco = "<a href='%s' class='$btnSecondary' onclick='return confirm(\"Opravdu?\")' title='" . $stateFinished . "'><i class='fa fa-sm fa-check'></i></a>";
				$grid->addColumnAction('', $actionIco, [$this, 'completeOrder'], [], null, ['class' => 'minimal']);
			} catch (\Throwable $e) {
				Debugger::log($e, ILogger::ERROR);
			}
		};

		$buttonsByTargetStates = [
			Order::STATE_OPEN => [
				Order::STATE_RECEIVED => $receiveOrderButton,
				Order::STATE_COMPLETED => $receiveAndCompleteOrderButton,
				Order::STATE_CANCELED => $cancelOrderButton,
			],
			Order::STATE_RECEIVED => [
				Order::STATE_OPEN => $openOrderButton,
				Order::STATE_COMPLETED => $completeOrderButton,
				Order::STATE_CANCELED => $cancelOrderButton,
			],
			Order::STATE_COMPLETED => [
				Order::STATE_OPEN => $openOrderButton,
				Order::STATE_RECEIVED => $receiveOrderButton,
				Order::STATE_CANCELED => $cancelOrderButton,
			],
			Order::STATE_CANCELED => [
				Order::STATE_OPEN => $openOrderButton,
				Order::STATE_RECEIVED => $receiveOrderButton,
				Order::STATE_COMPLETED => $completeOrderButton,
			],
		];

		foreach ($buttonsByTargetStates[$state] ?? [] as $targetState => $button) {
			if (!isset($orderStatesEvents[$state]) || !Arrays::contains($orderStatesEvents[$state], $targetState) ||
				($state === Order::STATE_OPEN && !$this->shopper->getEditOrderAfterCreation())) {
				continue;
			}

			$button();
		}

		if (isset($configuration['pauseOrder']) && $configuration['pauseOrder']) {
			$pauseButton = "<a href='%s' class='$btnSecondary' title='Pozastavit'><i class='fa fa-sm fa-pause'></i></a>";
			$unPauseButton = "<a href='%s' class='$btnSecondary' title='Zrušit pozastavení'><i class='fa fa-sm fa-play'></i></a>";

			$grid->addColumn('', function (Order $order, AdminGrid $datagrid) use ($pauseButton, $unPauseButton) {
				$pauseLink = $datagrid->getPresenter()->link('pauseOrder!', [$order->getPK()]);
				$unPauseLink = $datagrid->getPresenter()->link('unPauseOrder!', [$order->getPK()]);

				return \sprintf($order->pausedTs ? $unPauseButton : $pauseButton, $order->pausedTs ? $unPauseLink : $pauseLink);
			}, '%s', null, ['class' => 'minimal']);

			$grid->onRenderRow[] = function (\Nette\Utils\Html $row, $object): void {
				/** @var \Eshop\DB\Order $object */
				if ($object->pausedTs) {
					$row->appendAttribute('style', 'background-color: #f7d5d5 !important;');
				}
			};
		}

		$downloadIco = "<a href='%s' class='$btnSecondary' title='Stáhnout'><i class='fa fa-sm fa-download'></i></a>";

		if (isset($configuration['exportEdi']) && $configuration['exportEdi'] && $state !== Order::STATE_OPEN) {
			$grid->addColumnAction('EDI', $downloadIco, [$this, 'downloadEdi'], [], null, ['class' => 'minimal']);
		}

		if (isset($configuration['exportCsv']) && $configuration['exportCsv']) {
			$grid->addColumnAction('CSV', $downloadIco, [$this, 'downloadCsv'], [], null, ['class' => 'minimal']);
		}

		if ($this->dpd && $state !== Order::STATE_OPEN) {
			$tempDir = $this->container->getParameters()['tempDir'] . '/dpd/';

			$grid->addColumn('DPD', function (Order $order, AdminGrid $datagrid) use ($tempDir) {
				try {
					$title = $order->dpdError ? FileSystem::read($tempDir . $order->code) : $order->getDpdCode();
				} catch (IOException $e) {
					$title = '';
				}

				return '<button type="button" role="button" title="' . $title . '" class="btn btn-sm btn-outline-' .
					($order->getDpdCode() ? 'success' : ($order->dpdError ? 'danger' : 'primary')) . '" data-toggle="tooltip" data-placement="bottom">
				<i class="fas fa-' . ($order->getDpdCode() ? ($order->dpdPrinted ? 'print' : 'check') : ($order->dpdError ? 'exclamation' : 'times')) . '"></i>
				</button>';
			}, '%s', 'this.dpdCode', ['class' => 'fit']);
		}

		if ($this->ppl && $state !== Order::STATE_OPEN) {
			$tempDir = $this->container->getParameters()['tempDir'] . '/ppl/';

			$grid->addColumn('PPL', function (Order $order, AdminGrid $datagrid) use ($tempDir) {
				try {
					$title = $order->pplError ? FileSystem::read($tempDir . $order->code) : $order->getPplCode();
				} catch (IOException $e) {
					$title = '';
				}

				return '<button type="button" role="button" title="' . $title . '" class="btn btn-sm btn-outline-' .
					($order->getPplCode() ? 'success' : ($order->pplError ? 'danger' : 'primary')) . '" data-toggle="tooltip" data-placement="bottom">
				<i class="fas fa-' . ($order->getPplCode() ? ($order->pplPrinted ? 'print' : 'check') : ($order->pplError ? 'exclamation' : 'times')) . '"></i>
				</button>';
			}, '%s', 'this.pplCode', ['class' => 'fit']);
		}

		$grid->addColumn('', function ($object, $grid) {
			return '<a class="btn btn-outline-primary btn-sm text-xs" style="white-space: nowrap" href="' .
				$grid->getPresenter()->link('comments', $object) . '"><i title="Komentáře" class="far fa-comment"></i>&nbsp;' . $object->commentCount .
				'</a>';
		});

//		$grid->addColumn('', function (Order $order) use ($grid) {
//			return $grid->getPresenter()->link('printDetail', $order);
//		}, "<a class='$btnSecondary' href='%s'><i class='fa fa-search'></i> Detail</a>", null, ['class' => 'minimal']);

		if (isset($configuration['printInvoices']) && $configuration['printInvoices'] && $state !== Order::STATE_OPEN) {
			$grid->addColumn('Tisk', function (Order $order, AdminGrid $datagrid) {
				$invoice = $order->invoices->first();

				if (!$invoice) {
					return '<button class="btn btn-sm btn-outline-danger disabled" disabled><i class="fas fa-times text-danger"></i></button>';
				}

				$link = $datagrid->getPresenter()->link(':Eshop:Export:invoice', $invoice->hash);

				return '<a target="_blank" href="' . $link . '" title="Tisknout fakturu" class="btn btn-sm btn-outline-' .
					($invoice->printed ? 'success' : 'primary') . '">
				<i class="fas fa-' . ($invoice->printed ? 'check link-success' : 'print link-primary') . '"></i>
				</a>';
			}, '%s', null, ['class' => 'fit']);
		}

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

		$grid->addFilterSelectInput('filter_payment', 'IF(:fp = "1", payment.paidTs IS NOT NULL, payment.paidTs IS NULL)', null, '- Stav platby -', null, [
			'0' => 'Nezaplaceno',
			'1' => 'Zaplaceno',
		], 'fp');

		$openOrderButton = function () use ($grid, $stateOpen, $btnSecondary): void {
			try {
				$grid->getForm()->addSubmit('openMultiple', Html::fromHtml('<i class="fas fa-angle-double-right"></i> ' . $stateOpen))->setHtmlAttribute('class', $btnSecondary)
					->onClick[] = [$this, 'openOrderMultiple'];
			} catch (\Throwable $e) {
				Debugger::log($e, ILogger::ERROR);
			}
		};

		$receiveOrderButton = function () use ($grid, $stateReceived, $btnSecondary): void {
			try {
				$grid->getForm()->addSubmit('receiveMultiple', Html::fromHtml('<i class="fas fa-angle-double-right"></i> ' . $stateReceived))->setHtmlAttribute('class', $btnSecondary)
					->onClick[] = [$this, 'receiveOrderMultiple'];
			} catch (\Throwable $e) {
				Debugger::log($e, ILogger::ERROR);
			}
		};

		$receiveAndCompleteOrderButton = function () use ($grid, $stateFinished, $btnSecondary): void {
			try {
				$grid->getForm()->addSubmit('receiveAndCompleteMultiple', Html::fromHtml('<i class="fas fa-angle-double-right"></i> ' . $stateFinished))->setHtmlAttribute('class', $btnSecondary)
					->onClick[] = [$this, 'receiveAndCompleteMultiple'];
			} catch (\Throwable $e) {
				Debugger::log($e, ILogger::ERROR);
			}
		};

		$cancelOrderButton = function () use ($grid, $stateCanceled, $btnSecondary): void {
			try {
				$grid->getForm()->addSubmit('cancelMultiple', Html::fromHtml('<i class="fas fa-angle-double-right"></i> ' . $stateCanceled))
					->setHtmlAttribute('class', $btnSecondary)
					->onClick[] = [$this, 'cancelOrderMultiple'];
			} catch (\Throwable $e) {
				Debugger::log($e, ILogger::ERROR);
			}
		};

		$completeOrderButton = function () use ($grid, $stateFinished, $btnSecondary): void {
			try {
				$grid->getForm()->addSubmit('completeMultiple', Html::fromHtml('<i class="fas fa-angle-double-right"></i> ' . $stateFinished))
					->setHtmlAttribute('class', $btnSecondary)
					->onClick[] = [$this, 'completeOrderMultiple'];
			} catch (\Throwable $e) {
				Debugger::log($e, ILogger::ERROR);
			}
		};

		$buttonsByTargetStates = [
			Order::STATE_OPEN => [
				Order::STATE_RECEIVED => $receiveOrderButton,
				Order::STATE_COMPLETED => $receiveAndCompleteOrderButton,
				Order::STATE_CANCELED => $cancelOrderButton,
			],
			Order::STATE_RECEIVED => [
				Order::STATE_OPEN => $openOrderButton,
				Order::STATE_COMPLETED => $completeOrderButton,
				Order::STATE_CANCELED => $cancelOrderButton,
			],
			Order::STATE_COMPLETED => [
				Order::STATE_OPEN => $openOrderButton,
				Order::STATE_RECEIVED => $receiveOrderButton,
				Order::STATE_CANCELED => $cancelOrderButton,
			],
			Order::STATE_CANCELED => [
				Order::STATE_OPEN => $openOrderButton,
				Order::STATE_RECEIVED => $receiveOrderButton,
				Order::STATE_COMPLETED => $completeOrderButton,
			],
		];

		foreach ($buttonsByTargetStates[$state] ?? [] as $targetState => $button) {
			if (!isset($orderStatesEvents[$state]) || !Arrays::contains($orderStatesEvents[$state], $targetState) ||
				($state === Order::STATE_OPEN && !$this->shopper->getEditOrderAfterCreation())) {
				continue;
			}

			$button();
		}

		$eshopBulkProperties = ['bannedTs', 'dpdPrinted', 'pplPrinted'];

		if ($this->onBulkActionsCreated) {
			Arrays::invoke($this->onBulkActionsCreated, $grid, $eshopBulkProperties);
		} else {
			$grid->addButtonBulkEdit('orderBulkForm', $eshopBulkProperties, 'ordersGrid');
		}

		if (isset($configuration['printMultiple']) && $configuration['printMultiple'] && $state !== Order::STATE_OPEN) {
			$grid->addBulkAction(
				'printDetailMultiple',
				'printDetailMultiple',
				'<i class="fas fa-print"></i> Tisk',
				'btn btn-outline-primary btn-sm',
				function (Presenter $presenter, string $destination, array $ids): void {
					if (\count($ids) === 0) {
						$presenter->flashMessage('Žádné vybrané položky!', 'warning');

						$presenter->redirect('this');
					}
				},
			);
		}

		if (isset($configuration['pauseOrder']) && $configuration['pauseOrder']) {
			$grid->addBulkAction('pauseOrder', 'pauseOrder', '<i class="fas fa-pause"></i>');
			$grid->addBulkAction('unPauseOrder', 'unPauseOrder', '<i class="fas fa-play"></i>');
		}

		if (isset($configuration['printInvoices']) && $configuration['printInvoices'] && $state !== Order::STATE_OPEN) {
			$grid->addBulkAction(
				'printInvoiceMultiple',
				'printInvoiceMultiple',
				'<i class="fas fa-print"></i> Faktury',
				'btn btn-outline-primary btn-sm',
				function (Presenter $presenter, string $destination, array $ids): void {
					if (\count($ids) === 0) {
						$presenter->flashMessage('Žádné vybrané položky!', 'warning');

						$presenter->redirect('this');
					}
				},
			);
		}

		if ($state !== Order::STATE_OPEN) {
			$submit = $grid->getForm()->addSubmit('exportZasilkovna', Html::fromHtml('<i class="fas fa-download"></i> Zásilkovna (CSV)'));
			$submit->setHtmlAttribute('class', $btnSecondary);
			$submit->onClick[] = [$this, 'exportZasilkovna'];
		}

		if (Helpers::isConfigurationActive($configuration, 'exportPPC') && $state !== Order::STATE_OPEN) {
			$grid->addBulkAction('exportPPC', 'exportPPC', '<i class="fas fa-download"></i> PPC (CSV)');
		}

		if (Helpers::isConfigurationActive($configuration, 'exportTargito') && $state !== Order::STATE_OPEN) {
			$grid->addBulkAction('exportTargito', 'exportTargito', '<i class="fas fa-download"></i> Targito (CSV)');
		}

		if (Helpers::isConfigurationActive($configuration, 'eHub') && $state !== Order::STATE_OPEN) {
			$grid->addBulkAction('sendEHub', 'EHubSendOrders', '<i class="fas fa-paper-plane"></i> eHUB');
		}

		if ($this->dpd && $state !== Order::STATE_OPEN) {
			$grid->addBulkAction('sendDPD', 'sendDPD', '<i class="fas fa-paper-plane"></i> DPD')->setHtmlAttribute('formtarget', '_blank');
			$grid->addBulkAction('printDPD', 'printDPD', '<i class="fas fa-print"></i> DPD')->setHtmlAttribute('formtarget', '_blank');
		}

		if ($this->ppl && $state !== Order::STATE_OPEN) {
			$grid->addBulkAction('sendPPL', 'sendPPL', '<i class="fas fa-paper-plane"></i> PPL')->setHtmlAttribute('formtarget', '_blank');
			$grid->addBulkAction('printPPL', 'printPPL', '<i class="fas fa-print"></i> PPL')->setHtmlAttribute('formtarget', '_blank');
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
			$paymentInfo = !isset($this->configuration['showPay']) || (isset($this->configuration['showPay']) && $this->configuration['showPay']) ?
				"<br><small title='Nezaplaceno'><i class='fas fa-stop fa-xs' style='color: gray'></i> 
<a href='$linkPay'>Zaplatit</a>" . (isset($this->configuration['showExtendedPay']) && $this->configuration['showExtendedPay'] ?
					"  | <a href='$linkPayPlusEmail'>Zaplatit + e-mail</a>" : '') . '</small>' : '';
		}

		return "<a href='$link' class='" . ($payment->paidTs ? 'text-success font-weight-bold' : '') . "'>" . $payment->getTypeName() . '</a>' . $paymentInfo;
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
			$deliveryInfo = !isset($this->configuration['showDispatch']) || (isset($this->configuration['showDispatch']) && $this->configuration['showDispatch']) ?
				"<br><small title='Neexpedováno'><i class='fas fa-stop fa-xs' style='color: gray'></i>
 <a href='$linkShip'>Expedovat</a>" . (isset($this->configuration['showExtendedDispatch']) && $this->configuration['showExtendedDispatch'] ?
					"  | <a href='$linkShipPlusEmail'>Expedovat + e-mail</a>" : '') . '</small>' : '';
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

			$this->orderRepository->cancelOrder($order);
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

	public function openOrderMultiple(Button $button): void
	{
		/** @var \Grid\Datagrid $grid */
		$grid = $button->lookup(Datagrid::class);

		foreach ($grid->getSelectedIds() as $id) {
			$this->openOrder($grid->getSource()->where('this.uuid', $id)->first(), $grid, false);
		}

		$grid->getPresenter()->flashMessage('Provedeno', 'success');
		$grid->getPresenter()->redirect('this');
	}

	public function openOrder(Order $object, ?Datagrid $grid = null, bool $redirectAfter = true): void
	{
		/** @var \Eshop\BackendPresenter $presenter */
		$presenter = $grid->getPresenter();

		/** @var \Admin\DB\Administrator|null $admin */
		$admin = $presenter->admin->getIdentity();

		$this->orderRepository->openOrder($object, $admin);

		if (!$redirectAfter) {
			return;
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
