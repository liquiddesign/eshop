<?php

namespace Eshop\Admin;

use Admin\Controls\AdminForm;
use Admin\Controls\AdminFormFactory;
use Admin\Controls\AdminGrid;
use Eshop\BackendPresenter;
use Eshop\Common\Helpers;
use Eshop\DB\CategoryRepository;
use Eshop\DB\CategoryTypeRepository;
use Eshop\DB\DisplayAmountRepository;
use Eshop\DB\InternalRibbon;
use Eshop\DB\InternalRibbonRepository;
use Eshop\DB\ProducerRepository;
use Eshop\DB\Product;
use Eshop\DB\ProductRepository;
use Eshop\DB\RibbonRepository;
use Eshop\DB\SupplierProductRepository;
use Eshop\DB\SupplierRepository;
use Eshop\DB\VisibilityList;
use Eshop\DB\VisibilityListItem;
use Eshop\DB\VisibilityListRepository;
use Eshop\Services\SettingsService;
use Forms\Form;
use Grid\Datagrid;
use League\Csv\Reader;
use League\Csv\Writer;
use Nette\Application\Attributes\Persistent;
use Nette\Application\Responses\FileResponse;
use Nette\DI\Attributes\Inject;
use Nette\Utils\FileSystem;
use Nette\Utils\Strings;
use StORM\Collection;
use StORM\Expression;
use StORM\ICollection;
use Tracy\Debugger;
use Tracy\ILogger;
use Web\DB\SettingRepository;

class VisibilityListPresenter extends BackendPresenter
{
	public const TABS = [
		'lists' => 'Seznamy',
		'items' => 'Položky',
	];

	#[Inject]
	public VisibilityListRepository $visibilityListRepository;

	#[Inject]
	public \Eshop\DB\VisibilityListItemRepository $visibilityListItemRepository;

	#[Inject]
	public CategoryTypeRepository $categoryTypeRepository;

	#[Inject]
	public CategoryRepository $categoryRepository;

	#[Inject]
	public ProductRepository $productRepository;

	#[Inject]
	public \Nette\Application\Application $application;

	#[Inject]
	public SettingRepository $settingRepository;

	#[Inject]
	public SettingsService $settingsService;

	#[Inject]
	public ProducerRepository $producerRepository;

	#[Inject]
	public RibbonRepository $ribbonRepository;

	#[Inject]
	public InternalRibbonRepository $internalRibbonRepository;

	#[Inject]
	public SupplierRepository $supplierRepository;

	#[Inject]
	public SupplierProductRepository $supplierProductRepository;

	#[Inject]
	public DisplayAmountRepository $displayAmountRepository;

	#[Persistent]
	public string $tab = 'lists';

	public function createComponentListsGrid(): AdminGrid
	{
		$collection = $this->visibilityListRepository->many();

		$grid = $this->gridFactory->create($collection, 20, 'priority', 'ASC', true);
		$grid->addColumnSelector();

		$grid->addColumnText('Kód', 'code', '%s', 'code');
		$grid->addColumnText('Název', 'name', '%s', 'name');

		$grid->addColumnInputInteger('Priorita', 'priority', '', '', 'priority', [], true);
		$grid->addColumnInputCheckbox('<i title="Skryto" class="far fa-eye-slash"></i>', 'hidden', '', '', 'hidden');

		$grid->addColumnLinkDetail('listDetail');
		$grid->addColumnActionDeleteSystemic();

		$grid->addButtonSaveAll();
		$grid->addButtonDeleteSelected(condition: function (VisibilityList $systemicEntity) {
			return !$systemicEntity->isSystemic();
		});

		$grid->addFilterTextInput('search', ['name'], null, 'Název');
		$grid->addFilterButtons();

		return $grid;
	}

	public function createComponentItemsGrid(): AdminGrid
	{
		$collection = $this->visibilityListItemRepository->many()
			->join(['product' => 'eshop_product'], 'this.fk_product = product.uuid');

		$grid = $this->gridFactory->create($collection, 20, 'priority', 'ASC', true, filterShops: false);
		$grid->addColumnSelector();

		$grid->addColumnText('Seznam', 'visibilityList.name', '%s', 'visibilityList.name');

		$grid->addColumnText('Vytvořeno', 'product.createdTs|date', '%s', 'product.createdTs', ['class' => 'fit']);
		$grid->addColumn('Kód', function (VisibilityListItem $visibilityListItem) {
			return $visibilityListItem->product->getFullCode();
		}, '%s', 'product.code');

		$grid->addColumn('Produkt', function (VisibilityListItem $visibilityListItem, Datagrid $datagrid) {
			$link = $this->admin->isAllowed(':Eshop:Admin:Product:edit') ? $datagrid->getPresenter()->link(
				':Eshop:Admin:Product:edit',
				[$visibilityListItem->product],
			) : '#';

			return '<a href="' . $link . '">' . $visibilityListItem->product->name . '</a>';
		}, '%s');

		$grid->addColumnInputInteger('Priorita', 'priority', '', '', 'priority', [], true);

		$grid->addColumnInputCheckbox('<i title="Doporučeno" class="far fa-thumbs-up"></i>', 'recommended', '', '', 'recommended');
		$grid->addColumnInputCheckbox('<i title="Skryto" class="far fa-eye-slash"></i>', 'hidden', '', '', 'hidden');
		$grid->addColumnInputCheckbox('<i title="Skryto v menu a vyhledávání" class="far fa-minus-square"></i>', 'hiddenInMenu', '', '', 'hiddenInMenu');
		$grid->addColumnInputCheckbox('<i title="Neprodejné" class="fas fa-ban"></i>', 'unavailable', '', '', 'unavailable');

		$grid->addColumnLinkDetail('itemDetail');
		$grid->addColumnActionDelete();

		$grid->addButtonSaveAll();
		$grid->addButtonDeleteSelected();

		$mutationSuffix = $this->visibilityListItemRepository->getConnection()->getMutationSuffix();
		$grid->addFilterTextInput('product', ["product.name$mutationSuffix", 'product.code', 'product.ean'], null, 'Produkt - Jméno, kód, ean');

		if ($categories = $this->visibilityListRepository->getArrayForSelect()) {
			$grid->addFilterDataSelect(function (Collection $source, $value): void {
				$source->where('this.fk_visibilityList', $value);
			}, '', 'list', null, $categories)->setPrompt('- Seznam -');
		}

		$categoryTypes = $this->settingsService->getCategoryMainTypes();

		if ($categoryTypes && ($categories = $this->categoryRepository->getTreeArrayForSelect(true, \array_keys($categoryTypes)))) {
			$exactCategories = $categories;
			$categories += ['0' => 'X - bez kategorie'];

			foreach ($exactCategories as $key => $value) {
				$categories += ['.' . $key => $value . ' (bez podkategorií)'];
			}

			$grid->addFilterDataSelect(function (Collection $source, $value): void {
				if (\str_starts_with($value, '.')) {
					$subSelect = $this->categoryRepository->getConnection()->rows(['eshop_product_nxn_eshop_category'])
						->where('this.fk_product = eshop_product_nxn_eshop_category.fk_product')
						->where('eshop_product_nxn_eshop_category.fk_category', Strings::substring($value, 1));

					$source->where('EXISTS (' . $subSelect->getSql() . ')', $subSelect->getVars());
				} else {
					$category = $this->categoryRepository->one($value);

					if (!$category && $value !== '0') {
						$source->where('1=0');

						return;
					}

					$source->filter(['category' => $value === '0' ? false : $category->path]);
				}
			}, '', 'category', null, $categories)->setPrompt('- Kategorie -');
		}

		if ($producers = $this->producerRepository->getArrayForSelect()) {
			$producers += ['0' => 'X - bez výrobce'];
			$grid->addFilterDataMultiSelect(function (Collection $source, $value): void {
				$source->filter(['producer' => Helpers::replaceArrayValue($value, '0', null)]);
			}, '', 'producers', null, $producers, ['placeholder' => '- Výrobci -']);
		}

		if ($ribbons = $this->ribbonRepository->getArrayForSelect()) {
			$ribbons += ['0' => 'X - bez štítků'];
			$grid->addFilterDataMultiSelect(function (Collection $source, $value): void {
				$source->filter(['ribbon' => Helpers::replaceArrayValue($value, '0', null)]);
			}, '', 'ribbons', null, $ribbons, ['placeholder' => '- Veř. štítky -']);
		}

		if ($ribbons = $this->internalRibbonRepository->getArrayForSelect(type: InternalRibbon::TYPE_PRODUCT)) {
			$ribbons += ['0' => 'X - bez štítků'];
			$grid->addFilterDataMultiSelect(function (Collection $source, $value): void {
				$source->filter(['internalRibbon' => Helpers::replaceArrayValue($value, '0', null)]);
			}, '', 'internalRibbon', null, $ribbons, ['placeholder' => '- Int. štítky -']);
		}

		$grid->addFilterDataSelect(function (ICollection $source, $value): void {
			$source->where('this.hidden', (bool) $value);
		}, '', 'hidden', null, ['1' => 'Skryté', '0' => 'Viditelné'])->setPrompt('- Viditelnost -');

		$grid->addFilterDataSelect(function (ICollection $source, $value): void {
			$source->where('this.hiddenInMenu', (bool) $value);
		}, '', 'hiddenInMenu', null, ['1' => 'Skryté', '0' => 'Viditelné'])->setPrompt('- Viditelnost v menu -');

		$grid->addFilterDataSelect(function (ICollection $source, $value): void {
			$source->where('this.recommended', (bool) $value);
		}, '', 'recommended', null, ['1' => 'Doporučené', '0' => 'Normální'])->setPrompt('- Doporučené -');

		$grid->addFilterDataSelect(function (ICollection $source, $value): void {
			$source->where('this.unavailable', (bool) $value);
		}, '', 'unavailable', null, ['1' => 'Neprodejné', '0' => 'Prodejné'])->setPrompt('- Prodejnost -');

		if ($suppliers = $this->supplierRepository->getArrayForSelect()) {
			$grid->addFilterDataMultiSelect(function (ICollection $source, $suppliers): void {
				$expression = new Expression();

				foreach ($suppliers as $supplier) {
					$expression->add('OR', 'product.fk_supplierSource=%1$s', [$supplier]);
				}

				$subSelect = $this->supplierProductRepository->getConnection()
					->rows(['eshop_supplierproduct']);

				$subSelect->setBinderName('eshop_supplierproductFilterDataMultiSelectSupplier');

				$subSelect
					->where('this.fk_product = eshop_supplierproduct.fk_product')
					->where('eshop_supplierproduct.fk_supplier', $suppliers);

				$source->where('EXISTS (' . $subSelect->getSql() . ') OR ' . $expression->getSql(), $subSelect->getVars() + $expression->getVars());
			}, '', 'suppliers', null, $suppliers, ['placeholder' => '- Zdroje -']);
		}

		$grid->addFilterDataSelect(function (ICollection $source, $value): void {
			if ($value === 'master') {
				$source->where('product.fk_masterProduct IS NULL');
			} elseif ($value === 'slave') {
				$source->where('product.fk_masterProduct IS NOT NULL');
			}
		}, '', 'merged', null, ['master' => 'Pouze master', 'slave' => 'Pouze slave'])->setPrompt('- Sloučení -');

		if ($displayAmounts = $this->displayAmountRepository->getArrayForSelect()) {
			$displayAmounts += ['0' => 'X - nepřiřazená'];
			$grid->addFilterDataMultiSelect(function (Collection $source, $value): void {
				$source->where('product.fk_displayAmount', Helpers::replaceArrayValue($value, '0', null));
			}, '', 'displayAmount', null, $displayAmounts, ['placeholder' => '- Dostupnost -']);
		}

		$grid->addFilterButtons();

		$grid->addButtonBulkEdit('itemForm', ['priority', 'hidden', 'hiddenInMenu', 'unavailable', 'recommended'], 'itemsGrid');

		$submit = $grid->getForm()->addSubmit('export', 'Exportovat ...')->setHtmlAttribute('class', 'btn btn-outline-primary btn-sm');

		$submit->onClick[] = function ($button) use ($grid): void {
			$grid->getPresenter()->redirect('export', [$grid->getSelectedIds()]);
		};

		return $grid;
	}

	public function createComponentExportItemsForm(): AdminForm
	{
		/** @var \Grid\Datagrid $grid */
		$grid = $this->getComponent('itemsGrid');

		$ids = $this->getParameter('ids') ?: [];
		$totalNo = $grid->getFilteredSource()->enum();
		$selectedNo = \count($ids);

		$form = $this->formFactory->create();
		$form->setAction($this->link('this', ['selected' => $this->getParameter('selected')]));
		$form->addRadioList('bulkType', 'Upravit', [
			'selected' => "vybrané ($selectedNo)",
			'all' => "celý výsledek ($totalNo)",
		])->setDefaultValue('selected');

		$form->addSubmit('submit', 'Exportovat');

		$form->onSuccess[] = function (AdminForm $form) use ($ids, $grid): void {
			$values = $form->getValues('array');

			/** @var \StORM\Collection $objects */
			$objects = $values['bulkType'] === 'selected' ? $this->visibilityListItemRepository->many()->where('uuid', $ids) : $grid->getFilteredSource();

			$tempFilename = \tempnam($this->tempDir, 'csv');

			$this->application->onShutdown[] = function () use ($tempFilename): void {
				try {
					FileSystem::delete($tempFilename);
				} catch (\Throwable $e) {
					Debugger::log($e, ILogger::WARNING);
				}
			};

			$this->productRepository->csvExportVisibilityListItem(Writer::createFromPath($tempFilename, 'w+'), $objects);

			$this->getPresenter()->sendResponse(new FileResponse($tempFilename, 'items.csv', 'text/csv'));
		};

		return $form;
	}

	public function createComponentListForm(): Form
	{
		$form = $this->formFactory->create();

		/** @var \Eshop\DB\VisibilityList|null $object */
		$object = $this->getParameter('object');

		$codeInput = $form->addText('code', 'Kód')
			->setRequired()
			->setHtmlAttribute('data-info', 'Kód může obsahovat pouze znaky a-z, A-Z, 0-9. Speciální znaky nejsou povoleny!');

		AdminFormFactory::addCodeValidationToInput($codeInput, $this->visibilityListRepository, $object);

		$form->addText('name', 'Název')
			->setRequired();

		$form->addInteger('priority', 'Priorita')
			->setDefaultValue(10)
			->setRequired();

		$form->addCheckbox('hidden', 'Skryto');

		$this->formFactory->addShopsContainerToAdminForm($form, false);

		$form->addSubmits(!$object);

		$form->onSuccess[] = function (AdminForm $form) use ($object): void {
			$values = $form->getValues('array');

			$object = $this->visibilityListRepository->syncOne($values, null, true, ignore: false);

			$this->flashMessage('Uloženo', 'success');
			$form->processRedirect('listDetail', 'default', [$object]);
		};

		return $form;
	}

	public function createComponentItemForm(): Form
	{
		$form = $this->formFactory->create();

		/** @var \Eshop\DB\VisibilityListItem|null $object */
		$object = $this->getParameter('object');

		$form->monitor(BackendPresenter::class, function (BackendPresenter $presenter) use ($form, $object): void {
			$form->addInteger('priority', 'Priorita')
				->setDefaultValue(10)
				->setRequired();

			$hiddenInput = $form->addCheckbox('hidden', 'Skryto');
			$hiddenInMenuInput = $form->addCheckbox('hiddenInMenu', 'Skryto v menu a vyhledávání');
			$hiddenInMenuInput->addConditionOn($hiddenInput, $form::EQUAL, false)->toggle($hiddenInMenuInput->getHtmlId() . '-toogle');

			$form->addCheckbox('unavailable', 'Neprodejné')->setHtmlAttribute('data-info', 'Znemožňuje nákup produktu.');

			$productInput = $form->addSelectAjax('product', 'Produkt', 'Zvolte produkt', Product::class);

			if ($object) {
				$this->template->select2AjaxDefaults[$productInput->getHtmlId()] = [$object->getValue('product') => $object->product->name];
			}

			$form->addSelect2('visibilityList', 'Seznam', $this->visibilityListRepository->getArrayForSelect())
				->setPrompt('- Zvolte seznam -')
				->setRequired();

			$form->addSubmits(!$object);
		});

		$form->onValidate[] = function (AdminForm $form): void {
			if (!$form->isValid()) {
				return;
			}

			$data = $this->getHttpRequest()->getPost();

			if (isset($data['product'])) {
				return;
			}

			/** @var \Nette\Forms\Controls\SelectBox $input */
			$input = $form['product'];
			$input->addError('Toto pole je povinné!');
		};

		$form->onSuccess[] = function (AdminForm $form) use ($object): void {
			$values = $form->getValuesWithAjax();

			$object = $this->visibilityListItemRepository->syncOne($values, null, true, ignore: false);

			$this->flashMessage('Uloženo', 'success');
			$form->processRedirect('itemDetail', 'default', [$object]);
		};

		return $form;
	}

	public function renderDefault(): void
	{
		$this->template->headerLabel = 'Viditelnosti';
		$this->template->headerTree = [
			['Viditelnosti', 'this',],
			[self::TABS[$this->tab]],
		];

		if ($this->tab === 'lists') {
			$this->template->displayButtons = [$this->createNewItemButton('listNew')];
			$this->template->displayControls = [$this->getComponent('listsGrid')];
		} elseif ($this->tab === 'items') {
			$this->template->displayButtons = [
				$this->createNewItemButton('itemNew'),
				$this->createButtonWithClass('import', '<i class="fas fa-file-upload"></i> Import', 'btn btn-primary btn-sm'),
			];
			$this->template->displayControls = [$this->getComponent('itemsGrid')];
		}

		$this->template->tabs = self::TABS;
	}

	public function renderImport(): void
	{
		$this->template->headerLabel = 'Importovat';
		$this->template->headerTree = [
			['Viditelnosti', 'default'],
			['Import'],
		];
		$this->template->displayButtons = [$this->createBackButton('default')];
		$this->template->displayControls = [$this->getComponent('importItemsForm')];
	}

	public function createComponentImportItemsForm(): AdminForm
	{
		$form = $this->formFactory->create();
		$form->addUpload('file', 'CSV soubor')->setRequired();

		$form->addSelect('delimiter', 'Oddělovač', [
			';' => 'Středník (;)',
			',' => 'Čárka (,)',
			'   ' => 'Tab (\t)',
			' ' => 'Mezera ( )',
			'|' => 'Pipe (|)',
		])->setHtmlAttribute('data-info', '<h5 class="mt-2">Nápověda</h5>
<b>Povinné sloupce:</b><br>
product - Kód produktu<br>
list - Kód seznamu viditelnosti<br>
hidden - Skryto<br>
hiddenInMen - Skryto v menu a vyhledávání<br>
unavailable - Neprodejné<br>
recommended - Doporučené<br>
priority - Priorita<br>
');

		$form->addSubmit('submit', 'Uložit');

		$form->onSuccess[] = function (Form $form): void {
			$values = $form->getValues('array');

			/** @var \Nette\Http\FileUpload $file */
			$file = $form->getValues('array')['file'];

			$this->visibilityListItemRepository->getConnection()->getLink()->beginTransaction();

			try {
				$result = $this->productRepository->csvImportVisibilityListItem(
					Reader::createFromString($file->getContents()),
					$values['delimiter'],
				);

				$this->productRepository->getConnection()->getLink()->commit();

				$this->flashMessage("Uloženo: {$result['imported']}\nPřeskočeno: {$result['skipped']}", 'success');
			} catch (\Throwable $e) {
				Debugger::barDump($e, ILogger::WARNING);
				$this->productRepository->getConnection()->getLink()->rollBack();

				$this->flashMessage($e->getMessage() !== '' ? $e->getMessage() : 'Import dat se nezdařil!', 'error');
			}

			$form->getPresenter()->redirect('import');
		};

		return $form;
	}

	public function renderListNew(): void
	{
		$this->template->headerLabel = 'Nový';
		$this->template->headerTree = [
			['Viditelnosti', 'default'],
			['Nový'],
		];
		$this->template->displayButtons = [$this->createBackButton('default')];
		$this->template->displayControls = [$this->getComponent('listForm')];
	}

	public function renderListDetail(): void
	{
		$this->template->headerLabel = 'Detail';
		$this->template->headerTree = [
			['Viditelnosti', 'default'],
			['Detail'],
		];
		$this->template->displayButtons = [$this->createBackButton('default')];
		$this->template->displayControls = [$this->getComponent('listForm')];
	}

	public function actionListDetail(VisibilityList $object): void
	{
		/** @var \Forms\Form $form */
		$form = $this->getComponent('listForm');
		$values = $object->toArray();
		$form->setDefaults($values);
	}

	public function renderItemNew(): void
	{
		$this->template->headerLabel = 'Nový';
		$this->template->headerTree = [
			['Viditelnosti'],
			['Položky', 'default'],
			['Nový'],
		];
		$this->template->displayButtons = [$this->createBackButton('default')];
		$this->template->displayControls = [$this->getComponent('itemForm')];
	}

	public function renderItemDetail(): void
	{
		$this->template->headerLabel = 'Detail';
		$this->template->headerTree = [
			['Viditelnosti'],
			['Položky', 'default'],
			['Detail'],
		];
		$this->template->displayButtons = [$this->createBackButton('default')];
		$this->template->displayControls = [$this->getComponent('itemForm')];
	}

	public function actionItemDetail(VisibilityListItem $object): void
	{
		/** @var \Forms\Form $form */
		$form = $this->getComponent('itemForm');
		$values = $object->toArray();
		$form->setDefaults($values);
	}

	public function renderExport(array $ids): void
	{
		unset($ids);

		$this->template->headerLabel = 'Exportovat';
		$this->template->headerTree = [
			['Viditelnosti', 'default'],
			['Export'],
		];
		$this->template->displayButtons = [$this->createBackButton('default')];
		$this->template->displayControls = [$this->getComponent('exportItemsForm')];
	}
}
