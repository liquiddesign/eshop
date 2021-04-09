<?php

declare(strict_types=1);

namespace Eshop\Admin;

use Admin\BackendPresenter;
use Admin\Controls\AdminForm;
use Eshop\DB\Supplier;
use Eshop\DB\SupplierRepository;
use Eshop\DB\AddressRepository;
use Forms\Form;

class SupplierPresenter extends BackendPresenter
{
	/** @inject */
	public SupplierRepository $supplierRepository;
	
	/** @inject */
	public AddressRepository $addressRepository;
	
	public function createComponentGrid()
	{
		$grid = $this->gridFactory->create($this->supplierRepository->many(), 20, 'name', 'ASC', true);
		$grid->addColumnSelector();
		
		$grid->addColumnText('Název', 'name', '%s', 'name');
		$grid->addColumnText('Telefon', 'phone', '<a href="tel:%1$s"><i class="fa fa-phone-alt"></i> %1$s</a>')->onRenderCell[] = [$grid, 'decoratorEmpty'];
		$grid->addColumnText('Email', 'email', '<a href="mailto:%1$s"><i class="far fa-envelope"></i> %1$s</a>')->onRenderCell[] = [$grid, 'decoratorEmpty'];
		$grid->addColumnLink('import', "<i class='fa fa-sm fa-cog'></i>&nbsp;Import</a>", '');
		
		$grid->addColumnLinkDetail();
		
		$grid->addColumnActionDeleteSystemic();
		
		$grid->addButtonDeleteSelected(null, false, function (Supplier $supplier) {
			return !$supplier->isSystemic();
		});
		
		$grid->addFilterTextInput('search', ['name', 'email', 'phone'], null, 'Název, email, telefon');
		$grid->addFilterButtons();
		
		return $grid;
	}
	
	public function createComponentImportForm(): AdminForm
	{
		$form = $this->formFactory->create();
		
		$form->addIntegerNullable('importPriority', 'Priorita importu');
		$form->addCheckbox('isImportActive', 'Je import aktivní?');
		
		$form->addSubmits(!$this->getParameter('supplier'));
		
		$form->onSuccess[] = function (AdminForm $form) {
			$values = $form->getValues('array');
			
			/** @var Supplier $supplier */
			$supplier = $this->getParameter('supplier');
			$supplier->update($values);

			$this->flashMessage('Uloženo','success');
			$form->processRedirect('import','default',[$supplier]);
		};
		
		return $form;
	}
	
	public function createComponentForm(): AdminForm
	{
		$form = $this->formFactory->create();
		
		$form->addGroup('Obecné');
		$form->addText('code', 'Kód');
		$form->addText('name', 'Název')->setRequired();
		$form->addText('phone', 'Telefon');
		$form->addEmail('email', 'Email');
		
		$form->addGroup('Fakturační adresa');
		$billAddress = $form->addContainer('billAddress');
		$billAddress->addHidden('uuid');
		$billAddress->addText('street', 'Ulice');
		$billAddress->addText('city', 'Město');
		$billAddress->addText('zipcode', 'PSČ');
		$billAddress->addText('state', 'Stát');
		
		$form->addGroup('Doručovací adresa');
		$deliveryAddress = $form->addContainer('deliveryAddress');
		$deliveryAddress->addHidden('uuid');
		$deliveryAddress->addText('name',' Jméno a příjmení / název firmy');
		$deliveryAddress->addText('street', 'Ulice');
		$deliveryAddress->addText('city', 'Město');
		$deliveryAddress->addText('zipcode', 'PSČ');
		$deliveryAddress->addText('state', 'Stát');
		
		$form->addSubmits(!$this->getParameter('supplier'));
		
		$form->onSuccess[] = function (AdminForm $form) {
			$values = $form->getValues('array');
			
			if (!$this->getParameter('supplier')) {
				unset($values['billAddress']['uuid'], $values['deliveryAddress']['uuid']);
			}
			
			$values['billAddress'] = $this->addressRepository->syncOne($values['billAddress']);
			$values['deliveryAddress'] = $this->addressRepository->syncOne($values['deliveryAddress']);
			
			/** @var Supplier $supplier */
			$supplier = $this->supplierRepository->syncOne($values, null, true);
			
			$this->flashMessage('Uloženo', 'success');
			$form->processRedirect('detail','default',[$supplier]);
		};
		
		return $form;
	}
	
	public function renderDefault()
	{
		$this->template->headerLabel = 'Dodavatelé';
		$this->template->headerTree = [
			['Dodavatelé', 'default'],
		];
		$this->template->displayButtons = [$this->createNewItemButton('new')];
		$this->template->displayControls = [$this->getComponent('grid')];
	}
	
	public function renderNew()
	{
		$this->template->headerLabel = 'Nový dodavatel';
		$this->template->headerTree = [
			['Dodavatelé', 'default'],
			['Nový dodavatel'],
		];
		$this->template->displayButtons = [$this->createBackButton('default')];
		$this->template->displayControls = [$this->getComponent('form')];
	}
	
	public function renderDetail(Supplier $supplier)
	{
		$this->template->headerLabel = 'Detail dodavatele';
		$this->template->headerTree = [
			['Dodavatelé', 'default'],
			['Detail'],
		];
		$this->template->displayButtons = [
			$this->createBackButton('default'),
		];
		$this->template->displayControls = [$this->getComponent('form')];
	}
	
	public function actionDetail(Supplier $supplier)
	{
		/** @var Form $form */
		$form = $this->getComponent('form');
		
		$form->setDefaults($supplier->toArray(['deliveryAddress', 'billAddress']));
	}
	
	public function actionImport(Supplier $supplier)
	{
		/** @var Form $form */
		$form = $this->getComponent('importForm');
		
		$form->setDefaults($supplier->toArray());
	}
	
	public function renderImport(Supplier $supplier)
	{
		$this->template->headerLabel = 'Import';
		$this->template->headerTree = [
			['Dodavatelé', 'default'],
			['Import'],
		];
		$this->template->displayButtons = [$this->createBackButton('default')];
		$this->template->displayControls = [$this->getComponent('importForm')];
	}
}
