<?php

declare(strict_types=1);

namespace Eshop\Admin;

use Admin\BackendPresenter;
use Admin\Controls\AdminForm;
use Admin\Controls\AdminGrid;
use Eshop\DB\AttributeCategoryRepository;
use Eshop\DB\Category;
use Eshop\DB\CategoryRepository;
use Eshop\DB\CategoryType;
use Eshop\DB\CategoryTypeRepository;
use Eshop\DB\ParameterCategoryRepository;
use Forms\Form;
use Nette\Http\Request;
use Nette\Utils\Arrays;
use Nette\Utils\Image;
use Nette\Utils\Random;
use Pages\DB\PageRepository;
use Pages\Helpers;
use StORM\DIConnection;
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
	public ParameterCategoryRepository $parameterCategoryRepository;

	/** @inject */
	public CategoryTypeRepository $categoryTypeRepository;

	private array $tabs = [];

	/** @persistent */
	public string $tab = 'none';

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

//		$grid->addColumn('Kategorie parametrů', function (Category $object, $datagrid) {
//			$string = '';
//
//			if ($parameterCategories = $this->categoryRepository->getParameterCategoriesOfCategory($object)) {
//				foreach ($parameterCategories as $parameterCategory) {
//					$link = $this->admin->isAllowed(':Eshop:Admin:Parameter:parameterCategoryDetail') ?
//						$datagrid->getPresenter()->link(':Eshop:Admin:Parameter:parameterCategoryDetail', [$parameterCategory, 'backLink' => $this->storeRequest()]) : '#';
//
//					$string .= "<a href='$link'>" . $parameterCategory->name . "</a>, ";
//				}
//			}
//
//			return \substr($string, 0, -2);
//		}, '%s');

//		$grid->addColumn('Kategorie atributů', function (Category $object, $datagrid) {
//			$string = '';
//
//			if ($parameterCategories = $this->categoryRepository->getAttributeCategoriesOfCategory($object)) {
//				foreach ($parameterCategories as $parameterCategory) {
//					$link = $this->admin->isAllowed(':Eshop:Admin:Attribute:categoryDetail') ?
//						$datagrid->getPresenter()->link(':Eshop:Admin:Attribute:categoryDetail', [$parameterCategory, 'backLink' => $this->storeRequest()]) : '#';
//
//					$string .= "<a href='$link'>" . $parameterCategory->name . "</a>, ";
//				}
//			}
//
//			return \substr($string, 0, -2);
//		}, '%s');

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

	public function createComponentCategoryNewForm(): Form
	{
		$form = $this->formFactory->create(true);

		$form->addText('code', 'Kód');

		$imagePicker = $form->addImagePicker('imageFileName', 'Obrázek', [
			Category::IMAGE_DIR . \DIRECTORY_SEPARATOR . 'origin' => null,
			Category::IMAGE_DIR . \DIRECTORY_SEPARATOR . 'detail' => static function (Image $image): void {
				$image->resize(600, null);
			},
			Category::IMAGE_DIR . \DIRECTORY_SEPARATOR . 'thumb' => static function (Image $image): void {
				$image->resize(300, null);
			},
		]);

		$category = $this->getParameter('category');

		$imagePicker->onDelete[] = function (array $directories, $filename) use ($category) {
			$this->onDeleteImage($category);
			$this->redirect('this');
		};

		$imagePicker = $form->addImagePicker('productFallbackImageFileName', 'Placeholder produktů', [
			Category::IMAGE_DIR . \DIRECTORY_SEPARATOR . 'origin' => null,
			Category::IMAGE_DIR . \DIRECTORY_SEPARATOR . 'detail' => static function (Image $image): void {
				$image->resize(600, null);
			},
			Category::IMAGE_DIR . \DIRECTORY_SEPARATOR . 'thumb' => static function (Image $image): void {
				$image->resize(300, null);
			},
		]);

		$imagePicker->onDelete[] = function (array $directories, $filename) use ($category) {
			$this->onDeleteImage($category, 'productFallbackImageFileName');
			$this->redirect('this');
		};

		$nameInput = $form->addLocaleText('name', 'Název');
		$form->addLocalePerexEdit('perex', 'Perex');
		$form->addLocaleRichEdit('content', 'Obsah');

		$categories = $this->categoryRepository->getTreeArrayForSelect(false, $this->tab);

		if ($this->getParameter('category')) {
			unset($categories[$this->getParameter('category')->getPK()]);
		}

		$form->addDataSelect('ancestor', 'Nadřazená kategorie', $categories)->setPrompt('Žádná');
//		$form->addDataMultiSelect('parameterCategories', 'Kategorie parametrů', $this->parameterCategoryRepository->getArrayForSelect())
//			->setHtmlAttribute('data-info', '&nbsp;Pokud nebude kategorie parametrů nastavena, bude získána kategorie parametrů z nadřazené kategorie.');
//		$form->addDataMultiSelect('attributeCategories', 'Kategorie atributů', $this->attributeCategoryRepository->getArrayForSelect())
//			->setHtmlAttribute('data-info', '&nbsp;Pokud nebude kategorie atributů nastavena, bude získána kategorie atributů z nadřazené kategorie.');
		$form->addText('exportGoogleCategory', 'Exportní název pro Google');
		$form->addText('exportHeurekaCategory', 'Export název pro Heuréku');
		$form->addText('exportZboziCategory', 'Export název pro Zbozi');
		$form->addInteger('priority', 'Priorita')->setDefaultValue(10)->setRequired();
		$form->addCheckbox('hidden', 'Skryto');
		$form->addCheckbox('recommended', 'Doporučeno');

		$form->addPageContainer('product_list', ['category' => $this->getParameter('category')], $nameInput);

		$form->addSubmits(!$category);

		$form->onSuccess[] = function (AdminForm $form) {
			$values = $form->getValues('array');

			$this->createImageDirs(Category::IMAGE_DIR);

			if (!$values['uuid']) {
				$values['uuid'] = DIConnection::generateUuid();
				$values['type'] = $this->tab;
			}

			$values['imageFileName'] = $form['imageFileName']->upload($values['uuid'] . '.%2$s');
			$values['productFallbackImageFileName'] = $form['productFallbackImageFileName']->upload($values['uuid'] . '_fallback.%2$s');

			$prefix = $values['ancestor'] ? $this->categoryRepository->one($values['ancestor'])->path : '';
			$random = null;

			do {
				$random = $prefix . Random::generate(4, '0-9a-z');
				$tempCategory = $this->categoryRepository->many()->where('path', $random)->first();
			} while ($tempCategory);

			$values['path'] = $random;

			$category = $this->categoryRepository->syncOne($values, null, true);

			$this->categoryRepository->updateCategoryChildrenPath($category);

			$form->syncPages(function () use ($category, $values) {
				$values['page']['params'] = Helpers::serializeParameters(['category' => $category->getPK()]);
				$this->pageRepository->syncOne($values['page']);
			});

			$this->flashMessage('Uloženo', 'success');
			$form->processRedirect('detail', 'default', [$category]);
		};

		return $form;
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
			$this->template->displayButtons = [$this->createNewItemButton('categoryNew')];
			$this->template->displayControls = [$this->getComponent('categoryGrid')];
		}
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
			$this->getComponent('categoryNewForm'),
		];
	}

	public function actionDetail(Category $category, ?string $backLink = null)
	{
		/** @var Form $form */
		$form = $this->getComponent('categoryNewForm');
		$form->setDefaults($category->toArray(['parameterCategories']));
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
}