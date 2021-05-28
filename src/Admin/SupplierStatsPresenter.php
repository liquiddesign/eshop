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

class SupplierStatsPresenter extends BackendPresenter
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
		$grid->addColumnText('Import', "lastImportTs|date:'d.m.Y'", '%s', null, ['class' => 'fit']);
		$grid->addColumnText('Aktualizace', "lastUpdateTs|date:'d.m.Y'", '%s', null, ['class' => 'fit']);
		
		$grid->addColumn('K dispozici', function(Supplier $supplier) {
			return $this->formatNumber($this->supplierProductRepository->many()->where('this.fk_supplier', $supplier)->enum());
		}, '%s', null, ['class' => 'fit'])->onRenderCell[] = [$grid, 'decoratorNumber'];
		
		$grid->addColumn('Mapováno', function(Supplier $supplier) {
			return $this->formatNumber($this->supplierProductRepository->many()->where('this.fk_supplier', $supplier)->where('category.fk_category IS NOT NULL')->enum());
		}, '%s', null, ['class' => 'fit'])->onRenderCell[] = [$grid, 'decoratorNumber'];
		
		$grid->addColumn('V katalogu', function(Supplier $supplier) {
			return $this->formatNumber($this->supplierProductRepository->many()->where('this.fk_supplier', $supplier)->where('fk_product IS NOT NULL')->enum());
		}, '%s', null, ['class' => 'fit'])->onRenderCell[] = [$grid, 'decoratorNumber'];
		
		$grid->addColumn('Jako zdroj', function(Supplier $supplier) {
			return $this->formatNumber($this->productRepository->many()->where('fk_supplierSource', $supplier)->enum());
		}, '%s', null, ['class' => 'fit'])->onRenderCell[] = [$grid, 'decoratorNumber'];
		
		$grid->addColumn('Viditelných', function(Supplier $supplier) use ($pricelists) {
			return $this->formatNumber($this->productRepository->many()
				->where('this.fk_supplierSource', $supplier)
				->where('hidden', false)
				->join(['prices' => 'eshop_price'], 'prices.fk_product=this.uuid')
				->where('prices.fk_pricelist', $pricelists)
				->enum());
		}, '%s', null, ['class' => 'fit'])->onRenderCell[] = [$grid, 'decoratorNumber'];
		
		
		return $grid;
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
	
	private function formatNumber($number): string
	{
		return \number_format($number, 0, '.', ' ');
	}
}
