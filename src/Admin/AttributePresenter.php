<?php
declare(strict_types=1);

namespace Eshop\Admin;

use Admin\BackendPresenter;
use Admin\Controls\AdminForm;
use Admin\Controls\AdminGrid;
use Eshop\DB\Attribute;
use Eshop\DB\AttributeRepository;
use Eshop\DB\AttributeValue;
use Eshop\DB\AttributeValueRepository;
use Eshop\DB\CategoryRepository;
use Forms\Form;
use Grid\Datagrid;
use StORM\Collection;
use StORM\DIConnection;
use function Clue\StreamFilter\fun;

class AttributePresenter extends BackendPresenter
{
	/** @inject */
	public AttributeRepository $attributeRepository;

	/** @inject */
	public AttributeValueRepository $attributeValueRepository;

	/** @inject */
	public CategoryRepository $categoryRepository;

	public const TABS = [
		'attributes' => 'Atributy',
		'values' => 'Hodnoty',
	];

	/** @persistent */
	public string $tab = 'attributes';

	public function renderDefault()
	{
		$this->template->headerLabel = 'Atributy';
		$this->template->headerTree = [
			['Atributy', 'this'],
			[self::TABS[$this->tab]]
		];

		if ($this->tab == 'attributes') {
			$this->template->displayButtons = [$this->createNewItemButton('attributeNew')];
			$this->template->displayControls = [$this->getComponent('attributeGrid')];
		} elseif ($this->tab == 'values') {
			$this->template->displayButtons = [$this->createNewItemButton('valueNew')];
			$this->template->displayControls = [$this->getComponent('valuesGrid')];
		}

		$this->template->tabs = self::TABS;
	}

	public function createComponentAttributeGrid(): AdminGrid
	{
		$grid = $this->gridFactory->create($this->attributeRepository->many(), 20, 'priority', 'ASC', true);
		$grid->addColumnSelector();
		$grid->addColumnText('Kód', 'code', '%s', 'code');
		$grid->addColumnText('Název', 'name', '%s', 'name');

		$btnSecondary = 'btn btn-sm btn-outline-primary';

		$grid->addColumn('', function (Attribute $object, Datagrid $datagrid) use ($btnSecondary) {
			$attributeValues = $this->attributeRepository->getAttributeValues($object, true);

			return \count($attributeValues) > 0 ?
				"<a class='$btnSecondary' href='" . $datagrid->getPresenter()->link('this', ['tab' => 'values', 'valuesGrid-attribute' => $object->code]) . "'>Hodnoty</a>" :
				"<a class='$btnSecondary' href='" . $datagrid->getPresenter()->link('valueNew', $object) . "'>Vytvořit&nbsp;hodnotu</a>";
		}, '%s', null, ['class' => 'minimal']);

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

		$grid->addFilterTextInput('code', ['this.name_cs', 'this.code'], null, 'Kód, název');

		if ($categories = $this->categoryRepository->getTreeArrayForSelect()) {
			$grid->addFilterDataSelect(function (Collection $source, $value) {
				$source->join(['nxn' => 'eshop_attribute_nxn_eshop_category'], 'this.uuid = nxn.fk_attribute');
				$source->join(['category' => 'eshop_category'], 'category.uuid = nxn.fk_category');
				$source->where('category.uuid', $value);
			}, '', 'category', null, $categories)->setPrompt('- Kategorie -');
		}

		$grid->addFilterButtons(['default']);

		return $grid;
	}

	public function createComponentAttributeForm()
	{
		$form = $this->formFactory->create(true);

		$form->addText('code', 'Kód');
		$form->addLocaleText('name', 'Název');

		$form->addDataMultiSelect('categories', 'Kategorie', $this->categoryRepository->getArrayForSelect());
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

	public function createComponentValuesGrid(): AdminGrid
	{
		$grid = $this->gridFactory->create($this->attributeValueRepository->many(), 20, 'code', 'ASC', true);
		$grid->addColumnSelector();
		$grid->addColumnText('Kód', 'code', '%s', 'code');
		$grid->addColumnText('Popisek', 'label', '%s', 'label');
		$grid->addColumnText('Číselná reprezentace', 'number', '%s', 'number');
		$grid->addColumnText('Atribut', 'attribute.name', '%s', 'attribute.name');
		$grid->addColumnInputInteger('Priorita', 'priority', '', '', 'priority', [], true);
		$grid->addColumnInputCheckbox('<i title="Skryto" class="far fa-eye-slash"></i>', 'hidden', '', '', 'hidden');

		$grid->addColumnLinkDetail('valueDetail');
		$grid->addColumnActionDelete(null, false, function (AttributeValue $attributeValue) {
			return !$this->attributeValueRepository->isValuePairedWithProducts($attributeValue);
		});

		$grid->addButtonSaveAll();
		$grid->addButtonDeleteSelected(null, false, function (AttributeValue $attributeValue) {
			return !$this->attributeValueRepository->isValuePairedWithProducts($attributeValue);
		});

		$grid->addFilterTextInput('search', ['this.code', 'this.label_cs'], null, 'Kód, popisek');
		$grid->addFilterTextInput('attribute', ['attribute.code'], null, 'Kód atributu');
		$grid->addFilterButtons(['default']);

		return $grid;
	}

	public function createComponentValuesForm()
	{
		$form = $this->formFactory->create(true);

		$form->addText('code', 'Kód')->setRequired();

		if (!($this->getParameter('attributeValue') && $this->attributeValueRepository->isValuePairedWithProducts($this->getParameter('attributeValue')))) {
			$attributeInput = $form->addDataSelect('attribute', 'Atribut', $this->attributeRepository->getArrayForSelect())->setRequired();

			if ($attribute = $this->getParameter('attribute')) {
				$attributeInput->setDefaultValue($attribute->getPK());
			}
		}

		$form->addLocaleText('label', 'Popisek');
		$form->addLocaleText('note', 'Dodatečné informace');
		$form->addText('metaValue', 'Doprovodná hodnota');
		$form->addText('number', 'Číselná reprezentace')->addFilter('floatval')->setNullable()->addCondition($form::FILLED)->addRule($form::FLOAT);
		$form->addText('priority', 'Priorita')
			->addRule($form::INTEGER)
			->setRequired()
			->setDefaultValue(10);
		$form->addCheckbox('hidden', 'Skryto');

		$form->addSubmits(!$this->getParameter('attributeValue'));

		$form->onSuccess[] = function (AdminForm $form) {
			$values = $form->getValues('array');

			if (!$values['uuid']) {
				$values['uuid'] = DIConnection::generateUuid();
			}

			if ($this->getParameter('attributeValue')) {
				$values['attribute'] = $this->getParameter('attributeValue')->attribute->getPK();
			}

			if ($this->getParameter('attribute')) {
				$values['attribute'] = $this->getParameter('attribute')->getPK();
			}

			$object = $this->attributeValueRepository->syncOne($values, null, true);

			$this->flashMessage('Uloženo', 'success');
			$form->processRedirect('valueDetail', 'default', [$object]);
		};

		return $form;
	}

	public function actionAttributeNew()
	{
	}

	public function actionAttributeDetail(Attribute $attribute)
	{
		/** @var Form $form */
		$form = $this->getComponent('attributeForm');

		$form->setDefaults($attribute->toArray(['categories']));
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

	public function actionValueNew(?Attribute $attribute = null)
	{

	}

	public function renderValueNew(?Attribute $attribute = null)
	{
		$this->template->headerLabel = 'Nová položka';
		$this->template->headerTree = [
			['Atributy', 'default'],
			['Hodnoty', 'default'],
			['Nová položka'],
		];
		$this->template->displayButtons = [$this->createBackButton('default')];
		$this->template->displayControls = [$this->getComponent('valuesForm')];
	}

	public function actionValueDetail(AttributeValue $attributeValue)
	{
		/** @var Form $form */
		$form = $this->getComponent('valuesForm');

		$form->setDefaults($attributeValue->toArray());
	}

	public function renderValueDetail(AttributeValue $attributeValue)
	{
		$this->template->headerLabel = 'Detail';
		$this->template->headerTree = [
			['Atributy', 'default'],
			['Hodnoty', 'default'],
			['Detail'],
		];
		$this->template->displayButtons = [$this->createBackButton('default')];
		$this->template->displayControls = [$this->getComponent('valuesForm')];
	}
}