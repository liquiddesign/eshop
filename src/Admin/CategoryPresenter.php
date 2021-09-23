<?php

declare(strict_types=1);

namespace Eshop\Admin;

use Admin\BackendPresenter;
use Admin\Controls\AdminForm;
use Admin\Controls\AdminGrid;
use Eshop\Admin\Controls\ICategoryFormFactory;
use Eshop\DB\Category;
use Eshop\DB\CategoryRepository;
use Eshop\DB\CategoryType;
use Eshop\DB\CategoryTypeRepository;
use Forms\Form;
use Nette\Http\Request;
use Nette\Utils\Arrays;
use Nette\Utils\FileSystem;
use Web\DB\PageRepository;
use StORM\Entity;

class CategoryPresenter extends BackendPresenter
{
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

	private array $tabs = [];

	/** @persistent */
	public string $tab = 'none';

	/** @persistent */
	public string $editTab = 'menu0';

	public function createComponentCategoryGrid()
	{
		$grid = $this->gridFactory->create($this->categoryRepository->many()->where('this.fk_type', $this->tab), null,
			null, 'ASC', true);

		$grid->setNestingCallback(static function ($source, $parent) {
			if (!$parent) {
				return $source->where('LENGTH(path)=4');
			}

			return $source->where('path!=:parent AND path LIKE :path',
				['path' => $parent->path . '%', 'parent' => $parent->path]);
		});

		$grid->addColumnSelector();

		$grid->addColumnImage('imageFileName', Category::IMAGE_DIR);
		$grid->addColumnText('Kód', 'code', '%s', 'code', ['class' => 'minimal']);

		$grid->addColumn('Název', function (Category $category, $grid) {
			return [
				$grid->getPresenter()->link(':Eshop:Product:list', ['category' => (string)$category]),
				$category->name
			];
		}, '<a href="%s" target="_blank"> %s</a>', 'name')->onRenderCell[] = function (\Nette\Utils\Html $td, Category $object) {
			$level = \strlen($object->path) / 4 - 1;
			$td->setHtml(\str_repeat('- - ', $level) . $td->getHtml());
		};

		$grid->addColumnInputInteger('Priorita', 'priority', '', '', 'this.priority', [], true);
		$grid->addColumnInputCheckbox('<i title="Doporučeno" class="far fa-thumbs-up"></i>', 'recommended', '', '', 'recommended');
		$grid->addColumnInputCheckbox('<i title="Skryto" class="far fa-eye-slash"></i>', 'hidden', '', '', 'hidden');

		$grid->addColumnLinkDetail('Detail');
		$grid->addColumnActionDeleteSystemic();

		$grid->addButtonSaveAll();
		$grid->addButtonDeleteSelected(null, true, function ($object) {
			if ($object) {
				return !$object->isSystemic();
			}

			return false;
		});

		$grid->addButtonBulkEdit('categoryNewForm', ['exportGoogleCategory', 'exportHeurekaCategory', 'exportZboziCategory'], 'categoryGrid');

		$grid->addFilterTextInput('search', ['code', 'name_cs'], null, 'Kód, Název');
		$grid->addFilterButtons();

		$grid->onDelete[] = function (Category $object) {
			foreach ($this->categoryRepository->many()->where('path LIKE :q', ['q' => "$object->path%"])->toArray() as $subCategory) {
				$this->onDeleteImage($subCategory);
				$this->onDeletePage($subCategory);
				$subCategory->delete();
			}

			$this->categoryRepository->clearCategoriesCache();
		};

		return $grid;
	}

	protected function onDeletePage(Entity $object)
	{
		if ($page = $this->pageRepository->getPageByTypeAndParams('product_list', null, ['category' => $object])) {
			$page->delete();
		}
	}

	public function createComponentCategoryForm(): Controls\CategoryForm
	{
		return $this->categoryFormFactory->create($this->getParameter('category'));
	}

	public function actionDefault()
	{
		$this->tabs = $this->categoryTypeRepository->getArrayForSelect();
		$this->tabs['types'] = '<i class="fa fa-bars"></i> Typy';

		if ($this->tab == 'none') {
			$this->tab = \count($this->tabs) > 1 ? Arrays::first(\array_keys($this->tabs)) : 'types';
		}
	}

	public function renderDefault()
	{
		$this->template->tabs = $this->tabs;

		if ($this->tab == 'types') {
			$this->template->headerLabel = 'Typy kategorií';
			$this->template->headerTree = [
				['Kategorie', 'default'],
				['Typy']
			];
			$this->template->displayButtons = [$this->createNewItemButton('categoryTypeNew')];
			$this->template->displayControls = [$this->getComponent('categoryTypeGrid')];
		} else {
			$this->template->headerLabel = 'Kategorie - ' . $this->tabs[$this->tab];
			$this->template->headerTree = [
				['Kategorie', 'default'],
			];
			$this->template->displayButtons = [
				$this->createNewItemButton('categoryNew'),
				$this->createButtonWithClass('generateCategoryProducerPages!', '<i class="fa fa-sync"></i>  Generovat stránky výrobců', 'btn btn-sm btn-outline-primary')
			];
			$this->template->displayControls = [$this->getComponent('categoryGrid')];
		}
	}

	public function handleGenerateCategoryProducerPages()
	{
		try {
			$this->categoryRepository->generateCategoryProducerPages();
			$this->flashMessage('Provedeno', 'success');
		} catch (\Throwable $exception) {
			$this->flashMessage('Chyba!', 'error');
		}

		$this->redirect('this');
	}

	public function renderCategoryNew()
	{
		$this->template->headerLabel = 'Nová položka';
		$this->template->headerTree = [
			['Kategorie', 'default'],
			['Nová položka'],
		];
		$this->template->displayButtons = [$this->createBackButton('default')];
		$this->template->displayControls = [$this->getComponent('categoryNewForm')];
	}

	public function renderDetail(Category $category)
	{
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

	public function actionDetail(Category $category, ?string $backLink = null)
	{
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

		$grid->onDelete[] = function (CategoryType $object) {
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

		$form->onSuccess[] = function (AdminForm $form) {
			$values = $form->getValues('array');

			$categoryType = $this->categoryTypeRepository->syncOne($values);

			$this->flashMessage('Uloženo', 'success');
			$form->processRedirect('categoryTypeDetail', 'default', [$categoryType]);
		};

		return $form;
	}

	public function actionCategoryTypeNew()
	{
	}

	public function renderCategoryTypeNew()
	{
		$this->template->headerLabel = 'Nový typ kategorie';
		$this->template->headerTree = [
			['Kategorie', 'default'],
			['Typy']
		];
		$this->template->displayButtons = [$this->createBackButton('default')];
		$this->template->displayControls = [$this->getComponent('categoryTypeForm')];
	}

	public function actionCategoryTypeDetail(CategoryType $categoryType)
	{
		/** @var Form $form */
		$form = $this->getComponent('categoryTypeForm');
		$form->setDefaults($categoryType->toArray());
	}

	public function renderCategoryTypeDetail(CategoryType $categoryType)
	{
		$this->template->headerLabel = 'Detail';
		$this->template->headerTree = [
			['Kategorie', 'default'],
			['Typy', 'default'],
			['Detail']
		];
		$this->template->displayButtons = [$this->createBackButton('default')];
		$this->template->displayControls = [$this->getComponent('categoryTypeForm')];
	}

	public function createImageDirs(string $dir)
	{
		$subDirs = ['origin', 'detail', 'thumb'];
		$rootDir = $this->container->parameters['wwwDir'] . \DIRECTORY_SEPARATOR . 'userfiles' . \DIRECTORY_SEPARATOR . $dir;
		FileSystem::createDir($rootDir);

		foreach ($subDirs as $subDir) {
			FileSystem::createDir($rootDir . \DIRECTORY_SEPARATOR . $subDir);
		}
	}
}