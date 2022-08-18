<?php

declare(strict_types=1);

namespace Eshop\Controls;

use Eshop\Admin\Controls\OrderGridFactory;
use Eshop\CheckoutManager;
use Eshop\DB\CatalogPermissionRepository;
use Eshop\DB\OrderRepository;
use Eshop\Shopper;
use Grid\Datalist;
use League\Csv\Writer;
use Nette\Application\Application;
use Nette\Application\Responses\FileResponse;
use Nette\Application\UI\Form;
use Nette\Localization\Translator;
use Nette\Utils\Arrays;
use Nette\Utils\FileSystem;
use StORM\Collection;
use StORM\ICollection;
use Tracy\Debugger;
use Tracy\ILogger;

/**
 * Class Products
 * @package Eshop\Controls
 */
class OrderList extends Datalist
{
	/**
	 * Don't remove! Using in template.
	 */
	public CheckoutManager $checkoutManager;

	/**
	 * Don't remove! Using in template.
	 */
	public OrderRepository $orderRepository;

	public Shopper $shopper;

	private Translator $translator;

	private OrderGridFactory $orderGridFactory;

	private Application $application;

	private string $tempDir;

	public function __construct(
		Translator $translator,
		OrderGridFactory $orderGridFactory,
		OrderRepository $orderRepository,
		CatalogPermissionRepository $catalogPermissionRepository,
		Shopper $shopper,
		CheckoutManager $checkoutManager,
		Application $application,
		?Collection $orders = null
	) {
		if (!$orders && $shopper->getCustomer()) {
			/** @var \Eshop\DB\CatalogPermission $permission */
			$permission = $catalogPermissionRepository->many()->where('fk_account', $shopper->getCustomer()->getAccount())->first();
		}

		parent::__construct($orders ?? $orderRepository->getFinishedOrders($shopper->getCustomer(), $shopper->getMerchant(), isset($permission) ?
				($permission->viewAllOrders ? null : $shopper->getCustomer()->getAccount()) : null));

		$this->setDefaultOnPage(10);
		$this->setDefaultOrder('createdTs', 'DESC');

		$this->addFilterExpression('search', function (ICollection $collection, $value) use ($orderRepository, $shopper): void {
			$suffix = $orderRepository->getConnection()->getMutationSuffix();

			$or = "this.code = :code OR items.productName$suffix LIKE :string";

			if ($shopper->getMerchant()) {
				$or .= ' OR purchase.accountFullname LIKE :string OR account.fullname LIKE :string';
				$or .= ' OR purchase.fullname LIKE :string OR customer.fullname LIKE :string OR customer.company LIKE :string';
			}

			$collection->where($or, ['code' => $value, 'string' => '%' . $value . '%'])
				->join(['carts' => 'eshop_cart'], 'purchase.uuid=carts.fk_purchase')
				->join(['items' => 'eshop_cartitem'], 'carts.uuid=items.fk_cart')
				->join(['account' => 'security_account'], 'account.uuid=purchase.fk_account');
		}, '');

		/** @var \Forms\Form $form */
		$form = $this->getFilterForm();

		$form->addText('search');
		$form->addSubmit('submit');

		$this->translator = $translator;
		$this->orderGridFactory = $orderGridFactory;
		$this->orderRepository = $orderRepository;
		$this->shopper = $shopper;
		$this->checkoutManager = $checkoutManager;
		$this->application = $application;
	}

	public function setTempDir(string $tempDir): void
	{
		$this->tempDir = $tempDir;
	}

	public function handleCancel(): void
	{
		$this->setFilters(null);
		$this->setPage(1);

		$this->getPresenter()->redirect('this');
	}

	public function render(): void
	{
		$this->template->paginator = $this->getPaginator();

		/** @var \Nette\Bridges\ApplicationLatte\Template $template */
		$template = $this->template;

		$template->render($this->template->getFile() ?: __DIR__ . '/orderList.latte');
	}

	public function createComponentSelectOrdersForm(): Form
	{
		$form = new \Nette\Application\UI\Form();

		foreach ($this->getItemsOnPage() as $order) {
			$form->addCheckbox('check_' . $order->getPK());
		}

		$form->addSubmit('finish', $this->translator->translate('orderL.finish', 'Vyřídit'))
			->setHtmlAttribute('onClick', "return confirm('" . $this->translator->translate('.really?', 'Opravdu?') . "')");
		$form->addSubmit('cancel', $this->translator->translate('orderL.cancel', 'Stornovat'))
			->setHtmlAttribute('onClick', "return confirm('" . $this->translator->translate('.really?', 'Opravdu?') . "')");

		$form->addSubmit('export');
		$form->addSubmit('exportAccounts');
		$form->addSubmit('exportItems');
		$form->addSubmit('exportCsv');
		$form->addSubmit('exportExcel');
		$form->addSubmit('exportExcelZip');
		$form->addSubmit('exportCsvApi');

		$form->onSuccess[] = function (\Nette\Forms\Form $form): void {
			$values = $form->getValues('array');

			$values = \array_filter($values, function ($value) {
				return $value;
			});

			if (\count($values) === 0) {
				$values = $this->getFilteredSource()->toArray();
			} else {
				foreach (\array_keys($values) as $key) {
					$values[\explode('_', $key)[1]] = $this->orderRepository->one(\explode('_', $key)[1], true);
					unset($values[$key]);
				}
			}

			/** @var \Nette\Application\UI\Component $submitButton */
			$submitButton = $form->isSubmitted();

			$submitName = $submitButton->getName();

			if ($submitName === 'export') {
				$this->exportOrders($values);
			} elseif ($submitName === 'exportAccounts') {
				$this->exportOrdersAccounts($values);
			} elseif ($submitName === 'exportItems') {
				$this->exportOrdersItems($values);
			} elseif ($submitName === 'exportExcel') {
				$this->exportOrdersExcel($values);
			} elseif ($submitName === 'exportExcelZip') {
				$this->exportOrdersExcelZip($values);
			} elseif ($submitName === 'exportCsvApi') {
				$this->exportCsvApi($values);
			} elseif ($submitName === 'exportCsv') {
				$this->exportCsv($values);
			}

			foreach ($values as $order) {
				if ($submitName === 'finish') {
					$this->orderGridFactory->completeOrder($order);
				} elseif ($submitName === 'cancel') {
					$this->orderGridFactory->cancelOrder($order);
				}
			}

			$this->getPresenter()->redirect('this');
		};

		return $form;
	}

	public function handleFinishOrder(string $orderId): void
	{
		$order = $this->orderRepository->one($orderId);
		$this->orderGridFactory->completeOrder($order);
	}

	public function handleCancelOrder(string $orderId): void
	{
		/** @var \Eshop\DB\Order|null $order */
		$order = $this->orderRepository->one($orderId);

		if (!$order) {
			return;
		}

		$this->orderGridFactory->cancelOrder($order);
	}

	public function handleExport(string $orderId): void
	{
		$object = $this->orderRepository->one($orderId, true);
		$tempFilename = \tempnam($this->tempDir, 'csv');
		$this->application->onShutdown[] = function () use ($tempFilename): void {
			try {
				FileSystem::delete($tempFilename);
			} catch (\Throwable $e) {
				Debugger::log($e, ILogger::WARNING);
			}
		};

		/** @phpstan-ignore-next-line volá metodu presenteru, pozůstatek z Linde */
		$this->getPresenter()->csvOrderExportAPI($object, Writer::createFromPath($tempFilename, 'w+'));

		$this->getPresenter()->sendResponse(new FileResponse($tempFilename, "order-$object->code.csv", 'text/csv'));
	}

	public function exportCsvApi(array $orders): void
	{
		$tempFilename = \tempnam($this->tempDir, 'csv');
		$this->application->onShutdown[] = function () use ($tempFilename): void {
			try {
				FileSystem::delete($tempFilename);
			} catch (\Throwable $e) {
				Debugger::log($e, ILogger::WARNING);
			}
		};
		/** @phpstan-ignore-next-line volá metodu presenteru, pozůstatek z Linde */
		$this->getPresenter()->csvOrderExportItemsAPI($orders, Writer::createFromPath($tempFilename, 'w+'));

		$this->getPresenter()->sendResponse(new FileResponse($tempFilename, 'orders.csv', 'text/csv'));
	}

	/**
	 * @param \Eshop\DB\Order[] $orders
	 */
	public function exportCsv(array $orders): void
	{
		$tempFilename = \tempnam($this->tempDir, 'csv');
		$this->application->onShutdown[] = function () use ($tempFilename): void {
			try {
				FileSystem::delete($tempFilename);
			} catch (\Throwable $e) {
				Debugger::log($e, ILogger::WARNING);
			}
		};

		$writer = Writer::createFromPath($tempFilename, 'w+');
		$showVat = $this->shopper->getShowVat();

		$writer->setDelimiter(';');

		if ($showVat) {
			$writer->insertOne([
				'order',
				'productName',
				'productCode',
				'amount',
				'price',
				'priceVat',
				'priceSum',
				'priceVatSum',
				'vatPct',
				'note',
				'account',
			]);
		} else {
			$writer->insertOne([
				'order',
				'productName',
				'productCode',
				'amount',
				'price',
				'priceSum',
				'note',
				'account',
			]);
		}

		foreach ($orders as $order) {
			foreach ($order->purchase->getItems() as $item) {
				if ($showVat) {
					$writer->insertOne([
						$order->code,
						$item->productName,
						$item->getFullCode(),
						$item->amount,
						$item->price,
						$item->priceVat,
						$item->getPriceSum(),
						$item->getPriceVatSum(),
						$item->vatPct,
						$item->note,
						$order->purchase->accountFullname,
					]);
				} else {
					$writer->insertOne([
						$order->code,
						$item->productName,
						$item->getFullCode(),
						$item->amount,
						$item->price,
						$item->getPriceSum(),
						$item->note,
						$order->purchase->accountFullname,
					]);
				}
			}
		}

		$this->getPresenter()->sendResponse(new FileResponse($tempFilename, 'orders.csv', 'text/csv'));
	}

	/**
	 * @param \Eshop\DB\Order[] $orders
	 * @throws \League\Csv\CannotInsertRecord
	 * @throws \League\Csv\InvalidArgument
	 * @throws \Nette\Application\AbortException
	 * @throws \Nette\Application\BadRequestException
	 */
	public function exportOrders(array $orders): void
	{
		$tempFilename = \tempnam($this->tempDir, 'csv');
		$this->application->onShutdown[] = function () use ($tempFilename): void {
			try {
				FileSystem::delete($tempFilename);
			} catch (\Throwable $e) {
				Debugger::log($e, ILogger::WARNING);
			}
		};

		$this->orderRepository->csvExportOrders($orders, Writer::createFromPath($tempFilename, 'w+'));

		$this->getPresenter()->sendResponse(new FileResponse($tempFilename, 'orders.csv', 'text/csv'));
	}

	/**
	 * @param \Eshop\DB\Order[] $orders
	 * @throws \Nette\Application\AbortException
	 * @throws \Nette\Application\BadRequestException
	 */
	public function exportOrdersAccounts(array $orders): void
	{
		$zip = new \ZipArchive();

		$zipFilename = \tempnam($this->tempDir, 'zip');

		if ($zip->open($zipFilename, \ZipArchive::CREATE) !== true) {
			exit("cannot open <$zipFilename>\n");
		}

		$accountsInfo = [];
		$accounts = [];

		/** @var \Eshop\DB\Order $order */
		foreach ($orders as $order) {
			$account = $order->purchase->account;

			if ($account) {
				$accounts[$account->getPK()][] = $order;
				$accountsInfo[$account->getPK()] = $account->fullname;
			} elseif ($order->purchase->accountFullname) {
				$accounts[$account][] = $order;
				$accountsInfo[$account] = $order->purchase->accountFullname;
			} else {
				continue;
			}
		}

		foreach ($accounts as $key => $orders) {
			$tempFilename = \tempnam($this->tempDir, 'csv');
			$this->application->onShutdown[] = function () use ($tempFilename): void {
				try {
					FileSystem::delete($tempFilename);
				} catch (\Throwable $e) {
					Debugger::log($e, ILogger::WARNING);
				}
			};

			$this->orderRepository->csvExportOrders($orders, Writer::createFromPath($tempFilename, 'w+'));

			$zip->addFile($tempFilename, $accountsInfo[$key] . '.csv');
		}

		$zip->close();

		$this->application->onShutdown[] = function () use ($zipFilename): void {
			try {
				FileSystem::delete($zipFilename);
			} catch (\Throwable $e) {
				Debugger::log($e, ILogger::WARNING);
			}
		};

		$this->getPresenter()->sendResponse(new FileResponse($zipFilename, 'orders.zip', 'application/zip'));
	}

	/**
	 * @param \Eshop\DB\Order[] $orders
	 * @throws \Nette\Application\AbortException
	 * @throws \Nette\Application\BadRequestException
	 */
	public function exportOrdersItems(array $orders): void
	{
		$zip = new \ZipArchive();

		$zipFilename = \tempnam($this->tempDir, 'zip');

		if ($zip->open($zipFilename, \ZipArchive::CREATE) !== true) {
			exit("cannot open <$zipFilename>\n");
		}

		/** @var \Eshop\DB\Order $order */
		foreach ($orders as $order) {
			$tempFilename = \tempnam($this->tempDir, 'csv');
			$this->application->onShutdown[] = function () use ($tempFilename): void {
				try {
					FileSystem::delete($tempFilename);
				} catch (\Throwable $e) {
					Debugger::log($e, ILogger::WARNING);
				}
			};
			$this->orderRepository->csvExport($order, Writer::createFromPath($tempFilename, 'w+'));

			$zip->addFile($tempFilename, $order->code . '_' . $order->purchase->accountFullname . '.csv');
		}

		$zip->close();

		$this->application->onShutdown[] = function () use ($zipFilename): void {
			try {
				FileSystem::delete($zipFilename);
			} catch (\Throwable $e) {
				Debugger::log($e, ILogger::WARNING);
			}
		};

		$this->getPresenter()->sendResponse(new FileResponse($zipFilename, 'orders.zip', 'application/zip'));
	}

	public function exportOrdersExcel(array $orders): void
	{
		$filename = \tempnam($this->tempDir, 'xlsx');

		$writer = new \XLSXWriter();

		$this->orderRepository->excelExportAll($orders, $writer);

		$writer->writeToFile($filename);

		$this->application->onShutdown[] = function () use ($filename): void {
			try {
				FileSystem::delete($filename);
			} catch (\Throwable $e) {
				Debugger::log($e, ILogger::WARNING);
			}
		};

		$this->getPresenter()->sendResponse(new FileResponse($filename, 'orders.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'));
	}

	public function exportOrdersExcelZip(array $orders): void
	{
		if (\count($orders) === 1) {
			$order = Arrays::first($orders);

			$tempFilename = \tempnam($this->tempDir, 'xlsx');
			$this->application->onShutdown[] = function () use ($tempFilename): void {
				try {
					FileSystem::delete($tempFilename);
				} catch (\Throwable $e) {
					Debugger::log($e, ILogger::WARNING);
				}
			};

			$writer = new \XLSXWriter();

			$this->orderRepository->excelExport($order, $writer, $order->code);

			$writer->writeToFile($tempFilename);

			$this->getPresenter()->sendResponse(new FileResponse($tempFilename, "$order->code.xlsx", 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'));
		}

		$zip = new \ZipArchive();

		$zipFilename = \tempnam($this->tempDir, 'zip');

		if ($zip->open($zipFilename, \ZipArchive::CREATE) !== true) {
			exit("cannot open <$zipFilename>\n");
		}

		/** @var \Eshop\DB\Order $order */
		foreach ($orders as $order) {
			$tempFilename = \tempnam($this->tempDir, 'xlsx');
			$this->application->onShutdown[] = function () use ($tempFilename): void {
				try {
					FileSystem::delete($tempFilename);
				} catch (\Throwable $e) {
					Debugger::log($e, ILogger::WARNING);
				}
			};

			$writer = new \XLSXWriter();

			$this->orderRepository->excelExport($order, $writer, $order->code);

			$writer->writeToFile($tempFilename);

			$zip->addFile($tempFilename, $order->code . '.xlsx');
		}

		$zip->close();

		$this->application->onShutdown[] = function () use ($zipFilename): void {
			try {
				FileSystem::delete($zipFilename);
			} catch (\Throwable $e) {
				Debugger::log($e, ILogger::WARNING);
			}
		};

		$this->getPresenter()->sendResponse(new FileResponse($zipFilename, 'orders.zip', 'application/zip'));
	}
}
