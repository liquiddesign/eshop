<?php

declare(strict_types=1);

namespace Eshop\Admin;

use Admin\Controls\AdminForm;
use Admin\Controls\AdminGrid;
use Eshop\DB\Customer;
use Eshop\DB\CustomerRepository;
use Eshop\DB\EHubTransaction;
use Eshop\DB\EHubTransactionRepository;
use Eshop\DB\OrderRepository;
use Eshop\Integration\EHub;
use Forms\Form;
use Grid\Datagrid;
use StORM\Collection;
use StORM\ICollection;
use Tracy\Debugger;
use Tracy\ILogger;

class EHubPresenter extends \Eshop\BackendPresenter
{
	#[\Nette\DI\Attributes\Inject]
	public EHubTransactionRepository $EHubTransactionRepository;
	
	#[\Nette\DI\Attributes\Inject]
	public OrderRepository $orderRepository;

	#[\Nette\DI\Attributes\Inject]
	public EHub $EHub;

	#[\Nette\DI\Attributes\Inject]
	public CustomerRepository $customerRepository;
	
	public function createComponentGridTransactions(): AdminGrid
	{
//		$btnSecondary = 'btn btn-sm btn-outline-primary';

		$grid = $this->gridFactory->create($this->EHubTransactionRepository->many(), 20, 'this.createdTs', 'DESC', true);

		$grid->onAnchor[] = function (AdminGrid $grid): void {
			$source = $grid->getFilteredSource();
			$source->select(['commissionSum' => 'SUM(this.commission)']);

			$grid->template->commissionSum = $source->firstValue('commissionSum');
			$grid->template->setFile(__DIR__ . '/templates/eHubTransactionsGrid.latte');
		};

		$grid->addColumnSelector();

		$grid->addColumnText('Vytvořen', "createdTs|date:'d.m.Y G:i'", '%s', 'createdTs', ['class' => 'fit'])->onRenderCell[] = [$grid, 'decoratorNowrap'];
		$grid->addColumnText('Related click', "clickDateTime|date:'d.m.Y G:i'", '%s', 'clickDateTime', ['class' => 'fit'])->onRenderCell[] = [$grid, 'decoratorNowrap'];
		$grid->addColumnText('ID', 'transactionId', '%s', 'transactionId', ['class' => 'fit']);
		$grid->addColumn('Stav', function (EHubTransaction $EHubTransaction): string {
			return EHubTransaction::STATUSES[$EHubTransaction->status] ?? '';
		}, '%s', 'status', ['class' => 'fit']);
		$grid->addColumn('Objednávka', function (EHubTransaction $EHubTransaction): ?string {
			if ($EHubTransaction->getValue('order') === null) {
				return null;
			}

			$link = $this->link(':Eshop:Admin:Order:printDetail', ['order' => $EHubTransaction->order]);
			$orderCode = $EHubTransaction->order->code;

			return "<a href='$link'><i class='fa fa-external-link-alt fa-sm'></i>&nbsp;$orderCode</a>";
		}, '%s', 'order.code');
		$btnSecondary = 'btn btn-sm btn-outline-primary';
		$grid->addColumn('Login', function (EHubTransaction $object, Datagrid $grid) use ($btnSecondary) {
			if (!$object->order || !$object->order->purchase->account) {
				return '';
			}

			$account = $object->order->purchase->account;

			$link = $grid->getPresenter()->link('loginCustomer!', [$account->login]);

			return $account->isActive() ?
				"<a class='$btnSecondary' target='_blank' href='$link'><i class='fa fa-sign-in-alt'></i></a>" :
				"<a class='$btnSecondary disabled' href='#'><i class='fa fa-sign-in-alt'></i></a>";
		}, '%s', null, ['class' => 'minimal']);
		$grid->addColumnText('Cena', 'orderAmount', '%s', 'orderAmount', ['class' => 'fit']);
		$grid->addColumnText('Originální cena', 'originalOrderAmount', '%s', 'originalOrderAmount', ['class' => 'fit']);
		$grid->addColumnText('Měna', 'originalCurrency', '%s', 'originalCurrency', ['class' => 'fit']);
		$grid->addColumnText('Provize', 'commission', '%s', 'commission');
		$grid->addColumn('Provize %', function (EHubTransaction $EHubTransaction): string {
			return \round($EHubTransaction->commission / $EHubTransaction->orderAmount * 100, 2) . ' %';
		}, '%s', null, ['class' => 'fit']);
		$grid->addColumnText('Typ', 'type', '%s', 'type', ['class' => 'fit']);
		$grid->addColumnText('ID objednávky', 'orderId', '%s', 'orderId', ['class' => 'fit']);
		$grid->addColumnText('Kupón', 'couponCode', '%s', 'couponCode', ['class' => 'fit']);
		$grid->addColumn('Nový zákazník', function (EHubTransaction $EHubTransaction): string {
			return $EHubTransaction->newCustomer === true ? '<i class="fa fa-check text-success"></i>' : ($EHubTransaction->newCustomer === false ? '<i class="fa fa-times text-danger"></i>' : '');
		}, '%s', 'newCustomer', ['class' => 'fit']);
		
		$grid->addColumnLinkDetail('detailTransaction');
		$grid->addColumnActionDelete();

		$grid->addButtonDeleteSelected(null, false, null, 'this.uuid');
		$grid->addBulkAction('changeStatus', 'changeStatus', 'Změnit stav hromadně');
		
		$grid->addFilterTextInput('search', ['transactionId'], null, 'ID');
		$grid->addFilterSelectInput('status', 'status = :q', 'Status', '- Status -', null, EHubTransaction::STATUSES);

		$grid->addFilterPolyfillDatetime(function (ICollection $source, $value): void {
			$source->where('this.createdTs >= :created_from', ['created_from' => $value]);
		}, '', 'date_from', null, ['defaultHour' => '00', 'defaultMinute' => '00'])->setHtmlAttribute('class', 'form-control form-control-sm flatpicker')->setHtmlAttribute('placeholder', 'Datum od');

		$grid->addFilterPolyfillDatetime(function (ICollection $source, $value): void {
			$source->where('this.createdTs <= :created_to', ['created_to' => $value]);
		}, '', 'created_to', null, ['defaultHour' => '23', 'defaultMinute' => '59'])->setHtmlAttribute('class', 'form-control form-control-sm flatpicker')->setHtmlAttribute('placeholder', 'Datum do');
		$grid->addFilterButtons(['transactions']);
		
		return $grid;
	}
	
	public function createComponentFormTransaction(): Form
	{
		/** @var \Eshop\DB\EHubTransaction|null $EHubTransaction */
		$EHubTransaction = $this->getParameter('EHubTransaction');

		$form = $this->formFactory->create();
		
		$form->addText('transactionId', 'ID')->setDisabled();

		if ($EHubTransaction) {
			$form->addText('order', 'Objednávka')->setDisabled();
		} else {
			$form->addSelect2('order', 'Objednávka', $this->orderRepository->many()->orderBy(['createdTs' => 'DESC'])->toArrayOf('code'))
				->setRequired();
		}

		$form->addSelect('status', 'Stav', EHubTransaction::STATUSES_TO_UPDATE)->setRequired()->checkDefaultValue(false);
	
		$form->addSubmits(!$EHubTransaction);
		
		$form->onSuccess[] = function (AdminForm $form) use ($EHubTransaction): void {
			$values = $form->getValues('array');

			try {
				if ($EHubTransaction) {
					$this->EHub->updateTransaction($EHubTransaction, $values['status']);
				} else {
//					$order = $this->orderRepository->one($values['order']);
//
//					$newTransaction = $this->EHub->updateTransactionByOrder($order);
//					$values['transactionId'] = $newTransaction['transaction']['id'];
				}
			} catch (\Exception $e) {
				$this->flashMessage('Transakci nelze odeslat!', 'error');
				$this->redirect('this');
			}

			$EHubTransaction = $this->EHubTransactionRepository->syncOne($values);
			
			$this->flashMessage('Uloženo', 'success');
			
			$form->processRedirect('detailTransaction', 'transactions', [$EHubTransaction]);
		};
		
		return $form;
	}

	public function handleLoginCustomer($login): void
	{
		$this->user->login($this->customerRepository->getByAccountLogin($login), null, [Customer::class]);

		$this->presenter->redirect(':Web:Index:default');
	}

	public function renderChangeStatus(array $ids): void
	{
		unset($ids);

		$this->template->headerLabel = 'Transakce';
		$this->template->headerTree = [
			['eHUB', 'transactions'],
		];
		$this->template->displayButtons = [
			$this->createBackButton('transactions'),
		];
		$this->template->displayControls = [$this->getComponent('changeStatusForm')];
	}

	public function createComponentChangeStatusForm(): AdminForm
	{
		return $this->formFactory->createBulkActionForm($this->getBulkFormGrid('gridTransactions'), function (array $values, Collection $collection): void {
			/** @var \Eshop\DB\EHubTransaction $transaction */
			foreach ($collection as $transaction) {
				try {
					$this->EHub->updateTransaction($transaction, $values['status']);

					$transaction->update(['status' => $values['status']]);
				} catch (\Exception $e) {
					$this->flashMessage('Některé transakce nelze odeslat!', 'warning');
					$this->redirect('this');
				}
			}

			$this->flashMessage('Provedeno', 'success');
		}, $this->getBulkFormActionLink(), $this->EHubTransactionRepository->many(), $this->getBulkFormIds(), function (AdminForm $form): void {
			$form->addSelect('status', 'Stav', EHubTransaction::STATUSES_TO_UPDATE)->setRequired();
		});
	}
	
	public function renderTransactions(): void
	{
		$this->template->headerLabel = 'Transakce';
		$this->template->headerTree = [
			['eHUB', 'transactions'],
		];
		$this->template->displayButtons = [
			$this->createNewItemButton('newTransaction'),
			$this->createButtonWithClass('syncTransactions!', '<i class="fa fa-sync"></i>&nbsp;&nbsp;Aktualizovat', 'btn btn-sm btn-outline-primary'),
		];
		$this->template->displayControls = [$this->getComponent('gridTransactions')];
	}
	
	public function renderNewTransaction(): void
	{
		$this->template->headerLabel = 'Nová transakce';
		$this->template->headerTree = [
			['eHUB', 'transactions'],
			['Nová transakce'],
		];
		$this->template->displayButtons = [$this->createBackButton('transactions'),];
		$this->template->displayControls = [$this->getComponent('formTransaction')];
	}

	public function actionDetailTransaction(EHubTransaction $EHubTransaction): void
	{
		/** @var \Admin\Controls\AdminForm $form */
		$form = $this->getComponent('formTransaction');

		$values = $EHubTransaction->toArray();
		$values['order'] = $EHubTransaction->order ? $EHubTransaction->order->code : null;

		$form->setDefaults($values);
	}
	
	public function renderDetailTransaction(EHubTransaction $EHubTransaction): void
	{
		unset($EHubTransaction);

		$this->template->headerLabel = 'Detail';
		$this->template->headerTree = [
			['eHUB', 'transactions'],
			['Detail'],
		];
		$this->template->displayButtons = [
			$this->createBackButton('transactions'),
		];

		$this->template->displayControls = [$this->getComponent('formTransaction')];
	}

	public function handleSyncTransactions(): void
	{
		try {
			$this->EHub->syncTransactions();

			$this->flashMessage('Uloženo', 'success');
		} catch (\Exception $e) {
			Debugger::log($e, ILogger::WARNING);

			$this->flashMessage('Chyba synchronizace', 'error');
		}

		$this->redirect('this');
	}
}
