<?php
declare(strict_types=1);

namespace Eshop\Admin;

use App\Admin\Controls\AdminForm;
use App\Admin\Controls\AdminFormFactory;
use App\Admin\Controls\CustomValidators;
use App\Admin\Controls\AdminGrid;
use App\Admin\PresenterTrait;
use Eshop\DB\PricelistRepository;
use Eshop\DB\ProducerRepository;
use Eshop\DB\ProductRepository;
use Eshop\DB\Supplier;
use Eshop\DB\SupplierProduct;
use Eshop\DB\SupplierProductRepository;
use Eshop\DB\SupplierRepository;
use Forms\Form;
use Grid\Datagrid;
use Nette\Forms\Controls\Button;
use StORM\DIConnection;
use StORM\ICollection;
use StORM\InsertResult;
use StORM\Literal;
use StORM\Repository;

class SupplierProductPresenter extends \Nette\Application\UI\Presenter
{
	use PresenterTrait;
	
	/** @persistent */
	public string $tab = 'atc';
	
	/** @inject */
	public SupplierProductRepository $supplierProductRepository;
	
	/** @inject */
	public PricelistRepository $pricelistRepository;
	
	/** @inject */
	public ProductRepository $productRepository;
	
	/** @inject */
	public ProducerRepository $producerRepository;
	
	/** @inject */
	public SupplierRepository $supplierRepository;
	
	public function createComponentGrid()
	{
		$grid = $this->gridFactory->create($this->supplierProductRepository->many()->where('fk_supplier', $this->tab), 20, 'createdTs', 'ASC', true);
		$grid->addColumnSelector();
		$grid->addColumnText('Aktualizace', "updatedTs|date:'d.m.Y'", '%s', 'updatedTs', ['class' => 'fit']);
		$grid->addColumn('Párovat podle', function (SupplierProduct $product) {
			return $product->productCode ? ('Kód: ' . $product->getProductFullCode()) : ($product->ean ? ('EAN: ' . $product->ean) : '-');
		}, '%s', 'updatedTs', ['class' => 'fit'])->onRenderCell[] = [$grid, 'decoratorNowrap'];
		
		$grid->addColumnText('Název', "name", '%s', 'updatedTs');
		$grid->addColumnText('Výrobce', "producer.name", '%s', 'updatedTs');
		$grid->addColumnText('Kategorie', "category.name", '%s', 'updatedTs');
		
		$grid->addColumn('Katalog', function (SupplierProduct $supplierProduct, AdminGrid $datagrid) {
			$link = $supplierProduct->product && $this->admin->isAllowed(':Eshop:Admin:Product:edit') ? $datagrid->getPresenter()->link(':Eshop:Admin:Product:edit', [$supplierProduct->product, 'backLink' => $this->storeRequest(),]) : '#';
			
			return $supplierProduct->product ? "<a href='$link'>ano</a>" : "-";
		}, '%s', 'product');
		
		
		$grid->addColumnInputCheckbox('<span title="Aktivní">Aktvní</span>', 'active', 'active');
		
		$grid->addColumnLinkDetail('Detail');
		
		$grid->addButtonSaveAll();
		
		$grid->addFilterTextInput('search', ['ean', 'code'], null, 'EAN, kód');
		$grid->addFilterTextInput('q', ['name_cs'], null, 'Název produktu');
		
		$grid->addFilterCheckboxInput('notmapped', "fk_product IS NULL", 'Nenapárované');
		
		$grid->addButtonBulkEdit('form', ['active']);
		
		$grid->addFilterButtons();
		
		return $grid;
	}
	
	public function createComponentForm(): AdminForm
	{
		$form = $this->formFactory->create();
		$form->addText('productFullCode', 'Párovat k produktu')
			->setHtmlAttribute('data-info', 'Zadejte kód, subkód nebo EAN')
			->addRule(CustomValidators::IS_PRODUCT_EXISTS, 'Produkt neexistuje!', [
				$this->productRepository,
				$this->supplierProductRepository,
				$form
			])
			->setNullable();
		$form->addCheckbox('active', 'Aktivní');
		
		$form->addSubmits(false, false);
		
		$form->onSuccess[] = function (AdminForm $form) {
			$values = $form->getValues('array');
			
			$parsed = \explode('.', $values['productFullCode']);
			$values['productCode'] = $parsed[0] ?? null;
			$values['productSubCode'] = $parsed[1] ?? null;
			
			$supplierProduct = $this->getParameter('supplierProduct');
			
			$supplierProduct->update($values);
			
			$this->flashMessage('Uloženo', 'success');
			$form->processRedirect('detail', 'default', [$supplierProduct]);
		};
		
		return $form;
	}
	
	public function createComponentPairForm(): AdminForm
	{
		$form = $this->formFactory->create();
		
		$form->addCheckbox('overwrite', 'Přepsat nezamčené')->setDefaultValue(true);
		
		$form->addSubmit('submit', 'Spárovat');
		
		$form->onSuccess[] = function (AdminForm $form) {
			$values = $form->getValues('array');
			
			/** @var \Eshop\DB\Supplier $supplier */
			$supplier = $this->getParameter('supplier');
			
			$currency = 'CZK';
			$mutation = 'cs';
			$country = 'CZ';
			
			$this->supplierProductRepository->syncProducts($supplier, $mutation, $country, $values['overwrite']);
			
			foreach (['A'] as $type) {
				$pricelist = $this->pricelistRepository->syncOne([
					'uuid' => DIConnection::generateUuid($supplier->getPK(), $type),
					'name' => $supplier->name . " ($type)",
					'isActive' => false,
					'currency' => $currency,
					'country' => $country,
					'supplier' => $supplier,
				], ['currency', 'country']);
				
				$this->supplierProductRepository->syncPrices($supplier, $pricelist, $type);
			}
			
			$this->flashMessage('Uloženo', 'success');
			$form->getPresenter()->redirect('default');
		};
		
		return $form;
	}
	
	
	public function renderDefault()
	{
		$this->template->headerLabel = 'Dodavatelské produkty';
		$this->template->headerTree = [
			['Dodavatelské produkty'],
		];
		
		
		$this->template->tabs = [
			'atc' => 'AT Computers',
			'agem' => 'Agem.cz',
			'tc' => 'Tonercentrum.cz',
			'arles' => 'Arles.cz',
		];
		
		$supplier = new Supplier(['uuid' => $this->tab]);
		$this->template->displayButtons = [$this->createButtonWithClass('pair', '<i class="fa fa-sync"></i> Synchronizovat katalog', 'btn btn-sm btn-outline-primary', $supplier)];
		$this->template->displayControls = [$this->getComponent('grid')];
	}
	
	public function actionPair(Supplier $supplier)
	{
		/** @var \App\Admin\Controls\AdminForm $form */
		$form = $this->getComponent('pairForm');
		
		$form->setDefaults($supplier->toArray());
	}
	
	public function renderPair(Supplier $supplier)
	{
		$this->template->headerLabel = 'Aktualizovat produkty';
		$this->template->headerTree = [
			['Dodavatelské produkty', 'default'],
			['Aktualizovat produkty'],
		];
		$this->template->displayButtons = [];
		$this->template->displayControls = [$this->getComponent('pairForm')];
	}
	
	public function renderNew()
	{
		$this->template->headerLabel = 'Nová položka';
		$this->template->headerTree = [
			['Dodavatelské produkty', 'default'],
			['Nová položka'],
		];
		
		
		$this->template->displayButtons = [$this->createBackButton('default')];
		$this->template->displayControls = [$this->getComponent('form')];
	}
	
	public function renderDetail()
	{
		$this->template->headerLabel = 'Detail';
		$this->template->headerTree = [
			['Dodavatelské produkty', 'default'],
			['Detail'],
		];
		$this->template->displayButtons = [$this->createBackButton('default')];
		$this->template->displayControls = [$this->getComponent('form')];
	}
	
	public function actionDetail(SupplierProduct $supplierProduct)
	{
		/** @var Form $form */
		$form = $this->getComponent('form');
		
		$values = $supplierProduct->toArray();
		$values['product'] = $supplierProduct->getProductFullCode();
		$form->setDefaults($values);
	}
}
