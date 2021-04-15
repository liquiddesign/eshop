<?php

declare(strict_types=1);

namespace Eshop\Admin;

use Admin\BackendPresenter;
use Eshop\FormValidators;
use Admin\Controls\AdminForm;
use Admin\Controls\AdminGrid;
use Eshop\DB\Amount;
use Eshop\DB\AmountRepository;
use Eshop\DB\ProductRepository;
use Eshop\DB\Store;
use Eshop\DB\StoreRepository;
use Eshop\DB\SupplierRepository;
use Forms\Form;

class StorePresenter extends BackendPresenter
{
	/** @inject */
	public StoreRepository $storeRepository;

	/** @inject */
	public SupplierRepository $supplierRepository;

	/** @inject */
	public AmountRepository $amountRepo;

	/** @inject */
	public ProductRepository $productRepo;

	public function createComponentGrid()
	{
		$grid = $this->gridFactory->create($this->storeRepository->many(), 20, 'code', 'ASC', true);
		$grid->addColumnSelector();

		$grid->addColumnText('Kód', 'code', '%s', 'code', ['class' => 'fit'])->onRenderCell[] = [$grid, 'decoratorNowrap'];
		$grid->addColumnText('Název', 'name', '%s', 'name');
		$grid->addColumn('Zdroj', function (Store $object, $datagrid) {
			$link = $this->admin->isAllowed(':Eshop:Admin:Supplier:detail') && $object->supplier ? $datagrid->getPresenter()->link(':Eshop:Admin:Supplier:detail', [$object->supplier, 'backLink' => $this->storeRequest()]) : '#';
			
			return $object->supplier ? "<a href='$link'><i class='fa fa-external-link-alt fa-sm'></i>&nbsp;" . $object->supplier->name . "</a>" : '';
		}, '%s');

		$grid->addColumnLink('amounts', 'Množství');
		$grid->addColumnLinkDetail();
		$grid->addColumnActionDelete();

		$grid->addButtonDeleteSelected();

		$grid->addFilterTextInput('search', ['name_cs', 'code'], null, 'Kód, název');

		$grid->addFilterButtons();

		return $grid;
	}

	public function createComponentNewForm(): Form
	{
		$form = $this->formFactory->create();
		
		$form->addText('code', 'Kód')->setNullable();
		$form->addLocaleText('name', 'Název');
		$form->addDataSelect('supplier', 'Zdroj', $this->supplierRepository->getArrayForSelect())->setPrompt('Nepřiřazeno');

		$form->addSubmits(!$this->getParameter('store'));

		$form->onSuccess[] = function (AdminForm $form) {
			$values = $form->getValues('array');

			$store = $this->storeRepository->syncOne($values, null, true);

			$this->flashMessage('Uloženo', 'success');

			$form->processRedirect('detail', 'default', [$store]);
		};

		return $form;
	}

	public function renderDefault()
	{
		$this->template->headerLabel = 'Sklady';
		$this->template->headerTree = [
			['Sklady'],
		];
		$this->template->displayButtons = [$this->createNewItemButton('new')];
		$this->template->displayControls = [$this->getComponent('grid')];
	}

	public function renderNew()
	{
		$this->template->headerLabel = 'Nový';
		$this->template->headerTree = [
			['Sklady', 'default'],
			['Nová položka'],
		];
		$this->template->displayButtons = [$this->createBackButton('default')];
		$this->template->displayControls = [$this->getComponent('newForm')];
	}

	public function renderDetail()
	{
		$this->template->headerLabel = 'Detail';
		$this->template->headerTree = [
			['Sklady', 'default'],
			['Detail'],
		];
		$this->template->displayButtons = [$this->createBackButton('default')];
		$this->template->displayControls = [$this->getComponent('newForm')];
	}
	
	public function actionDetail(Store $store)
	{
		/** @var Form $form */
		$form = $this->getComponent('newForm');

		$form->setDefaults($store->toArray());
	}

	public function createComponentAmountsGrid()
	{
		$grid = $this->gridFactory->create($this->amountRepo->many()->where('fk_store', $this->getParameter('store')->getPK()), 20, 'price', 'ASC', true);
		$grid->addColumnSelector();
		
		$grid->addColumnText('Kód', 'product.code', '%s');
		
		$grid->addColumn('Produkt', function (Amount $amount, AdminGrid $datagrid) {
			$link = $this->admin->isAllowed(':Eshop:Admin:Product:edit') && $amount->product ? $datagrid->getPresenter()->link(':Eshop:Admin:Product:edit', [$amount->product, 'backLink' => $this->storeRequest()]) : '#';
			
			return '<a href="' . $link . '">&nbsp;' . $amount->product->name . '</a>';
		}, '%s');
		
		$grid->addColumnInputInteger('Naskladněno', 'inStock', '', '', 'inStock', [], true);
		$grid->addColumnInputInteger('Rezervováno', 'reserved', '', '', 'reserved', []);
		$grid->addColumnInputInteger('Objednáno', 'ordered', '', '', 'ordered', []);

		$grid->addColumnActionDelete();

		$grid->addButtonSaveAll(['reserved', 'ordered'], [
			'inStock' => 'int',
			'reserved' => 'int',
			'ordered' => 'int',
		]);
		$grid->addButtonDeleteSelected();

		$grid->addFilterTextInput('search', ['product.code', 'product.name_cs'], null, 'Kód, název');
		$grid->addFilterButtons(['amounts', $this->getParameter('store')]);

		return $grid;
	}

	public function createComponentAmountForm()
	{
		$form = $this->formFactory->create();
		
		$form->addText('product', 'Produkt')
			->setHtmlAttribute('data-info', 'Zadejte kód, subkód nebo EAN')
			->addRule([FormValidators::class, 'amountProductCheck'], 'Produkt neexistuje nebo již existuje záznam pro tento produkt!', [$this->productRepo, $this->amountRepo, $this->getParameter('store')])
			->setRequired();
		
		$form->addInteger('inStock', 'Naskladněno');
		$form->addIntegerNullable('reserved', 'Rezervováno');
		$form->addIntegerNullable('ordered', 'Objednáno');
		
		$form->addHidden('store', (string) $this->getParameter('store'));
		
		$form->addSubmits();

		$form->onSuccess[] = function (AdminForm $form) {
			$values = $form->getValues();

			$values['product'] = $this->productRepo->getProductByCodeOrEAN($values['product']);
			
			$this->amountRepo->createOne($values);

			$this->flashMessage('Uloženo', 'success');
			$form->processRedirect('this', 'amounts', [$this->getParameter('store')], [$this->getParameter('store')]);

		};

		return $form;
	}

	public function renderAmountNew(Store $store)
	{
		$this->template->headerLabel = 'Nová položka';
		$this->template->headerTree = [
			['Sklady', 'default'],
			['Množství', 'amounts', $store],
			['Nová položka'],
		];
		$this->template->displayButtons = [$this->createBackButton('amounts', $store)];
		$this->template->displayControls = [$this->getComponent('amountForm')];
	}

	public function renderAmounts(Store $store)
	{
		$this->template->headerLabel = 'Skladové množství';
		$this->template->headerTree = [
			['Sklady', 'default'],
			['Množství'],
		];
		$this->template->displayButtons = [$this->createBackButton('default'), $this->createNewItemButton('amountNew', [$store])];
		$this->template->displayControls = [$this->getComponent('amountsGrid')];
	}
}