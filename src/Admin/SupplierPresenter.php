<?php

declare(strict_types=1);

namespace Eshop\Admin;

use Admin\BackendPresenter;
use Admin\Controls\AdminForm;
use Eshop\DB\PricelistRepository;
use Eshop\DB\Supplier;
use Eshop\DB\SupplierProductRepository;
use Eshop\DB\SupplierRepository;
use Eshop\DB\AddressRepository;
use Forms\Form;
use StORM\DIConnection;

class SupplierPresenter extends BackendPresenter
{
	/** @inject */
	public SupplierRepository $supplierRepository;
	
	/** @inject */
	public SupplierProductRepository $supplierProductRepository;
	
	/** @inject */
	public PricelistRepository $pricelistRepository;
	
	/** @inject */
	public AddressRepository $addressRepository;
	
	public function createComponentGrid()
	{
		$grid = $this->gridFactory->create($this->supplierRepository->many(), 20, 'name', 'ASC', true);
		$grid->addColumnSelector();
		$grid->addColumnText('Aktualizace', "updatedTs|date:'d.m.Y'", '%s', 'updatedTs', ['class' => 'fit']);
		$grid->addColumnText('Kód', 'code', '%s', 'code', ['class' => 'minimal']);
		$grid->addColumnText('Název', 'name', '%s', 'name');
		
		$grid->addColumnInputText('Priorita', 'importPriority');
		$grid->addColumnInputCheckbox('Automaticky', 'isImportActive', '', '', 'isImportActive');
		
		$grid->addColumnLink('pair', '<i class="fa fa-play"></i> Ruční import');
		$grid->addColumnLinkDetail();
		
		$grid->addFilterTextInput('search', ['name', 'code'], null, 'Název, kód');
		$grid->addFilterButtons();
		$grid->addButtonSaveAll();
		
		return $grid;
	}
	
	public function createComponentForm(): AdminForm
	{
		$form = $this->formFactory->create();
		
		$form->addGroup('Obecné');
		$form->addText('code', 'Kód');
		$form->addText('name', 'Název')->setRequired();
		
		$form->addText('productCodePrefix', 'Prefix kód produktů');
		$form->addText('defaultDisplayAmount', 'Defaultní množství');
		$form->addText('defaultDisplayDelivery', 'Defaultní doručení');
		
		
		$form->addInteger('importPriority', 'Priorita');
		$form->addInteger('importPriceRatio', 'Procentuální změna ceny');
		$form->addCheckbox('isImportActive', 'Automaticky');
		
		$form->addSubmits(!$this->getParameter('supplier'));
		
		$form->onSuccess[] = function (AdminForm $form) {
			$values = $form->getValues('array');
			
			/** @var Supplier $supplier */
			$supplier = $this->supplierRepository->syncOne($values, null, true);
			
			$this->flashMessage('Uloženo', 'success');
			$form->processRedirect('detail','default',[$supplier]);
		};
		
		return $form;
	}
	
	public function createComponentPairForm(): AdminForm
	{
		$form = $this->formFactory->create();
		
		$form->addCheckbox('only_new', 'Jen nové produkty')->setDefaultValue(false);
		
		$form->addSubmit('submit', 'Potvrdit');
		
		$form->onSuccess[] = function (AdminForm $form) {
			$values = $form->getValues('array');
			
			/** @var \Eshop\DB\Supplier $supplier */
			$supplier = $this->getParameter('supplier');
			
			$currency = 'CZK';
			$mutation = 'cs';
			$country = 'CZ';
			
			$this->supplierProductRepository->syncProducts($supplier, $mutation, $country, !$values['only_new']);
			
			$pricelist = $this->pricelistRepository->syncOne([
				'uuid' => DIConnection::generateUuid($supplier->getPK(), 'available'),
				'code' => $supplier->code . '2',
				'name' => $supplier->name,
				'isActive' => true,
				'currency' => $currency,
				'country' => $country,
				'supplier' => $supplier,
				'priority' => 3,
			], ['currency', 'country']);
			
			$this->supplierProductRepository->syncPrices($this->supplierProductRepository->many()->where('fk_supplier', $supplier)->where('unavailable', false), $supplier, $pricelist);
			
			$pricelist = $this->pricelistRepository->syncOne([
				'uuid' => DIConnection::generateUuid($supplier->getPK(), 'unavailable'),
				'code' => $supplier->code . '1',
				'name' => $supplier->name . " (Nedostupné)",
				'isActive' => true,
				'currency' => $currency,
				'country' => $country,
				'supplier' => $supplier,
				'priority' => 4,
			], ['currency', 'country']);
			
			$this->supplierProductRepository->syncPrices($this->supplierProductRepository->many()->where('fk_supplier', $supplier)->where('unavailable', true), $supplier, $pricelist);
			
			$pricelist = $this->pricelistRepository->syncOne([
				'uuid' => DIConnection::generateUuid($supplier->getPK(), 'purchase'),
				'code' => $supplier->code . '0',
				'name' => $supplier->name . " (Nákupní)",
				'isActive' => false,
				'currency' => $currency,
				'country' => $country,
				'supplier' => $supplier,
				'priority' => 9,
			], ['currency', 'country']);
			
			$this->supplierProductRepository->syncPrices($this->supplierProductRepository->many()->where('fk_supplier', $supplier)->where('unavailable', true), $supplier, $pricelist, 'purchasePrice');
			
			$this->flashMessage('Uloženo', 'success');
			$form->getPresenter()->redirect('default');
		};
		
		return $form;
	}
	
	public function actionPair(Supplier $supplier)
	{
		/** @var \Admin\Controls\AdminForm $form */
		$form = $this->getComponent('pairForm');
		
		$form->setDefaults($supplier->toArray());
	}
	
	public function renderPair(Supplier $supplier)
	{
		$this->template->headerLabel = 'Import';
		$this->template->headerTree = [
			['Přehled zdrojů', 'default'],
			['Import'],
		];
		$this->template->displayButtons = [];
		$this->template->displayControls = [$this->getComponent('pairForm')];
	}
	
	public function renderDefault()
	{
		$this->template->headerLabel = 'Přehled zdrojů';
		$this->template->headerTree = [
			['Přehled zdrojů', 'default'],
		];
		$this->template->displayButtons = [];
		$this->template->displayControls = [$this->getComponent('grid')];
	}
	
	public function renderDetail(Supplier $supplier)
	{
		$this->template->headerLabel = 'Detail zdroje';
		$this->template->headerTree = [
			['Detail zdroje', 'default'],
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
		
		$form->setDefaults($supplier->toArray());
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
			['Zdroje', 'default'],
			['Import'],
		];
		$this->template->displayButtons = [$this->createBackButton('default')];
		$this->template->displayControls = [$this->getComponent('importForm')];
	}
}
