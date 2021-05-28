<?php

declare(strict_types=1);

namespace Eshop\Admin;

use Admin\BackendPresenter;
use Admin\Controls\AdminForm;
use Eshop\DB\CustomerGroupRepository;
use Eshop\DB\DisplayAmountRepository;
use Eshop\DB\DisplayDeliveryRepository;
use Eshop\DB\PricelistRepository;
use Eshop\DB\ProductRepository;
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
	public DisplayAmountRepository $displayAmountRepository;
	
	/** @inject */
	public DisplayDeliveryRepository $displayDeliveryRepository;
	
	/** @inject */
	public AddressRepository $addressRepository;
	
	/** @inject */
	public ProductRepository $productRepository;
	
	/** @inject */
	public CustomerGroupRepository $customerGroupRepository;
	
	
	public function createComponentGrid()
	{
		$pricelists = $this->customerGroupRepository->one(CustomerGroupRepository::UNREGISTERED_PK, true)->defaultPricelists->toArrayOf('uuid', [], true);
		
		
		$grid = $this->gridFactory->create($this->supplierRepository->many(), 20, 'name', 'ASC', true);
		$grid->addColumnSelector();
		$grid->addColumnText('Kód', 'code', '%s', 'code', ['class' => 'minimal']);
		$grid->addColumnText('Název', 'name', '%s', 'name');
		
		$grid->addColumnInputCheckbox('Automaticky', 'isImportActive', '', '', 'isImportActive');
		
		$grid->addColumnLink('pair', '<i class="fa fa-play"></i> Ruční aktualizace');
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
		$form->addText('code', 'Kód')->setHtmlAttribute('readonly', 'readonly');
		$form->addText('name', 'Název')->setRequired();
		$form->addGroup('Defaultní hodnoty');
		$form->addText('productCodePrefix', 'Prefix kódu produktů')->setNullable()->setHtmlAttribute('readonly', 'readonly');
		$form->addSelect('defaultDisplayAmount', 'Zobrazované množství', $this->displayAmountRepository->getArrayForSelect())->setPrompt('-zvolte-');
		$form->addSelect('defaultDisplayDelivery', 'Zobrazované doručení', $this->displayDeliveryRepository->getArrayForSelect())->setPrompt('-zvolte-');
		$form->addCheckbox('defaultHiddenProduct', 'Produkty budou skryté');
		
		$form->addGroup('Nastavení importu');
		$form->addInteger('importPriority', 'Priorita');
		$form->addInteger('importPriceRatio', 'Procentuální změna ceny');
		$form->addCheckbox('splitPricelists', 'Rozdělit ceníky (dostupné / nedostupné)');
		$form->addCheckbox('isImportActive', 'Spouštět automaticky každý den');
		
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
			
			if ($supplier->splitPricelists) {
				$pricelist = $this->supplierRepository->syncPricelist($supplier, $currency, $country, '2', 3, true);
				$this->supplierProductRepository->syncPrices($this->supplierProductRepository->many()->where('fk_supplier', $supplier)->where('unavailable', false), $supplier, $pricelist);
				
				$pricelist = $this->supplierRepository->syncPricelist($supplier, $currency, $country, '1', 4, true, 'Nedostupné');
				$this->supplierProductRepository->syncPrices($this->supplierProductRepository->many()->where('fk_supplier', $supplier)->where('unavailable', true), $supplier, $pricelist);
			} else {
				$pricelist = $this->supplierRepository->syncPricelist($supplier, $currency, $country, '0', 3, true,);
				$this->supplierProductRepository->syncPrices($this->supplierProductRepository->many()->where('fk_supplier', $supplier), $supplier, $pricelist);
			}
			
			if (!$this->supplierProductRepository->many()->where('fk_supplier', $supplier)->where('purchasePrice IS NOT NULL')->isEmpty()) {
				$pricelist = $this->supplierRepository->syncPricelist($supplier, $currency, $country, '3', 3, false, 'Nákupní');
				$this->supplierProductRepository->syncPrices($this->supplierProductRepository->many()->where('fk_supplier', $supplier)->where('purchasePrice IS NOT NULL'), $supplier, $pricelist);
			}
			
			$this->supplierCategoryRepository->syncAttributeCategoryAssigns($supplier);
			
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
	
	private function formatNumber($number): string
	{
		return \number_format($number, 0, '.', ' ');
	}
}
