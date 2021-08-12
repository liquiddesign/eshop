<?php

declare(strict_types=1);

namespace Eshop\Admin;

use Admin\BackendPresenter;
use Admin\Controls\AdminForm;
use Eshop\DB\AmountRepository;
use Eshop\DB\CustomerGroupRepository;
use Eshop\DB\Discount;
use Eshop\DB\DisplayAmountRepository;
use Eshop\DB\DisplayDeliveryRepository;
use Eshop\DB\ImportResult;
use Eshop\DB\ImportResultRepository;
use Eshop\DB\PricelistRepository;
use Eshop\DB\ProductRepository;
use Eshop\DB\StoreRepository;
use Eshop\DB\Supplier;
use Eshop\DB\SupplierCategoryRepository;
use Eshop\DB\SupplierProductRepository;
use Eshop\DB\SupplierRepository;
use Eshop\DB\AddressRepository;
use Forms\Form;
use Grid\Datagrid;
use StORM\Entity;

class SupplierPresenter extends BackendPresenter
{
	/** @inject */
	public SupplierRepository $supplierRepository;
	
	/** @inject */
	public ImportResultRepository $importResultRepository;

	/** @inject */
	public SupplierCategoryRepository $supplierCategoryRepository;

	/** @inject */
	public SupplierProductRepository $supplierProductRepository;

	/** @inject */
	public PricelistRepository $pricelistRepository;

	/** @inject */
	public AmountRepository $amountRepository;

	/** @inject */
	public StoreRepository $storeRepository;

	/** @inject */
	public DisplayDeliveryRepository $displayDeliveryRepository;

	/** @inject */
	public DisplayAmountRepository $displayAmountRepository;

	/** @inject */
	public AddressRepository $addressRepository;

	/** @inject */
	public ProductRepository $productRepository;

	/** @inject */
	public CustomerGroupRepository $customerGroupRepository;
	
	public function beforeRender()
	{
		parent::beforeRender();
		
		$this->template->tabs = [
			'@default' => 'Zdroje',
			'@history' => 'Historie importů',
		];
	}

	public function createComponentSupplierGrid()
	{
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
	
	public function createComponentHistoryGrid()
	{
		$grid = $this->gridFactory->create($this->importResultRepository->many(), 20, 'startedTs', 'DESC', true);
		$grid->addColumnSelector();
		$grid->addColumn('', function (ImportResult $object, Datagrid $datagrid) {
			if ($object->status === 'error') {
				$color = 'danger';
			} else if ($object->status === 'ok') {
				$color = 'success';
			} else {
				$color = 'info';
			}
			
			return '<i title="" class="fa fa-circle fa-sm text-' . $color . '">';
		}, '%s', null, ['class' => 'fit']);
		$grid->addColumnText('Zahájeno', "startedTs|date:'d.m.Y G:i'", '%s', 'startedTs', ['class' => 'minimal'])->onRenderCell[] = [$grid, 'decoratorNowrap'];
		$grid->addColumnText('Min.', "getRuntime()'", '%s', null, ['class' => 'minimal'])->onRenderCell[] = [$grid, 'decoratorNumber'];
		$grid->addColumnText('Typ', 'type', '%s', 'status', ['class' => 'minimal']);
		$grid->addColumnText('Zdroj', 'supplier.code', '%s', null, ['class' => 'minimal']);
		$grid->addColumn('Popis', function (ImportResult $object, Datagrid $datagrid) {
			if ($object->status === 'error') {
				return '<span style="color: red;">Import zastaven, nastala chyba <i style="color: #dc3545;" title="'. $object->errorMessage .'" class="fa fa-info-circle"></i></span>';
			} else if ($object->status === 'ok') {
				return $object->type === 'import' ? 'Import doběhl v pořádku' : 'Zápis do katalogu doběhl v pořádku';
			} else {
				return $object->type === 'import' ? 'Import běží / nedokončen' : 'Zápis do katalogu běží / nedokončen';
			}
			
			
		}, '%s', null);
		
		$grid->addColumnText('Nových', 'insertedCount', '%s', 'insertedCount', ['class' => 'minimal'])->onRenderCell[] = [$grid, 'decoratorNowrap'];
		$grid->addColumnText('Upravených', 'updatedCount', '%s', 'updatedCount', ['class' => 'minimal'])->onRenderCell[] = [$grid, 'decoratorNowrap'];
		$grid->addColumn('Obrázků', function (ImportResult $object, Datagrid $datagrid) {
			return $object->imageDownloadCount . ( $object->imageErrorCount ? ' (<span style="color: red;"> ' . $object->imageErrorCount . ' </span>)' : '');
		}, '%s', null, ['class' => 'minimal'])->onRenderCell[] = [$grid, 'decoratorNowrap'];
		
		$grid->addColumn('', function (ImportResult $object, Datagrid $datagrid) {
			return $datagrid->getPresenter()->link('logItems', $object);
		}, '<a class="btn btn-outline-primary btn-sm text-xs" target="_blank" style="white-space: nowrap" href="%s">Podrobný log</a>', null, ['class' => 'minimal']);

		
		$grid->addFilterTextInput('search', ['supplier.name', 'supplier.code'], null, 'Název, kód');
		$grid->addFilterButtons();
		
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
		$form->addCheckbox('importImages', 'Importovat obrázky');

		$form->addSubmits(!$this->getParameter('supplier'));

		$form->onSuccess[] = function (AdminForm $form) {
			$values = $form->getValues('array');

			/** @var Supplier $supplier */
			$supplier = $this->supplierRepository->syncOne($values, null, true);

			$this->flashMessage('Uloženo', 'success');
			$form->processRedirect('detail', 'default', [$supplier]);
		};

		return $form;
	}

	public function createComponentPairForm(): AdminForm
	{
		$form = $this->formFactory->create();

		$form->addCheckbox('only_new', 'Jen nové produkty')->setDefaultValue(false);
		$form->addCheckbox('allowImportImages', 'Importovat obrázky')->setDefaultValue(false);

		$form->addSubmit('submit', 'Potvrdit');

		$form->onSuccess[] = function (AdminForm $form) {
			$values = $form->getValues('array');

			/** @var \Eshop\DB\Supplier $supplier */
			$supplier = $this->getParameter('supplier');

			$currency = 'CZK';
			$mutation = 'cs';
			$country = 'CZ';

			$this->supplierProductRepository->syncProducts($supplier, $mutation, $country, !$values['only_new'], $values['allowImportImages']);

			$this->pricelistRepository->many()->where('fk_supplier', $supplier)->delete();

			if ($supplier->splitPricelists) {
				$pricelist = $this->supplierRepository->syncPricelist($supplier, $currency, $country, '2', 3, true);
				$this->supplierProductRepository->syncPrices($this->supplierProductRepository->many()->where('fk_supplier', $supplier)->where('amount IS NULL OR amount > 0'), $supplier, $pricelist);

				$pricelist = $this->supplierRepository->syncPricelist($supplier, $currency, $country, '1', 4, true, 'Nedostupné');
				$this->supplierProductRepository->syncPrices($this->supplierProductRepository->many()->where('fk_supplier', $supplier)->where('amount = 0'), $supplier, $pricelist);
			} else {
				$pricelist = $this->supplierRepository->syncPricelist($supplier, $currency, $country, '0', 3, true,);
				$this->supplierProductRepository->syncPrices($this->supplierProductRepository->many()->where('fk_supplier', $supplier), $supplier, $pricelist);
			}

			if (!$this->supplierProductRepository->many()->where('fk_supplier', $supplier)->where('purchasePrice IS NOT NULL')->isEmpty()) {
				$pricelist = $this->supplierRepository->syncPricelist($supplier, $currency, $country, '3', 3, false, 'Nákupní');
				$this->supplierProductRepository->syncPrices($this->supplierProductRepository->many()->where('fk_supplier', $supplier)->where('purchasePrice IS NOT NULL'), $supplier, $pricelist);
			}

			$this->storeRepository->many()->where('fk_supplier', $supplier)->delete();

			if (!$this->supplierProductRepository->many()->where('fk_supplier', $supplier)->where('amount IS NOT NULL')->isEmpty()) {
				$store = $this->supplierRepository->syncStore($supplier, $mutation);
				$this->supplierProductRepository->syncAmounts($this->supplierProductRepository->many()->where('fk_supplier', $supplier), $store);
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
	
	public function actionLogItems(ImportResult $importResult)
	{
		echo \nl2br(\file_get_contents($this->tempDir . '/log/import/' . $importResult->id . '.log'));
		
		$this->terminate();
	}

	public function renderDefault()
	{
		$this->template->headerLabel = 'Přehled zdrojů';
		$this->template->headerTree = [
			['Přehled zdrojů', 'default'],
		];
		$this->template->displayButtons = [];
		$this->template->displayControls = [$this->getComponent('supplierGrid')];
	}
	
	public function renderHistory()
	{
		$this->template->headerLabel = 'Historie importů';
		$this->template->headerTree = [
			['Historie importů', 'default'],
		];
		$this->template->displayButtons = [];
		$this->template->displayControls = [$this->getComponent('historyGrid')];
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
