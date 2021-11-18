<?php

declare(strict_types=1);

namespace Eshop\Admin;

use Admin\Controls\AdminForm;
use Admin\Controls\AdminGrid;
use Eshop\Admin\Controls\ICategoryFormFactory;
use Eshop\BackendPresenter;
use Eshop\DB\Category;
use Eshop\DB\CategoryRepository;
use Eshop\DB\CategoryType;
use Eshop\DB\CategoryTypeRepository;
use Eshop\DB\ProducerRepository;
use Forms\Form;
use League\Csv\Writer;
use Nette\Application\Responses\FileResponse;
use Nette\Forms\Controls\TextInput;
use Nette\Http\Request;
use Nette\Utils\Arrays;
use Nette\Utils\FileSystem;
use StORM\Entity;
use Web\DB\Page;
use Web\DB\PageRepository;

class CategoryPresenter extends BackendPresenter
{
	public const PRODUCER_PAGES = 0;
	public const PRODUCER_CATEGORY = 1;

	protected const CONFIGURATION = [
		'activeProducers' => null,
		'producerPagesType' => self::PRODUCER_CATEGORY,
		'dynamicCategories' => false,
	];

	/** @inject */
	public Request $request;

	/** @inject */
	public CategoryRepository $categoryRepository;

	/** @inject */
	public PageRepository $pageRepository;

	/** @inject */
	public CategoryTypeRepository $categoryTypeRepository;

	/** @inject */
	public ICategoryFormFactory $categoryFormFactory;

	/** @inject */
	public ProducerRepository $producerRepository;

	/** @persistent */
	public string $tab = 'none';

	/** @persistent */
	public string $editTab = 'menu0';

	private ?CategoryType $categoryType;

	/**
	 * @var string[]
	 */
	private array $tabs = [];

	public function createComponentCategoryGrid(): AdminGrid
	{
		$grid = $this->gridFactory->create(
			$this->categoryRepository->many()->where('this.fk_type', $this->tab),
			null,
			'path',
			'ASC',
			true,
		);

		$grid->setNestingCallback(static function ($source, $parent) {
			if (!$parent) {
				return $source->where('LENGTH(path)=4');
			}

			return $source->where(
				'path!=:parent AND path LIKE :path',
				[
					'path' => $parent->path . '%',
					'parent' => $parent->path,
				],
			);
		});

		$grid->addColumnSelector();

		$grid->addColumnImage('imageFileName', Category::IMAGE_DIR);
		$grid->addColumnText('Kód', 'code', '%s', 'code', ['class' => 'minimal']);

		$grid->addColumn('Název', function (Category $category, $grid) {
			return [
				$grid->getPresenter()->link(':Eshop:Product:list', ['category' => (string)$category]),
				$category->name,
			];
		}, '<a href="%s" target="_blank"> %s</a>', 'name')->onRenderCell[] = function (\Nette\Utils\Html $td, Category $object): void {
			$level = \strlen($object->path) / 4 - 1;
			$td->setHtml(\str_repeat('- - ', $level) . $td->getHtml());
		};

		if ($this->categoryType && !$this->categoryType->isReadOnly()) {
			$grid->addColumnInputInteger('Priorita', 'priority', '', '', 'this.priority', [], true);
			$grid->addColumnInputCheckbox('<i title="Doporučeno" class="far fa-thumbs-up"></i>', 'recommended', '', '', 'recommended');
			$grid->addColumnInputCheckbox('<i title="Skryto" class="far fa-eye-slash"></i>', 'hidden', '', '', 'hidden');
			$grid->addColumnInputCheckbox('<i title="Zobrazit v menu" class="fas fa-bars"></i>', 'showInMenu', '', '', 'showInMenu');
		} else {
			$grid->addColumn('Priorita', function (Category $category) {
				return '<input class="form-control form-control-sm" type="number" value="' . $category->priority . '" disabled>';
			}, '%s', 'showInMenu', ['class' => 'minimal']);
			$grid->addColumn('<i title="Doporučeno" class="far fa-thumbs-up"></i>', function (Category $category) {
				return '<label><input class="form-check form-control-sm" type="checkbox" value="' . (int)$category->recommended . '" disabled></label>';
			}, '%s', 'showInMenu', ['class' => 'minimal']);
			$grid->addColumn('<i title="Skryto" class="far fa-eye-slash"></i>', function (Category $category) {
				return '<label><input class="form-check form-control-sm" type="checkbox" value="' . (int)$category->hidden . '" disabled></label>';
			}, '%s', 'showInMenu', ['class' => 'minimal']);
			$grid->addColumn('<i title="Zobrazit v menu" class="fas fa-bars"></i>', function (Category $category) {
				return '<label><input class="form-check form-control-sm" type="checkbox" value="' . (int)$category->showInMenu . '" disabled></label>';
			}, '%s', 'showInMenu', ['class' => 'minimal']);
		}

		$grid->addColumnLinkDetail('Detail');
		$grid->addColumnActionDeleteSystemic();

		if ($this->categoryType && !$this->categoryType->isReadOnly()) {
			$grid->addButtonSaveAll([], [], null, false, null, null, true, null, function (): void {
				$this->categoryRepository->clearCategoriesCache();
			});
			$grid->addButtonDeleteSelected(null, true, function ($object) {
				if ($object) {
					return !$object->isSyemic();
				}

				return false;
			}, null, function (): void {
				$this->categoryRepository->clearCategoriesCache();
			});

			$grid->addButtonBulkEdit('categoryForm', ['exportGoogleCategory', 'exportHeurekaCategory', 'exportZboziCategory'], 'categoryGrid');

			if (isset($this::CONFIGURATION['producerPagesType']) && $this::CONFIGURATION['producerPagesType'] === $this::PRODUCER_CATEGORY) {
				$submit = $grid->getForm()->addSubmit('generateProducerCategories', 'Generovat kategorie výrobců')->setHtmlAttribute('class', 'btn btn-outline-primary btn-sm');

				$submit->onClick[] = function ($button) use ($grid): void {
					$grid->getPresenter()->redirect('generateProducerCategories', [$grid->getSelectedIds()]);
				};
			}
		}

		$submit = $grid->getForm()->addSubmit('exportCategoryTree', 'Exportovat strom (CSV)')->setHtmlAttribute('class', 'btn btn-outline-primary btn-sm');

		$submit->onClick[] = function ($button) use ($grid): void {
			$grid->getPresenter()->redirect('exportCategoryTree', [$grid->getSelectedIds()]);
		};

		$grid->addFilterTextInput('search', ['code', 'name_cs'], null, 'Kód, Název');
		$grid->addFilterButtons(['default', ['categoryGrid-order' => 'path-ASC']]);

		$grid->onDelete[] = function (Category $object): void {
			foreach ($this->categoryRepository->many()->where('path LIKE :q', ['q' => "$object->path%"])->toArray() as $subCategory) {
				$this->onDeleteImage($subCategory);
				$this->onDeletePage($subCategory);
				$subCategory->delete();
			}

			$this->categoryRepository->clearCategoriesCache();
		};

		return $grid;
	}

	public function onDeletePage(Entity $object): void
	{
		/** @var \Web\DB\Page|null $page */
		$page = $this->pageRepository->getPageByTypeAndParams('product_list', null, ['category' => $object]);

		if (!$page) {
			return;
		}

		$page->delete();
	}

	public function createComponentCategoryForm(): Controls\CategoryForm
	{
		return $this->categoryFormFactory->create($this->getParameter('category'));
	}

	public function actionDefault(): void
	{
		$this->tabs = $this->categoryTypeRepository->getArrayForSelect();
		$this->tabs['types'] = '<i class="fa fa-bars"></i> Typy';

		if (isset($this::CONFIGURATION['dynamicCategories']) && $this::CONFIGURATION['dynamicCategories']) {
			$this->tabs['dynamicCategories'] = 'Dynamické kategorie';
		}

		if ($this->tab !== 'none') {
			return;
		}

		$this->tab = \count($this->tabs) > 1 ? Arrays::first(\array_keys($this->tabs)) : 'types';
	}

	public function renderDefault(): void
	{
		$this->template->tabs = $this->tabs;

		if ($this->tab === 'types') {
			$this->template->headerLabel = 'Typy kategorií';
			$this->template->headerTree = [
				['Kategorie', 'default'],
				['Typy'],
			];
			$this->template->displayButtons = [$this->createNewItemButton('categoryTypeNew')];
			$this->template->displayControls = [$this->getComponent('categoryTypeGrid')];
		} elseif ($this->tab === 'dynamicCategories') {
			$this->template->headerLabel = 'Dynamické kategorie';
			$this->template->headerTree = [
				['Kategorie', 'default'],
				['Dynamické'],
			];
			$this->template->displayButtons = [$this->createNewItemButton('dynamicCategoryNew')];
			$this->template->displayControls = [$this->getComponent('dynamicCategoriesGrid')];
		} else {
			$this->template->headerLabel = 'Kategorie - ' . $this->tabs[$this->tab];
			$this->template->headerTree = [
				['Kategorie', 'default'],
			];

			$this->template->displayButtons = [];

			if ($this->categoryType && !$this->categoryType->isReadOnly()) {
				$this->template->displayButtons[] = $this->createNewItemButton('categoryNew');
			}

			$this->template->displayButtons[] = $this->createButtonWithClass(
				'importCategoryTree',
				'<i class="fas fa-file-import"></i> Import stromu',
				'btn btn-outline-primary btn-sm',
			);

			if (isset($this::CONFIGURATION['producerPagesType']) && $this::CONFIGURATION['producerPagesType'] === $this::PRODUCER_PAGES) {
				$this->template->displayButtons[] = $this->createButtonWithClass(
					'generateCategoryProducerPages!',
					'<i class="fa fa-sync"></i>  Generovat stránky výrobců',
					'btn btn-sm btn-outline-primary',
				);
			}

			$this->template->displayControls = [$this->getComponent('categoryGrid')];
		}
	}

	public function createComponentGenerateProducerCategoriesForm(): AdminForm
	{
		/** @var \Grid\Datagrid $grid */
		$grid = $this->getComponent('categoryGrid');

		$ids = $this->getParameter('ids') ?: [];
		$totalNo = $grid->getFilteredSource()->enum();
		$selectedNo = \count($ids);

		$form = $this->formFactory->create();
		$form->setAction($this->link('this', ['selected' => $this->getParameter('selected')]));
		$form->addRadioList('bulkType', 'Generovat', [
			'selected' => "vybrané ($selectedNo)",
			'all' => "celý výsledek ($totalNo)",
		])->setDefaultValue('selected');

		$form->addCheckbox('deep', 'Párovat produkty z podkategorií?');

		$form->addSubmit('submit', 'Generovat');

		$form->onSuccess[] = function (AdminForm $form) use ($ids, $grid): void {
			$values = $form->getValues('array');

			$this->categoryRepository->generateProducerCategories($values['bulkType'] === 'selected' ? $ids : \array_keys($grid->getFilteredSource()->toArray()), $values['deep']);

			$this->flashMessage('Provedeno', 'success');
			$this->redirect('default');
		};

		return $form;
	}

	public function actionGenerateProducerCategories(array $ids): void
	{
		unset($ids);
	}

	public function renderGenerateProducerCategories(array $ids): void
	{
		unset($ids);

		$this->template->headerLabel = 'Generovat kategorie vůrobců';
		$this->template->headerTree = [
			['Kategorie', 'default'],
			['Generovat výrobce'],
		];
		$this->template->displayButtons = [$this->createBackButton('default')];
		$this->template->displayControls = [$this->getComponent('generateProducerCategoriesForm')];
	}

	public function handleGenerateCategoryProducerPages(): void
	{
		try {
			$this->categoryRepository->generateCategoryProducerPages($this::CONFIGURATION['activeProducers'] ?? null);
			$this->flashMessage('Provedeno', 'success');
		} catch (\Throwable $exception) {
			$this->flashMessage('Chyba!', 'error');
		}

		$this->redirect('this');
	}

	public function renderCategoryNew(): void
	{
		$this->template->headerLabel = 'Nová položka';
		$this->template->headerTree = [
			['Kategorie', 'default'],
			['Nová položka'],
		];
		$this->template->displayButtons = [$this->createBackButton('default')];
		$this->template->displayControls = [$this->getComponent('categoryForm')];
	}

	public function renderDetail(Category $category): void
	{
		unset($category);

		$this->template->headerLabel = 'Detail';
		$this->template->headerTree = [
			['Kategorie', 'default'],
			['Detail'],
		];
		$this->template->displayButtons = [$this->createBackButton('default')];
		$this->template->displayControls = [
			$this->getComponent('categoryForm'),
		];

		$this->template->editTab = $this->editTab;
		$this->template->setFile(__DIR__ . '/templates/category.edit.latte');
	}

	public function actionDetail(Category $category, ?string $backLink = null): void
	{
		unset($backLink);

		/** @var \Forms\Form $form */
		$form = $this->getComponent('categoryForm')['form'];
		$form->setDefaults($category->toArray());
	}

	public function createComponentCategoryTypeGrid(): AdminGrid
	{
		$grid = $this->gridFactory->create($this->categoryTypeRepository->many(), null, 'priority', 'ASC', true);
		$grid->addColumnSelector();

		$grid->addColumnText('Název', 'name', '%s', 'name');

		$grid->addColumnInputInteger('Priorita', 'priority', '', '', 'priority', [], true);
		$grid->addColumnInputCheckbox('<i title="Skryto" class="far fa-eye-slash"></i>', 'hidden', '', '', 'hidden');

		$grid->addColumnLinkDetail('categoryTypeDetail');
		$grid->addColumnActionDeleteSystemic();

		$grid->addButtonSaveAll();
		$grid->addButtonDeleteSelected(null, false, function ($object) {
			if ($object) {
				return !$object->isSystemic();
			}

			return false;
		});

		$grid->addButtonBulkEdit('categoryTypeForm', ['hidden', 'priority'], 'categoryTypeGrid');

		$grid->addFilterTextInput('search', ['name'], null, 'Název');
		$grid->addFilterButtons();

		$grid->onDelete[] = function (CategoryType $object): void {
			$this->categoryRepository->clearCategoriesCache();
		};

		return $grid;
	}

	public function createComponentCategoryTypeForm(): AdminForm
	{
		$form = $this->formFactory->create();

		$categoryType = $this->getParameter('categoryType');

		$form->addText('name', 'Název');
		$form->addInteger('priority', 'Priorita')->setDefaultValue(10)->setRequired();
		$form->addCheckbox('hidden', 'Skryto');

		$form->addSubmits(!$categoryType);

		$form->onSuccess[] = function (AdminForm $form): void {
			$values = $form->getValues('array');

			$categoryType = $this->categoryTypeRepository->syncOne($values);

			$this->flashMessage('Uloženo', 'success');
			$form->processRedirect('categoryTypeDetail', 'default', [$categoryType]);
		};

		return $form;
	}

	public function renderCategoryTypeNew(): void
	{
		$this->template->headerLabel = 'Nový typ kategorie';
		$this->template->headerTree = [
			['Kategorie', 'default'],
			['Typy'],
		];
		$this->template->displayButtons = [$this->createBackButton('default')];
		$this->template->displayControls = [$this->getComponent('categoryTypeForm')];
	}

	public function actionCategoryTypeDetail(CategoryType $categoryType): void
	{
		/** @var \Forms\Form $form */
		$form = $this->getComponent('categoryTypeForm');
		$form->setDefaults($categoryType->toArray());
	}

	public function renderCategoryTypeDetail(): void
	{
		$this->template->headerLabel = 'Detail';
		$this->template->headerTree = [
			['Kategorie', 'default'],
			['Typy', 'default'],
			['Detail'],
		];
		$this->template->displayButtons = [$this->createBackButton('default')];
		$this->template->displayControls = [$this->getComponent('categoryTypeForm')];
	}

	public function createImageDirs(string $dir): void
	{
		$subDirs = ['origin', 'detail', 'thumb'];
		$rootDir = $this->container->parameters['wwwDir'] . \DIRECTORY_SEPARATOR . 'userfiles' . \DIRECTORY_SEPARATOR . $dir;
		FileSystem::createDir($rootDir);

		foreach ($subDirs as $subDir) {
			FileSystem::createDir($rootDir . \DIRECTORY_SEPARATOR . $subDir);
		}
	}

	public function renderDynamicCategoryNew(): void
	{
		$this->template->headerLabel = 'Dynamické kategorie';
		$this->template->headerTree = [
			['Kategorie', 'default'],
			['Dynamické', 'default'],
			['Nová položka'],
		];
		$this->template->displayButtons = [$this->createBackButton('default')];
		$this->template->displayControls = [$this->getComponent('dynamicCategoryForm')];
	}

	public function actionDynamicCategoryDetail(Page $dynamicCategory): void
	{
		/** @var \Admin\Controls\AdminForm $form */
		$form = $this->getComponent('dynamicCategoryForm');

		$parameters = \array_filter($dynamicCategory->getParsedParameters(), function ($value) {
			return $value !== '' && $value !== null;
		});

		if (isset($parameters['attributeValue'])) {
			foreach (\explode(';', $parameters['attributeValue']) as $attributeValue) {
				/** @var \Eshop\DB\AttributeValue $attributeValue */
				$attributeValue = $this->attributeValueRepository->one($attributeValue);

				$this->template->select2AjaxDefaults[$form['parameters']['attributeValue']->getHtmlId()][$attributeValue->getPK()] =
					($attributeValue->attribute->name ?? $attributeValue->attribute->code) .
					' - ' .
					($attributeValue->label ?? $attributeValue->code);
			}
		}

		$form['parameters']->setDefaults($parameters);

		$defaults = $dynamicCategory->toArray();
		$form['name']->setDefaults($defaults['name'] ?? []);
		$form['content']->setDefaults($defaults['content'] ?? []);
	}

	public function renderDynamicCategoryDetail(): void
	{
		$this->template->headerLabel = 'Dynamické kategorie';
		$this->template->headerTree = [
			['Kategorie', 'default'],
			['Dynamické', 'default'],
			['Detail'],
		];
		$this->template->displayButtons = [$this->createBackButton('default')];
		$this->template->displayControls = [$this->getComponent('dynamicCategoryForm')];
	}

	public function createComponentDynamicCategoriesGrid(): AdminGrid
	{
		$collection = $this->pageRepository->many()->where('type', 'product_list')
			->where("params != ''")
			->where("params REGEXP '^(category|producer|attributeValue|tag)={1}[A-Za-z0-9]+&{1}$' = 0");

		$grid = $this->gridFactory->create($collection, 20, 'title', 'ASC', true);
		$grid->addColumnSelector();

		$grid->addColumnText('Název', 'name', '%s', 'name');
		$grid->addColumn('URL', function (Page $page) {
			$url = $this->getHttpRequest()->getUrl()->getBaseUrl() . $page->url;

			return [$url, $url];
		}, "<a href='%s' target=_blank>%s</a>");

		$grid->addColumnInputCheckbox('<i title="Offline" class="far fa-eye-slash"></i>', 'isOffline', '', '', 'isOffline');

		$grid->addColumnLinkDetail('dynamicCategoryDetail');
		$grid->addColumnActionDeleteSystemic();

		$grid->addButtonSaveAll();
		$grid->addButtonDeleteSelected(null, false, function ($object) {
			if ($object) {
				return !$object->isSystemic();
			}

			return false;
		});

		$grid->addButtonBulkEdit('dynamicCategoryDetail', ['isOffline'], 'dynamicCategoriesGrid');

		$grid->addFilterTextInput('search', ['title_cs', 'url'], null, 'Název, URL');
		$grid->addFilterButtons();

		$grid->onDelete[] = function (CategoryType $object): void {
			$this->categoryRepository->clearCategoriesCache();
		};

		return $grid;
	}

	public function createComponentDynamicCategoryForm(): AdminForm
	{
		$form = $this->formFactory->create();

		/** @var \Web\DB\Page|null $dynamicCategory */
		$dynamicCategory = $this->getParameter('dynamicCategory');

		$form->addLocaleText('name', 'Název')->forPrimary(function (TextInput $input): void {
			$input->setRequired();
		});
		$form->addLocaleRichEdit('content', 'Obsah');

		$form->addPageContainer('product_list', $dynamicCategory ? $dynamicCategory->getParsedParameters() : ['category' => null], null, false, true, false, 'URL a SEO', false, true);
		$form->addGroup('Parametry');
		$parametersContainer = $form->addContainer('parameters');

		$parametersContainer->addSelect2('category', 'Kategorie', $this->categoryRepository->getTreeArrayForSelect())->setPrompt('Nepřiřazeno');
		$parametersContainer->addSelect2('producer', 'Výrobce', $this->producerRepository->getArrayForSelect())->setPrompt('Nepřiřazeno');
		$parametersContainer->addMultiSelect2Ajax('attributeValue', $this->link('getAttributeValues!'), 'Hodnoty atributu', [], 'Nepřiřazeno');
		$parametersContainer->addText('priceFrom', 'Cena od')->addCondition($form::FILLED)->addRule($form::FLOAT);
		$parametersContainer->addText('priceTo', 'Cena do')->addCondition($form::FILLED)->addRule($form::FLOAT);
		$parametersContainer->addText('query', 'Query');

		$form->addSubmits(!$dynamicCategory);

		$form->onValidate[] = function (AdminForm $form) use ($dynamicCategory): void {
			if ($form->isValid() === false) {
				return;
			}

			$values = $form->getValues('array');
			$rawValues = $this->getHttpRequest()->getPost();

			$values['parameters']['attributeValue'] = isset($rawValues['parameters']['attributeValue']) ? \implode(';', $rawValues['parameters']['attributeValue']) : null;

			$values['page']['params'] = \array_filter($values['parameters'], function ($value) {
				return $value !== '' && $value !== null;
			});

			if (\count($values['page']['params']) < 2) {
				$form->addError('Je nutné vyplnit alespoň 2 parametry!');

				return;
			}

			/** @var \Web\DB\Page|null $page */
			$page = $this->pageRepository->getPageByTypeAndParams('product_list', null, $values['page']['params']);

			if ($page === null || ($dynamicCategory !== null && $dynamicCategory->getPK() === $page->getPK())) {
				return;
			}

			$form->addError('Stránka s danými parametry již existuje!');
		};


		$form->onSuccess[] = function (AdminForm $form): void {
			$values = $form->getValues('array');
			$rawValues = $this->getHttpRequest()->getPost();

			$values['parameters']['attributeValue'] = isset($rawValues['parameters']['attributeValue']) ? \implode(';', $rawValues['parameters']['attributeValue']) : null;

			$values['page']['name'] = Arrays::pick($values, 'name', []);
			$values['page']['content'] = Arrays::pick($values, 'content', []);
			$values['page']['type'] = 'product_list';

			$page = $this->pageRepository->syncPage($values['page'], \array_filter($values['parameters'], function ($value) {
				return $value !== '' && $value !== null;
			}));

			$this->flashMessage('Uloženo', 'success');
			$form->processRedirect('dynamicCategoryDetail', 'default', [$page]);
		};

		return $form;
	}

	public function renderImportCategoryTree(): void
	{
		$this->template->headerLabel = 'Import stromu kategorií';
		$this->template->headerTree = [
			['Kategorie', 'default'],
			['Import stromu'],
		];
		$this->template->displayButtons = [$this->createBackButton('default')];
		$this->template->displayControls = [$this->getComponent('importCategoryTreeForm')];
	}

	public function createComponentImportCategoryTreeForm(): AdminForm
	{
		$form = $this->formFactory->create();

		$formatInput = $form->addSelect('format', 'Formát importu', ['xmlHeureka' => 'XML Heuréka', 'csvHeureka' => 'CSV Heuréka']);

		$csvFilePicker = $form->addFilePicker('fileCsv', 'Soubor (CSV)')
			->setRequired()
			->addRule($form::MIME_TYPE, 'Neplatný soubor!', 'text/csv');

		$xmlFilePicker = $form->addFilePicker('fileXml', 'Soubor (XML)')
			->setRequired()
			->addRule($form::MIME_TYPE, 'Neplatný soubor!', 'text/xml');

		$csvFilePicker->setHtmlAttribute('data-info', 'Podporuje <b>pouze</b> formátování Windows a Linux (UTF-8)!');
		$xmlFilePicker->setHtmlAttribute('data-info', 'Podporuje <b>pouze</b> formátování Windows a Linux (UTF-8)!');

		$form->addSelect('delimiter', 'Oddělovač', [
			';' => 'Středník (;)',
			',' => 'Čárka (,)',
			'   ' => 'Tab (\t)',
			' ' => 'Mezera ( )',
			'|' => 'Pipe (|)',
		])->setHtmlAttribute('data-info', '<h5 class="mt-2">Nápověda</h5>
Očekává se formát kategorií dle formátu Heuréky. Tedy "Subcategory 1" atd. až po úroveň 5.
<br>
<b>Pozor!</b> Pokud pracujete se souborem na zařízeních Apple, ujistětě se, že vždy při ukládání použijete možnost uložit do formátu Windows nebo Linux (UTF-8)!');

		$formatInput->addCondition($form::EQUAL, 'xmlHeureka')
			->toggle($form['fileXml']->getHtmlId() . '-toogle');

		$formatInput->addCondition($form::EQUAL, 'csvHeureka')
			->toggle($form['fileCsv']->getHtmlId() . '-toogle')
			->toggle($form['delimiter']->getHtmlId() . '-toogle');

		$form->addSubmit('submit', 'Importovat');

		$form->onValidate[] = function (Form $form): void {
			if (!$form->isValid()) {
				return;
			}

			$values = $form->getValues('array');

			/** @var \Nette\Http\FileUpload $file */
			$file = $values['file' . ($values['format'] === 'xmlHeureka' ? 'Xml' : 'Csv')];

			if ($file->hasFile()) {
				return;
			}

			$form['file']->addError('Neplatný soubor!');
		};

		$form->onSuccess[] = function ($form): void {
			$values = $form->getValues('array');

			/** @var \Nette\Http\FileUpload $file */
			$file = $values['file' . ($values['format'] === 'xmlHeureka' ? 'Xml' : 'Csv')];

			$connection = $this->productRepository->getConnection();

			$connection->getLink()->beginTransaction();

			try {
				if ($values['format'] === 'xmlHeureka') {
					$this->categoryRepository->importHeurekaTreeXml($file->getContents(), $this->categoryType);
				} else {
					$this->categoryRepository->importTreeCsv($this->getReaderFromString($file->getContents(), $values['delimiter']), $this->categoryType);
				}

				$connection->getLink()->commit();
				$this->flashMessage('Provedeno', 'success');
			} catch (\Exception $e) {
				$connection->getLink()->rollBack();

				$this->flashMessage($e->getMessage() !== '' ? $e->getMessage() : 'Import dat se nezdařil!', 'error');
			}

			$this->redirect('this');
		};

		return $form;
	}

	public function renderExportCategoryTree(array $ids): void
	{
		unset($ids);

		$this->template->headerLabel = 'Export stromu kategorií';
		$this->template->headerTree = [
			['Kategorie', 'default'],
			['Export stromu'],
		];
		$this->template->displayButtons = [$this->createBackButton('default')];
		$this->template->displayControls = [$this->getComponent('exportCategoryTreeForm')];
	}

	public function createComponentExportCategoryTreeForm(): AdminForm
	{
		/** @var \Grid\Datagrid $grid */
		$grid = $this->getComponent('categoryGrid');

		$ids = $this->getParameter('ids') ?: [];
		$totalNo = $grid->getPaginator()->getItemCount();
		$selectedNo = \count($ids);

		$form = $this->formFactory->create();
		$form->setAction($this->link('this', ['selected' => $this->getParameter('selected')]));
		$form->addRadioList('bulkType', 'Exportovat', [
			'selected' => "vybrané ($selectedNo)",
			'all' => "celý výsledek ($totalNo)",
		])->setDefaultValue('selected');

		$form->addSelect('delimiter', 'Oddělovač', [
			';' => 'Středník (;)',
			',' => 'Čárka (,)',
			'   ' => 'Tab (\t)',
			' ' => 'Mezera ( )',
			'|' => 'Pipe (|)',
		]);

		$form->addSubmit('submit', 'Exportovat');

		$form->onSuccess[] = function (AdminForm $form) use ($ids, $grid): void {
			$values = $form->getValues('array');

			$items = $values['bulkType'] === 'selected' ? $this->categoryRepository->many()->where('this.uuid', $ids)->toArray() : $grid->getFilteredSource()->toArray();

			$tempFilename = \tempnam($this->tempDir, "csv");

			$this->categoryRepository->exportTreeCsv(
				Writer::createFromPath($tempFilename),
				$items,
			);

			$this->getPresenter()->sendResponse(new FileResponse($tempFilename, "categories.csv", 'text/csv'));
		};

		return $form;
	}

	protected function startup(): void
	{
		parent::startup();

		$this->categoryType = $this->categoryTypeRepository->one($this->tab);
	}
}
