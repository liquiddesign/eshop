<?php
declare(strict_types=1);

namespace Eshop\Admin;

use Admin\BackendPresenter;
use Admin\Controls\AdminForm;
use Admin\Controls\AdminGrid;
use Eshop\DB\Attribute;
use Eshop\DB\AttributeCategory;
use Eshop\DB\AttributeCategoryRepository;
use Eshop\DB\AttributeRepository;
use Eshop\DB\AttributeValueRepository;
use Forms\Form;
use StORM\DIConnection;

class AttributePresenter extends BackendPresenter
{
	/** @inject */
	public AttributeRepository $attributeRepository;

	/** @inject */
	public AttributeCategoryRepository $attributeCategoryRepository;

	/** @inject */
	public AttributeValueRepository $attributeValueRepository;

	public const TABS = [
		'attributes' => 'Atributy',
		'categories' => 'Kategorie',
	];

	/** @persistent */
	public string $tab = 'attributes';

	public function actionDefault()
	{
		$this->template->headerLabel = 'Atributy';
		$this->template->headerTree = [
			['Atributy', 'this'],
			[self::TABS[$this->tab]]
		];

		if ($this->tab == 'attributes') {
			$this->template->displayButtons = [$this->createNewItemButton('attributeNew')];
			$this->template->displayControls = [$this->getComponent('attributeGrid')];
		} elseif ($this->tab == 'categories') {
			$this->template->displayButtons = [$this->createNewItemButton('categoryNew')];
			$this->template->displayControls = [$this->getComponent('categoryGrid')];
		}

		$this->template->tabs = self::TABS;
	}

	public function createComponentAttributeGrid(): AdminGrid
	{
		$grid = $this->gridFactory->create($this->attributeRepository->many(), 20, 'priority', 'ASC', true);
		$grid->addColumnSelector();
		$grid->addColumnText('Kód', 'code', '%s', 'code');
		$grid->addColumnText('Název', 'name', '%s', 'name');
		$grid->addColumnInputInteger('Priorita', 'priority', '', '', 'priority', [], true);
		$grid->addColumnInputCheckbox('Filtrace', 'showFilter', '', '', 'showFilter');
		$grid->addColumnInputCheckbox('Náhled', 'showProduct', '', '', 'showProduct');
		$grid->addColumnInputCheckbox('<i title="Skryto" class="far fa-eye-slash"></i>', 'hidden', '', '', 'hidden');

		$grid->addColumnLinkDetail('attributeDetail');
		$grid->addColumnActionDeleteSystemic();

		$grid->addButtonSaveAll();
		$grid->addButtonDeleteSelected(null, true, function ($object) {
			if ($object) {
				return !$object->isSystemic();
			}

			return false;
		});

		$grid->addFilterTextInput('search', ['name_cs', 'code'], null, 'Kód, název');
		$grid->addFilterButtons(['default']);

		return $grid;
	}

	public function createComponentCategoryGrid(): AdminGrid
	{
		$grid = $this->gridFactory->create($this->attributeCategoryRepository->many(), 20, 'code', 'ASC', true);
		$grid->addColumnSelector();
		$grid->addColumnText('Kód', 'code', '%s', 'code');
		$grid->addColumnText('Název', 'name', '%s', 'name');

		$grid->addColumnLinkDetail('categoryDetail');
		$grid->addColumnActionDeleteSystemic();

		$grid->addButtonSaveAll();
		$grid->addButtonDeleteSelected(null, true, function ($object) {
			if ($object) {
				return !$object->isSystemic();
			}

			return false;
		});

		$grid->addFilterTextInput('search', ['name_cs', 'code'], null, 'Kód, název');
		$grid->addFilterButtons(['groupDefault']);

		return $grid;
	}

	public function createComponentAttributeForm()
	{
		$form = $this->formFactory->create(true);

		$form->addText('code', 'Kód');
		$form->addLocaleText('name', 'Název');
		$form->addDataSelect('category', 'Kategorie atributů', $this->attributeCategoryRepository->getArrayForSelect())->setRequired();

		$form->addSelect('filterType', 'Typ filtru', Attribute::FILTER_TYPES);
		$form->addCheckbox('showProduct', 'Náhled')->setHtmlAttribute('data-info', 'Parametr se zobrazí v náhledu produktu.');
		$form->addCheckbox('showFilter', 'Filtr')->setHtmlAttribute('data-info', 'Parametr se zobrazí při filtrování.');
		$form->addText('priority', 'Priorita')
			->addRule($form::INTEGER)
			->setRequired()
			->setDefaultValue(10);
		$form->addCheckbox('hidden', 'Skryto');

		$form->addSubmits(!$this->getParameter('attribute'));

		$form->onSuccess[] = function (AdminForm $form) {
			$values = $form->getValues('array');

			if (!$values['uuid']) {
				$values['uuid'] = DIConnection::generateUuid();
			}

			$object = $this->attributeRepository->syncOne($values, null, true);

			$this->flashMessage('Uloženo', 'success');
			$form->processRedirect('attributeDetail', 'default', [$object]);
		};

		return $form;
	}

	public function createComponentCategoryForm()
	{
		$form = $this->formFactory->create(true);

		$form->addText('code', 'Kód');
		$form->addLocaleText('name', 'Název');

		$form->addSubmits(!$this->getParameter('category'));

		$form->onSuccess[] = function (AdminForm $form) {
			$values = $form->getValues('array');

			if (!$values['uuid']) {
				$values['uuid'] = DIConnection::generateUuid();
			}

			$object = $this->attributeCategoryRepository->syncOne($values, null, true);

			$this->flashMessage('Uloženo', 'success');
			$form->processRedirect('categoryDetail', 'default', [$object]);
		};

		return $form;
	}

	public function actionCategoryNew()
	{
	}

	public function actionCategoryDetail(AttributeCategory $category)
	{
		/** @var Form $form */
		$form = $this->getComponent('categoryForm');

		$form->setDefaults($category->toArray());
	}

	public function actionAttributeNew()
	{
	}

	public function actionAttributeDetail(Attribute $attribute)
	{
		/** @var Form $form */
		$form = $this->getComponent('attributeForm');

		$form->setDefaults($attribute->toArray());
	}

	public function renderCategoryNew()
	{
		$this->template->headerLabel = 'Nová položka';
		$this->template->headerTree = [
			['Atributy', 'default'],
			['Nová položka'],
		];
		$this->template->displayButtons = [$this->createBackButton('default')];
		$this->template->displayControls = [$this->getComponent('categoryForm')];
	}

	public function renderCategoryDetail(AttributeCategory $category)
	{
		$this->template->headerLabel = 'Detail';
		$this->template->headerTree = [
			['Atributy', 'default'],
			['Kategorie', 'default'],
			['Detail'],
		];
		$this->template->displayButtons = [$this->createBackButton('default')];
		$this->template->displayControls = [$this->getComponent('categoryForm')];
	}

	public function renderAttributeNew()
	{
		$this->template->headerLabel = 'Nová položka';
		$this->template->headerTree = [
			['Atributy', 'default'],
			['Nová položka'],
		];
		$this->template->displayButtons = [$this->createBackButton('default')];
		$this->template->displayControls = [$this->getComponent('attributeForm')];
	}

	public function renderAttributeDetail(Attribute $attribute)
	{
		$this->template->headerLabel = 'Detail';
		$this->template->headerTree = [
			['Atributy', 'default'],
			['Detail'],
		];
		$this->template->displayButtons = [$this->createBackButton('default')];
		$this->template->displayControls = [$this->getComponent('attributeForm')];
	}
}