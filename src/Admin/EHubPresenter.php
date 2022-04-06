<?php

declare(strict_types=1);

namespace Eshop\Admin;

use Admin\BackendPresenter;
use Admin\Controls\AdminForm;
use Admin\Controls\AdminGrid;
use Eshop\DB\EHubTransaction;
use Eshop\DB\EHubTransactionRepository;
use Eshop\DB\OrderRepository;
use Eshop\Integration\EHub;
use Forms\Form;
use Tracy\Debugger;
use Tracy\ILogger;

class EHubPresenter extends BackendPresenter
{
	/** @inject */
	public EHubTransactionRepository $EHubTransactionRepository;
	
	/** @inject */
	public OrderRepository $orderRepository;

	/** @inject */
	public EHub $EHub;
	
	public function createComponentGridTransactions(): AdminGrid
	{
//		$btnSecondary = 'btn btn-sm btn-outline-primary';

		$grid = $this->gridFactory->create($this->EHubTransactionRepository->many(), 20, 'code', 'ASC', true);
		$grid->addColumnSelector();

		$grid->addColumnText('ID', 'transactionId', '%s', 'transactionId', ['class' => 'fit']);
		$grid->addColumn('Stav', function (EHubTransaction $EHubTransaction): string {
			return EHubTransaction::STATUSES[$EHubTransaction->status] ?? '';
		}, '%s', 'status', ['class' => 'fit']);
		$grid->addColumn('Objednávka', function (EHubTransaction $EHubTransaction): ?string {
			if ($EHubTransaction->getValue('order') === null) {
				return null;
			}

			$link = $this->link(':Eshop:Admin:Order:detail', ['order' => $EHubTransaction->order]);
			$orderCode = $EHubTransaction->order->code;

			return "<a href='$link'><i class='fa fa-external-link-alt fa-sm'></i>&nbsp;$orderCode</a>";
		}, '%s', 'order.code');
		
		$grid->addColumnLinkDetail('detailTransaction');
		$grid->addColumnActionDelete();

		$grid->addButtonDeleteSelected(null, false, null, 'this.uuid');
		
		$grid->addFilterTextInput('search', ['transactionId'], null, 'ID');
		$grid->addFilterSelectInput('status', 'status = :q', 'Status', '- Status -', null, EHubTransaction::STATUSES);
		$grid->addFilterButtons(['transactions']);
		
		return $grid;
	}
	
	public function createComponentFormTransaction(): Form
	{
		/** @var \Eshop\DB\EHubTransaction|null $EHubTransaction */
		$EHubTransaction = $this->getParameter('EHubTransaction');

		$form = $this->formFactory->create();
		
		$form->addText('transactionId', 'ID')->setDisabled();
		$form->addText('order', 'Objednávka')->setDisabled();
		$form->addSelect('status', 'Stav', EHubTransaction::STATUSES)->setRequired();
	
		$form->addSubmits(!$EHubTransaction);
		
		$form->onSuccess[] = function (AdminForm $form): void {
			$values = $form->getValues('array');

			$EHubTransaction = $this->EHubTransactionRepository->syncOne($values);

//			$this->EHub->updateTransactionByOrder($EHubTransaction);
			
			$this->flashMessage('Uloženo', 'success');
			
			$form->processRedirect('detailTransaction', 'transactions', [$EHubTransaction]);
		};
		
		return $form;
	}
	
	public function renderTransactions(): void
	{
		$this->template->headerLabel = 'Transakce';
		$this->template->headerTree = [
			['eHUB', 'transactions'],
		];
		$this->template->displayButtons = [
			$this->createNewItemButton('newTransaction'),
			$this->createButtonWithClass('syncTransactions!', '<i class="fa fa-sync"></i>&nbsp;&nbsp;Synchronizovat', 'btn btn-sm btn-outline-primary'),
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
