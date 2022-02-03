<?php

declare(strict_types=1);

namespace Eshop\Admin;

use Admin\BackendPresenter;
use Admin\Controls\AdminForm;
use Admin\Controls\AdminGrid;
use Eshop\DB\CustomerGroupRepository;
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
use Eshop\FormValidators;
use Grid\Datagrid;
use Nette\Utils\Validators;

class SupplierPresenter extends BackendPresenter
{
	protected const SUPPLIER_TYPES = [
		\Eshop\Providers\HeurekaProvider::class => 'Heuréka',
	];

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
	public StoreRepository $storeRepository;
	
	/** @inject */
	public DisplayDeliveryRepository $displayDeliveryRepository;
	
	/** @inject */
	public DisplayAmountRepository $displayAmountRepository;
	
	/** @inject */
	public ProductRepository $productRepository;
	
	/** @inject */
	public CustomerGroupRepository $customerGroupRepository;
	
	public function beforeRender(): void
	{
		parent::beforeRender();
		
		$this->template->tabs = [
			'@default' => 'Zdroje',
			'@history' => 'Historie importů a zápisů',
		];
	}
	
	public function createComponentSupplierGrid(): AdminGrid
	{
		$grid = $this->gridFactory->create($this->supplierRepository->many(), 20, 'name', 'ASC', true);
		$grid->addColumnSelector();
		$grid->addColumnText('Kód', 'code', '%s', 'code', ['class' => 'minimal']);
		$grid->addColumnText('Název', 'name', '%s', 'name');
		
		//$grid->addColumnInputCheckbox('Automaticky', 'isImportActive', '', '', 'isImportActive');
		
		$grid->addColumnLink('pair', '<i class="fa fa-play"></i> Zapsat do katalogu');
		$grid->addColumnLinkDetail();
		
		$grid->addFilterTextInput('search', ['name', 'code'], null, 'Název, kód');
		$grid->addFilterButtons();
		$grid->addButtonSaveAll();
		
		return $grid;
	}
	
	public function createComponentHistoryGrid(): AdminGrid
	{
		$grid = $this->gridFactory->create($this->importResultRepository->many(), 20, 'startedTs', 'DESC', true);
		$grid->addColumnSelector();
		$grid->addColumn('', function (ImportResult $object, Datagrid $datagrid) {
			unset($datagrid);

			if ($object->status === 'error') {
				$color = 'danger';
			} elseif ($object->status === 'ok') {
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
				return '<span style="color: red;">Import zastaven, nastala chyba <i style="color: #dc3545;" title="' . $object->errorMessage . '" class="fa fa-info-circle"></i></span>';
			}

			if ($object->status === 'ok') {
				switch ($object->type) {
					case 'import':
						return 'Import doběhl v pořádku';
					case 'importAmount':
						return 'Import dosupností a skladů doběhl v pořádku';
					default:
						return 'Zápis do katalogu doběhl v pořádku';
				}
			}

			return $object->type === 'import' ? 'Import běží / nedokončen' : 'Zápis do katalogu běží / nedokončen';
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

	public function createComponentNewForm(): AdminForm
	{
		$form = $this->formFactory->create();

		$form->addGroup('Obecné');
		$form->addText('code', 'Kód')->setRequired();
		$form->addText('name', 'Název')->setRequired();
		$form->addSelect('providerClass', 'Typ', $this::SUPPLIER_TYPES)->setRequired();
		$form->addText('url', 'Adresa feedu')->setRequired();
		$form->addGroup('Defaultní hodnoty');
		$form->addText('productCodePrefix', 'Prefix kódu produktů')->setNullable();
		$form->addCheckbox('showCodeWithPrefix', 'Zobrazovat kód produktu s prefixem');
		$form->addSelect('defaultDisplayAmount', 'Zobrazované množství', $this->displayAmountRepository->getArrayForSelect())->setPrompt('-zvolte-');
		$form->addSelect('defaultDisplayDelivery', 'Zobrazované doručení', $this->displayDeliveryRepository->getArrayForSelect())->setPrompt('-zvolte-');
		$form->addCheckbox('defaultHiddenProduct', 'Produkty budou skryté');
		$form->addCheckbox('pairWithAlgolia', 'Povolit párování pomocí Algolia')->setHtmlAttribute('data-info', 'Pro zobrazení je nutné mít v Integraci nastavené API.');

		$form->addGroup('Nastavení importu');
		$form->addInteger('importPriority', 'Priorita')->setRequired()->setDefaultValue(0);
		$form->addInteger('importPriceRatio', 'Procentuální změna ceny')
			->setRequired()
			->setDefaultValue(100)
			->addRule([FormValidators::class, 'isPercentNoMax'], 'Neplatná hodnota!');
		$form->addCheckbox('splitPricelists', 'Rozdělit ceníky (dostupné / nedostupné)');
		$form->addCheckbox('importImages', 'Importovat obrázky');

		$form->addSubmits(true);

		$form->onValidate[] = function (AdminForm $form): void {
			if (!$form->isValid()) {
				return;
			}

			$values = $form->getValues('array');

			if (Validators::isUrl($values['url'])) {
				return;
			}

			/** @var \Nette\Forms\Controls\TextInput $input */
			$input = $form['url'];
			$input->addError('Nesprávný formát URL!');
		};

		$form->onSuccess[] = function (AdminForm $form): void {
			$values = $form->getValues('array');

			/** @var \Eshop\DB\Supplier $supplier */
			$supplier = $this->supplierRepository->syncOne($values, null, true);

			$this->flashMessage('Uloženo', 'success');
			$form->processRedirect('detail', 'default', [$supplier]);
		};

		return $form;
	}
	
	public function createComponentForm(): AdminForm
	{
		$form = $this->formFactory->create();

		/** @var \Eshop\DB\Supplier|null $supplier */
		$supplier = $this->getParameter('supplier');

		$form->addGroup('Obecné');
		$form->addText('code', 'Kód')->setHtmlAttribute('readonly', 'readonly');
		$form->addText('name', 'Název')->setRequired();

		if ($supplier->url) {
			$form->addText('url', 'Adresa feedu')->setRequired()->setDefaultValue($supplier->url);
		}

		$form->addGroup('Defaultní hodnoty');
		$form->addText('productCodePrefix', 'Prefix kódu produktů')->setNullable()->setHtmlAttribute('readonly', 'readonly');
		$form->addCheckbox('showCodeWithPrefix', 'Zobrazovat kód produktu s prefixem');
		$form->addSelect('defaultDisplayAmount', 'Zobrazované množství', $this->displayAmountRepository->getArrayForSelect())->setPrompt('-zvolte-');
		$form->addSelect('defaultDisplayDelivery', 'Zobrazované doručení', $this->displayDeliveryRepository->getArrayForSelect())->setPrompt('-zvolte-');
		$form->addCheckbox('defaultHiddenProduct', 'Produkty budou skryté');
		$form->addCheckbox('pairWithAlgolia', 'Povolit párování pomocí Algolia')->setHtmlAttribute('data-info', 'Pro zobrazení je nutné mít v Integraci nastavené API.');
		
		$form->addGroup('Nastavení importu');
		$form->addInteger('importPriority', 'Priorita')->setRequired();
		$form->addInteger('importPriceRatio', 'Procentuální změna ceny')->setRequired();
		$form->addCheckbox('splitPricelists', 'Rozdělit ceníky (dostupné / nedostupné)');
		$form->addCheckbox('importImages', 'Importovat obrázky');
		
		$form->addSubmits(!$supplier);
		
		$form->onSuccess[] = function (AdminForm $form): void {
			$values = $form->getValues('array');
			
			/** @var \Eshop\DB\Supplier $supplier */
			$supplier = $this->supplierRepository->syncOne($values, null, true);
			
			$this->flashMessage('Uloženo', 'success');
			$form->processRedirect('detail', 'default', [$supplier]);
		};
		
		return $form;
	}
	
	public function createComponentPairForm(): AdminForm
	{
		/** @var \Eshop\DB\Supplier $supplier */
		$supplier = $this->getParameter('supplier');
		
		$form = $this->formFactory->create();
		
		$form->addCheckbox('only_new', 'Jen nové produkty')->setDefaultValue(false);
		$form->addCheckbox('allowImportImages', 'Kopírovat obrázky')->setDefaultValue($supplier->importImages);
		
		$form->addSubmit('submit', 'Potvrdit');
		
		$form->onSuccess[] = function (AdminForm $form) use ($supplier): void {
			$values = $form->getValues('array');
			
			$this->supplierRepository->catalogEntry($supplier, $this->tempDir . '/log/import', $values['only_new'], $values['allowImportImages']);
			
			$this->flashMessage('Zapsáno do katalogu', 'success');
			$form->getPresenter()->redirect('default');
		};
		
		return $form;
	}
	
	public function actionPair(Supplier $supplier): void
	{
		/** @var \Admin\Controls\AdminForm $form */
		$form = $this->getComponent('pairForm');
		
		$form->setDefaults($supplier->toArray());
	}
	
	public function renderPair(): void
	{
		$this->template->headerLabel = 'Import';
		$this->template->headerTree = [
			['Přehled zdrojů', 'default'],
			['Import'],
		];
		$this->template->displayButtons = [];
		$this->template->displayControls = [$this->getComponent('pairForm')];
	}
	
	public function actionLogItems(ImportResult $importResult): void
	{
		echo \nl2br(\file_get_contents($this->tempDir . '/log/import/' . $importResult->id . '.log'));
		
		$this->terminate();
	}
	
	public function renderDefault(): void
	{
		$this->template->headerLabel = 'Přehled zdrojů';
		$this->template->headerTree = [
			['Přehled zdrojů', 'default'],
		];
		$this->template->displayButtons = [$this->createNewItemButton('new', [], 'Přidat zdroj')];
		$this->template->displayControls = [$this->getComponent('supplierGrid')];
	}

	public function renderNew(): void
	{
		$this->template->headerLabel = 'Nová položka';
		$this->template->headerTree = [
			['Přehled zdrojů', 'default'],
			['Nová položka'],
		];
		$this->template->displayButtons = [$this->createBackButton('default')];
		$this->template->displayControls = [$this->getComponent('newForm')];
	}
	
	public function renderHistory(): void
	{
		$this->template->headerLabel = 'Historie importů a zápisů';
		$this->template->headerTree = [
			['Historie importů', 'default'],
		];
		$this->template->displayButtons = [];
		$this->template->displayControls = [$this->getComponent('historyGrid')];
	}
	
	public function renderDetail(): void
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
	
	public function actionDetail(Supplier $supplier): void
	{
		/** @var \Forms\Form $form */
		$form = $this->getComponent('form');
		
		$form->setDefaults($supplier->toArray());
	}
	
	public function actionImport(Supplier $supplier): void
	{
		/** @var \Forms\Form $form */
		$form = $this->getComponent('importForm');
		
		$form->setDefaults($supplier->toArray());
	}
	
	public function renderImport(): void
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
