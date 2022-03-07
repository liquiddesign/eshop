<?php

declare(strict_types=1);

namespace Eshop\Admin;

use Admin\BackendPresenter;
use Admin\Controls\AdminForm;
use Admin\Controls\AdminGrid;
use Eshop\DB\Invoice;
use Eshop\DB\InvoiceRepository;
use Eshop\DB\OrderRepository;
use Forms\Form;

class InvoicesPresenter extends BackendPresenter
{
	/** @inject */
	public InvoiceRepository $invoiceRepository;
	
	/** @inject */
	public OrderRepository $orderRepository;
	
	public function createComponentGrid(): AdminGrid
	{
		$grid = $this->gridFactory->create($this->invoiceRepository->many(), 20, 'code', 'ASC', true);
		
		$grid->addColumnText('Kód', 'code', '%s', 'code', ['class' => 'fit']);
		
		$grid->addColumnLinkDetail('detail');
		
		$grid->addFilterTextInput('search', ['code'], null, 'Kód');
		$grid->addFilterButtons();
		
		return $grid;
	}
	
	public function createComponentForm(): Form
	{
		$form = $this->formFactory->create();
		
		$form->addText('code', 'Kód')->setRequired();
		$form->addDate('exposed', 'Datum vystavení')->setRequired();
		$form->addDate('taxDate', 'Datum zdanitelného plnění')->setRequired();
		$form->addDate('dueDate', 'Datum splatnosti')->setRequired();
		$form->addDataSelect('order', 'Objednávka', $this->orderRepository->many()->toArrayOf('code'));
		
		$form->addGroup('Stav faktury');
		$form->addDate('paidDate', 'Zaplaceno');
		$form->addDate('canceled', 'Storno');
	
		$form->addSubmits();
		
		$form->onSuccess[] = function (AdminForm $form): void {
			$values = $form->getValues('array');
			
			$this->invoiceRepository->createFromOrder($this->orderRepository->one($values['order']));
			
			$this->flashMessage('Uloženo', 'success');
			
			$form->processRedirect('this', 'default');
		};
		
		return $form;
	}
	
	public function renderDefault(): void
	{
		$this->template->headerLabel = 'Faktury';
		$this->template->headerTree = [
			['Faktury', 'default'],
		];
		$this->template->displayButtons = [$this->createNewItemButton('new')];
		$this->template->displayControls = [$this->getComponent('grid')];
	}
	
	public function renderNew(): void
	{
		$this->template->headerLabel = 'Nová faktura';
		$this->template->headerTree = [
			['Faktury', 'default'],
			['Nová faktura'],
		];
		$this->template->displayButtons = [$this->createBackButton('default')];
		$this->template->displayControls = [$this->getComponent('form')];
		$this->template->activeTab = 'default';
	}
	
	public function renderDetail(Invoice $invoice): void
	{
		unset($invoice);
		
		$this->template->headerLabel = 'Detail';
		$this->template->headerTree = [
			['Faktury', 'default'],
			['Detail'],
		];
		$this->template->displayButtons = [$this->createBackButton('default')];
		$this->template->displayControls = [$this->getComponent('form')];
	}
	
	public function actionDetail(Invoice $invoice): void
	{
		/** @var \Forms\Form $form */
		$form = $this->getComponent('form');
		
		$form->setDefaults($invoice->toArray());
	}
}
