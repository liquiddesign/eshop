<?php
declare(strict_types=1);

namespace Eshop\Admin;

use Admin\BackendPresenter;
use Admin\Controls\AdminForm;
use Admin\Controls\AdminGrid;
use Eshop\Controls\ProductFilter;
use Eshop\DB\Attribute;
use Eshop\DB\AttributeRepository;
use Eshop\DB\AttributeValue;
use Eshop\DB\AttributeValueRange;
use Eshop\DB\AttributeValueRangeRepository;
use Eshop\DB\AttributeValueRepository;
use Eshop\DB\CategoryRepository;
use Eshop\DB\SupplierRepository;
use Grid\Datagrid;
use Nette\Forms\Controls\TextArea;
use Nette\Forms\Controls\TextInput;
use Nette\Utils\Arrays;
use Nette\Utils\Random;
use Nette\Utils\Strings;
use Pages\DB\PageRepository;
use Pages\DB\PageTemplateRepository;
use Pages\Helpers;
use StORM\Collection;
use StORM\DIConnection;
use StORM\Entity;
use StORM\ICollection;

class AttributePresenter extends BackendPresenter
{
	public const TABS = [
		'attributes' => 'Atributy',
		'values' => 'Hodnoty',
		'ranges' => 'Rozsahy',
	];

	protected const CONFIGURATIONS = [
		'wizard' => false,
		'wizardSteps' => [],
		'forceValueFormMutationSelector' => false,
	];

	/** @inject */
	public AttributeRepository $attributeRepository;

	/** @inject */
	public AttributeValueRepository $attributeValueRepository;

	/** @inject */
	public CategoryRepository $categoryRepository;

	/** @inject */
	public PageRepository $pageRepository;

	/** @inject */
	public PageTemplateRepository $pageTemplateRepository;

	/** @inject */
	public SupplierRepository $supplierRepository;

	/** @inject */
	public AttributeValueRangeRepository $attributeValueRangeRepository;

	/** @persistent */
	public string $tab = 'attributes';

	public function renderDefault(): void
	{
		$this->template->headerLabel = 'Atributy';
		$this->template->headerTree = [
			['Atributy', 'this',],
			[self::TABS[$this->tab]],
		];

		if ($this->tab === 'attributes') {
			$this->template->displayButtons = [$this->createNewItemButton('attributeNew')];
			$this->template->displayControls = [$this->getComponent('attributeGrid')];
		} elseif ($this->tab === 'values') {
			$this->template->displayButtons = [$this->createNewItemButton('valueNew')];
			$this->template->displayControls = [$this->getComponent('valuesGrid')];
		} elseif ($this->tab === 'ranges') {
			$this->template->displayButtons = [$this->createNewItemButton('rangeNew')];
			$this->template->displayControls = [$this->getComponent('rangesGrid')];
		}

		$this->template->tabs = self::TABS;
	}

	public function createComponentAttributeGrid(): AdminGrid
	{
		$connection = $this->attributeRepository->getConnection();
		$mutationSuffix = $connection->getMutationSuffix();

		$source = $this->attributeRepository->many()->setGroupBy(['this.uuid'])
			->select(['categoriesNames' => "GROUP_CONCAT(DISTINCT category.name$mutationSuffix SEPARATOR ', ')"])
			->select(['assignCount' => 'COUNT(assign.uuid)'])
			->join(['attributeXcategory' => 'eshop_attribute_nxn_eshop_category'], 'attributeXcategory.fk_attribute = this.uuid')
			->join(['category' => 'eshop_category'], 'attributeXcategory.fk_category = category.uuid')
			->join(['attributeValue' => 'eshop_attributevalue'], 'this.uuid = attributeValue.fk_attribute')
			->join(['assign' => 'eshop_attributeassign'], 'attributeValue.uuid = assign.fk_value');

		$grid = $this->gridFactory->create($source, 20, null, null, true);

		$grid->setItemCountCallback(function (ICollection $filteredSource) use ($connection): int {
			return (int)$connection->rows()
				->select(['count' => 'count(*)'])
				->from(['derived' => $filteredSource->select(['assignCount' => 'COUNT(assign.uuid)'])], $filteredSource->getVars())
				->firstValue('count');
		});

		$grid->addColumnSelector();
		$grid->addColumnText('Kód', 'code', '%s', 'code', ['class' => 'minimal']);
		$grid->addColumnText('Název', 'name', '%s', 'name');
		$grid->addColumnText('Kategorie', 'categoriesNames', '%s');
		$grid->addColumnText('Zdroj', 'supplier.name', '%s', 'supplier.name');

		$grid->addColumnInputInteger('Priorita', 'priority', '', '', 'priority', [], true);
		$grid->addColumnInputCheckbox('Filtrace', 'showFilter', '', '', 'showFilter');
		$grid->addColumnInputCheckbox('Náhled', 'showProduct', '', '', 'showProduct');
		$grid->addColumnInputCheckbox('<i title="Skryto" class="far fa-eye-slash"></i>', 'hidden', '', '', 'hidden');

		$btnSecondary = 'btn btn-sm btn-outline-primary';

		$column = $grid->addColumn('', function (Attribute $object, Datagrid $datagrid) use ($btnSecondary) {
			$attributeValues = $this->attributeRepository->getAttributeValues($object, true);

			return \count($attributeValues) > 0 ?
				"<a class='$btnSecondary' href='" . $datagrid->getPresenter()->link('this', ['tab' => 'values', 'valuesGrid-attribute' => $object->code,]) . "'>Hodnoty</a>" :
				"<a class='$btnSecondary' href='" . $datagrid->getPresenter()->link('valueNew', $object) . "'>Vytvořit&nbsp;hodnotu</a>";
		}, '%s', null, ['class' => 'minimal']);

		$column->onRenderCell[] = function (\Nette\Utils\Html $td, Attribute $object): void {
			if ($object->isHardSystemic()) {
				$td[0] = '';
			}
		};

		$column = $grid->addColumn('', function (Attribute $object, Datagrid $datagrid) use ($btnSecondary) {
			return "<a class='$btnSecondary' href='" . $datagrid->getPresenter()->link('this', ['tab' => 'ranges', 'rangesGrid-attribute' => $object->code,]) . "'>Rozsahy</a>";
		}, '%s', null, ['class' => 'minimal']);

		$column->onRenderCell[] = function (\Nette\Utils\Html $td, Attribute $object): void {
			if ($object->isHardSystemic()) {
				$td[0] = '';
			}
		};

		$grid->addColumnLinkDetail('attributeDetail');
		$grid->addColumnActionDeleteSystemic();

		$grid->addButtonSaveAll();
		$grid->addButtonDeleteSelected(null, false, function ($object) {
			if ($object) {
				return !$object->isSystemic();
			}

			return false;
		}, 'this.uuid');

		$grid->addButtonBulkEdit('attributeForm', ['showCount', 'hidden', 'hideEmptyValues', 'showRange', 'showFilter', 'showProduct'], 'attributeGrid');

		$grid->addFilterTextInput('code', ['this.name_cs', 'this.code'], null, 'Kód, název');

		if ($categories = $this->categoryRepository->getTreeArrayForSelect()) {
			$grid->addFilterDataSelect(function (Collection $source, $value): void {
				$source->where('category.uuid', $value);
			}, '', 'category', null, $categories)->setPrompt('- Kategorie -');
		}

		if ($suppliers = $this->supplierRepository->getArrayForSelect()) {
			$grid->addFilterDataSelect(function (Collection $source, $value): void {
				$source->where('supplier.uuid', $value);
			}, '', 'supplier', null, $suppliers)->setPrompt('- Zdroj -');
		}

		$grid->addFilterDataSelect(function (ICollection $source, $value): void {
			$source->where('this.hidden', (bool)$value);
		}, '', 'hidden', null, ['1' => 'Skryté', '0' => 'Viditelné'])->setPrompt('- Viditelnost -');

		$grid->addFilterDataSelect(function (Collection $source, $value): void {
			if ($value === null) {
				$source->setGroupBy(['this.uuid']);
			} else {
				$source->setGroupBy(['this.uuid'], $value === 1 ? 'assignCount != 0' : 'assignCount = 0');
			}
		}, '', 'assign', null, [0 => 'Pouze nepřiřazené', 1 => 'Pouze přiřazené',])->setPrompt('- Přiřazené -');

		$grid->addFilterButtons(['default']);

		return $grid;
	}

	public function createComponentAttributeForm(): AdminForm
	{
		$form = $this->formFactory->create(true);

		/** @var \Eshop\DB\Attribute|null $attribute */
		$attribute = $this->getParameter('attribute');

		$hardSystemic = $attribute && $attribute->isHardSystemic();

		$form->addText('code', 'Kód')->setRequired();
		$form->addLocaleText('name', 'Název');
		$form->addLocaleTextArea('note', 'Dodatečné informace');
		$form->addDataMultiSelect('categories', 'Kategorie', $this->categoryRepository->getArrayForSelect());
		$form->addText('priority', 'Priorita')
			->addRule($form::INTEGER)
			->setRequired()
			->setDefaultValue(10);

		if (!$hardSystemic) {
			$form->addCheckbox('showProduct', 'Náhled')->setHtmlAttribute('data-info', 'Atribut se zobrazí v náhledu produktu.');
		}

		$form->addCheckbox('hidden', 'Skryto');
		$form->addCheckbox('hideEmptyValues', 'Skrýt nepřiřazené hodnoty')->setHtmlAttribute('data-info', 'Hodnoty, které nemají žádný produkt budou v seznamu skryté.');

		if (!$hardSystemic) {
			$form->addCheckbox('showRange', 'Zobrazit jako rozsahy')->setHtmlAttribute('data-info', 'Hodnoty atributu nebudou zobrazeny jako jednotlivé položky, ale souhrnně dle nastavení rozsahů.');
		}

		$form->addInteger('showCount', 'Počet položek zobrazených při načtení')->setNullable()
			->setHtmlAttribute('data-info', 'Při načtení bude zobrazeno jen X zvolených položek. Ostatní lze zobrazit tlačítkem "Zobrazit položky". Pokud necháte prázdné, budou zobrazeny všechny.');

		$form->addGroup('Export');
		$form->addText('heurekaName', 'Název pro Heureka.cz')->setNullable();
		$form->addText('zboziName', 'Název pro Zboží.cz')->setNullable();

		$form->addGroup('Filtr');
		$form->addCheckbox('showFilter', 'Filtr')->setHtmlAttribute('data-info', 'Atribut se zobrazí při filtrování.');

		if (!$hardSystemic) {
			$form->addSelect('filterType', 'Typ filtru', Attribute::FILTER_TYPES);

			if (isset($this::CONFIGURATIONS['wizard']) && $this::CONFIGURATIONS['wizard']) {
				$form->addGroup('Průvodce');
				$form->addCheckbox('showWizard', 'Zobrazit v průvodci');
				$form->addDataMultiSelect('wizardStep', 'Pozice v průvodci (krok)', $this::CONFIGURATIONS['wizardSteps']);
				$form->addLocaleText('wizardLabel', 'Název v průvodci')
					->forAll(function ($input): void {
						$input->setNullable()->setHtmlAttribute('data-info', 'Pokud necháte prázdné, použije se název atributu.');
					});
			}
		}

		$form->addSubmits(!$this->getParameter('attribute'));

		$form->onSuccess[] = function (AdminForm $form): void {
			$values = $form->getValues('array');

			if (!$values['uuid']) {
				$values['uuid'] = DIConnection::generateUuid();
			}

			$values['showCount'] = $values['showCount'] && $values['showCount'] < 0 ? null : $values['showCount'];
			$values['wizardStep'] = \count($values['wizardStep'] ?? []) > 0 ? \implode(',', $values['wizardStep']) : null;

			$object = $this->attributeRepository->syncOne($values, null, true);

			$this->flashMessage('Uloženo', 'success');
			$form->processRedirect('attributeDetail', 'default', [$object]);
		};

		return $form;
	}

	public function createComponentValuesGrid(): AdminGrid
	{
		$mutationSuffix = $this->attributeValueRepository->getConnection()->getMutationSuffix();

		$source = $this->attributeValueRepository->many()->setGroupBy(['this.uuid'])
			->select([
				'assignCount' => 'COUNT(assign.uuid)',
				'supplierName' => 'supplier.name',
				'rangeName' => "IF(valueRange.internalName IS NOT NULL, CONCAT(valueRange.internalName, ' (', valueRange.name$mutationSuffix, ')'), valueRange.name$mutationSuffix)",
			])
			->join(['attribute' => 'eshop_attribute'], 'this.fk_attribute = attribute.uuid')
			->join(['supplier' => 'eshop_supplier'], 'attribute.fk_supplier = supplier.uuid')
			->join(['assign' => 'eshop_attributeassign'], 'this.uuid = assign.fk_value')
			->join(['valueRange' => 'eshop_attributevaluerange'], 'this.fk_attributeValueRange = valueRange.uuid');

		$grid = $this->gridFactory->create($source, 20, 'code', 'ASC', true);

		$grid->setItemCountCallback(function (ICollection $filteredSource) {
			return (int)$this->attributeRepository->getConnection()->rows()
				->select(['count' => 'count(*)'])
				->from(['derived' => $filteredSource->setSelect(['uuid' => 'this.uuid', 'assignCount' => 'COUNT(assign.uuid)'])], $filteredSource->getVars())
				->firstValue('count');
		});

		$grid->addColumnSelector();
		$grid->addColumnText('Kód', 'code', '%s', 'code');
		$grid->addColumnText('Interní název', 'internalName', '%s', 'internalName');
		$grid->addColumn('Hodnota', function (AttributeValue $attributeValue, $grid) {
			$page = $this->pageRepository->getPageByTypeAndParams('product_list', null, ['attributeValue' => $attributeValue->getPK()]);

			if (!$page) {
				return $attributeValue->label;
			}

			return '<a href="' . $grid->getPresenter()->link(':Eshop:Product:list', ['attributeValue' => $attributeValue->getPK()]) . '" target="_blank">' . $attributeValue->label . '</a>';
		}, '%s', 'label');
		$grid->addColumnText('Číselná reprezentace', 'number', '%s', 'number');
		$grid->addColumnText('Atribut', 'attribute.name', '%s', 'attribute.name');
		$grid->addColumnText('Zdroj', 'supplierName', '%s', 'supplierName');
		$grid->addColumnText('Rozsah', 'rangeName', '%s');
		$grid->addColumnInputInteger('Priorita', 'priority', '', '', 'priority', [], true);
		$grid->addColumnInputCheckbox('<i title="Skryto" class="far fa-eye-slash"></i>', 'hidden', '', '', 'hidden');

		$grid->addColumnLinkDetail('valueDetail');
		$grid->addColumnActionDelete(null, false, function (AttributeValue $attributeValue) {
			return !$this->attributeValueRepository->isValuePairedWithProducts($attributeValue);
		});

		$grid->addButtonSaveAll();
		$grid->addButtonDeleteSelected(null, false, function (AttributeValue $attributeValue) {
			return !$this->attributeValueRepository->isValuePairedWithProducts($attributeValue);
		}, 'this.uuid');
		$grid->addButtonBulkEdit('valuesForm', ['attributeValueRange'], 'valuesGrid');

		$grid->addFilterTextInput('search', ['this.code', 'this.label_cs'], null, 'Kód, popisek');
		$grid->addFilterTextInput('attribute', ['attribute.code'], null, 'Kód atributu', null, '%s');

		if ($suppliers = $this->supplierRepository->getArrayForSelect()) {
			$grid->addFilterDataSelect(function (Collection $source, $value): void {
				$source->where('supplier.uuid', $value);
			}, '', 'supplier', null, $suppliers)->setPrompt('- Zdroj -');
		}

		$grid->addFilterDataSelect(function (ICollection $source, $value): void {
			$source->where('this.hidden', (bool)$value);
		}, '', 'hidden', null, ['1' => 'Skryté', '0' => 'Viditelné'])->setPrompt('- Viditelnost -');

		$grid->addFilterDataSelect(function (Collection $source, $value): void {
			if ($value === null) {
				$source->setGroupBy(['this.uuid']);
			} else {
				$source->setGroupBy(['this.uuid'], $value === 1 ? 'assignCount != 0' : 'assignCount = 0');
			}
		}, '', 'assign', null, [0 => 'Pouze nepřiřazené', 1 => 'Pouze přiřazené'])->setPrompt('- Přiřazené -');

		if ($options = $this->attributeValueRangeRepository->getArrayForSelect()) {
			$grid->addFilterDataSelect(function (Collection $source, $value): void {
				if ($value === '0') {
					$source->where('this.fk_attributeValueRange IS NULL');
				} else {
					$source->where('this.fk_attributeValueRange', $value);
				}
			}, '', 'range', null, ['0' => 'X - bez rozsahu'] + $options)->setPrompt('- Rozsahy -');
		}

		$grid->addFilterButtons(['default']);

		if ($this->formFactory->getPrettyPages()) {
			$submit = $grid->getForm()->addSubmit('createPages', 'Vytvořit stránky')->setHtmlAttribute('class', 'btn btn-outline-primary btn-sm');

			$submit->onClick[] = function ($button) use ($grid): void {
				$grid->getPresenter()->redirect('createPages', [$grid->getSelectedIds(), true]);
			};

			$submit = $grid->getForm()->addSubmit('deletePages', 'Smazat stránky')->setHtmlAttribute('class', 'btn btn-outline-primary btn-sm');

			$submit->onClick[] = function ($button) use ($grid): void {
				$grid->getPresenter()->redirect('createPages', [$grid->getSelectedIds(), false]);
			};
		}

		$grid->onDelete[] = [$this, 'onDelete'];

		return $grid;
	}

	public function createComponentValuesForm(): AdminForm
	{
		if (isset($this::CONFIGURATIONS['forceValueFormMutationSelector']) && $this::CONFIGURATIONS['forceValueFormMutationSelector']) {
			$this->formFactory->setMutations(\array_keys($this->attributeRepository->getConnection()->getAvailableMutations()));
		}

		$form = $this->formFactory->create(true);

		$form->addText('code', 'Kód')->setRequired();

		if (!($this->getParameter('attributeValue') && $this->attributeValueRepository->isValuePairedWithProducts($this->getParameter('attributeValue')))) {
			$attributeInput = $form->addDataSelect('attribute', 'Atribut', $this->attributeRepository->getArrayForSelect())->setRequired()
				->setHtmlAttribute('data-info', 'Hodnoty systémových atributů "' . \implode(', ', ProductFilter::SYSTEMIC_ATTRIBUTES) . '" nebudou použity.');

			if ($attribute = $this->getParameter('attribute')) {
				$attributeInput->setDefaultValue($attribute->getPK());
			}
		}

		$form->addText('internalName', 'Interní název')->setNullable()
			->setHtmlAttribute('data-info', 'Používá se pro lepší přehlednost v adminu. Pokud není vyplněn, tak se použije "Popisek".');
		$nameInput = $form->addLocaleText('label', 'Popisek');

		$form->addLocaleTextArea('note', 'Dodatečné informace');
		$form->addText('metaValue', 'Doprovodná hodnota');
		$form->addText('number', 'Číselná reprezentace')->addFilter('floatval')->setNullable()->addCondition($form::FILLED)->addRule($form::FLOAT);
		$form->addText('priority', 'Priorita')
			->addRule($form::INTEGER)
			->setRequired()
			->setDefaultValue(10);
		$form->addCheckbox('hidden', 'Skryto');

		$mutationSuffix = $this->attributeValueRangeRepository->getConnection()->getMutationSuffix();

		$form->addDataSelect(
			'attributeValueRange',
			'Rozsah',
			$this->attributeValueRangeRepository
				->getCollection(true)
				->select(['internalLabel' => 'IFNULL(internalName, name' . $mutationSuffix . ')'])
				->toArrayOf('internalLabel'),
		)
			->setPrompt('Nepřiřazeno')
			->setHtmlAttribute('data-info', 'Pokud má atribut aktivní možnost "Zobrazit jako rozsahy", tak bude tato hodnota zobrazena jako zvolený rozsah.');

		$form->addGroup('Export');
		$form->addText('heurekaLabel', 'Název pro Heureka.cz')->setNullable();
		$form->addText('zboziLabel', 'Název pro Zboží.cz')->setNullable();

		if (isset($this::CONFIGURATIONS['wizard']) && $this::CONFIGURATIONS['wizard']) {
			$form->addGroup('Průvodce');
			$form->addCheckbox('showWizard', 'Zobrazit v průvodci')
				->addCondition($form::FILLED)
				->toggle('frm-valuesForm-defaultWizard-toogle');
			$form->addDataMultiSelect('defaultWizard', 'Výchozí hodnota (zaškrtnuté) v krocích', $this::CONFIGURATIONS['wizardSteps']);
		}

		if ($form->getPrettyPages()) {
			$form->addPageContainer(
				'product_list',
				['attributeValue' => $this->getParameter('attributeValue') ? $this->getParameter('attributeValue')->getPK() : null],
				$nameInput,
				false,
				false,
				true,
				'Stránka',
			);
		}

		$form->addSubmits(!$this->getParameter('attributeValue'));

		$form->onSuccess[] = function (AdminForm $form): void {
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

			$values['defaultWizard'] = \count($values['defaultWizard'] ?? []) > 0 ? \implode(',', $values['defaultWizard']) : null;

			/** @var \Eshop\DB\AttributeValue $object */
			$object = $this->attributeValueRepository->syncOne($values, null, true);

			if (isset($values['page']) && isset($values['page']['url']) && !$values['page']['url'][Arrays::first($this->formFactory->getMutations())]) {
				foreach (\array_keys($this->pageRepository->getConnection()->getAvailableMutations()) as $mutation) {
					/** @var \Web\DB\Page|null $page */
					$page = $this->pageRepository->getPageByTypeAndParams('product_list', $mutation, ['attributeValue' => $this->getParameter('attributeValue')]);

					if ($page === null) {
						continue;
					}

					$page->delete();
				}
			} else {
				$values['page']['type'] = 'product_list';

				$this->pageRepository->syncPage($values['page'], ['attributeValue' => $object->getPK()]);
			}

			$this->flashMessage('Uloženo', 'success');
			$form->processRedirect('valueDetail', 'default', [$object]);
		};

		return $form;
	}

	public function createComponentRangeForm(): AdminForm
	{
		$form = $this->formFactory->create(true);

		$form->addLocaleText('name', 'Název')->forPrimary(function ($input): void {
			$input->setRequired();
		});
		$form->addText('internalName', 'Interní název')->setNullable()
			->setHtmlAttribute('data-info', 'Používá se pro lepší přehlednost v adminu. Pokud není vyplněn, tak se použije "Název".');

		$form->addInteger('priority', 'Priorita')->setDefaultValue(10)->setRequired();
		$form->addCheckbox('hidden', 'Skryto');

		$form->addSubmits(!$this->getParameter('attribute'));

		$form->onSuccess[] = function (AdminForm $form): void {
			$values = $form->getValues('array');

			if (!$values['uuid']) {
				$values['uuid'] = DIConnection::generateUuid();
			}

			$object = $this->attributeValueRangeRepository->syncOne($values, null, true);

			$this->flashMessage('Uloženo', 'success');
			$form->processRedirect('rangeDetail', 'default', [$object]);
		};

		return $form;
	}

	public function createComponentRangesGrid(): AdminGrid
	{
		$source = $this->attributeValueRangeRepository->many()
			->setGroupBy(['this.uuid'])
			->join(['attributeValue' => 'eshop_attributevalue'], 'this.uuid = attributeValue.fk_attributeValueRange')
			->join(['attribute' => 'eshop_attribute'], 'attributeValue.fk_attribute = attribute.uuid');

		$grid = $this->gridFactory->create($source, 20, 'priority', 'ASC', true);

		$grid->addColumnSelector();

		$grid->addColumnText('Interní název', 'internalName', '%s', 'internalName');
		$grid->addColumnText('Název', 'name', '%s', 'name');

		$grid->addColumnInputInteger('Priorita', 'priority', '', '', 'priority', [], true);
		$grid->addColumnInputCheckbox('<i title="Skryto" class="far fa-eye-slash"></i>', 'hidden', '', '', 'hidden');
		$grid->addColumnLinkDetail('rangeDetail');
		$grid->addColumnActionDelete();

		$grid->addButtonSaveAll();
		$grid->addButtonDeleteSelected(null, false, null, 'this.uuid');

		$grid->addFilterTextInput('search', ['this.name_cs', 'this.internalName',], null, 'Název, interní název');
		$grid->addFilterTextInput('attribute', ['attribute.code'], null, 'Kód atributu', null, '%s');
		$grid->addFilterButtons();

		return $grid;
	}

	public function actionAttributeDetail(Attribute $attribute): void
	{
		/** @var \Forms\Form $form */
		$form = $this->getComponent('attributeForm');

		$defaults = $attribute->toArray(['categories']);
		$defaults['wizardStep'] = $defaults['wizardStep'] ? \explode(',', $defaults['wizardStep']) : null;

		$form->setDefaults($defaults);
	}

	public function renderAttributeNew(): void
	{
		$this->template->headerLabel = 'Nová položka';
		$this->template->headerTree = [
			['Atributy', 'default',],
			['Nová položka'],
		];
		$this->template->displayButtons = [$this->createBackButton('default')];
		$this->template->displayControls = [$this->getComponent('attributeForm')];
	}

	public function renderAttributeDetail(): void
	{
		$this->template->headerLabel = 'Detail';
		$this->template->headerTree = [
			['Atributy', 'default',],
			['Detail'],
		];
		$this->template->displayButtons = [$this->createBackButton('default')];
		$this->template->displayControls = [$this->getComponent('attributeForm')];
	}

	public function renderValueNew(?Attribute $attribute = null): void
	{
		unset($attribute);

		$this->template->headerLabel = 'Nová položka';
		$this->template->headerTree = [
			['Atributy', 'default',],
			['Hodnoty', 'default',],
			['Nová položka'],
		];
		$this->template->displayButtons = [$this->createBackButton('default')];
		$this->template->displayControls = [$this->getComponent('valuesForm')];
	}

	public function actionValueDetail(AttributeValue $attributeValue): void
	{
		/** @var \Admin\Controls\AdminForm $form */
		$form = $this->getComponent('valuesForm');

		$defaults = $attributeValue->toArray();
		$defaults['defaultWizard'] = $defaults['defaultWizard'] ? \explode(',', $defaults['defaultWizard']) : null;

		$form->setDefaults($defaults);

		if (!$form->getPrettyPages()) {
			return;
		}

		/** @var \Web\DB\Page|null $page */
		$page = $this->pageRepository->getPageByTypeAndParams('product_list', null, ['attributeValue' => $attributeValue->getPK()]);

		if ($page === null) {
			return;
		}

		/** @var \Forms\Container $container */
		$container = $form['page'];
		$container->setDefaults($page->toArray());

		$form['page']['url']->forAll(function (TextInput $text, $mutation) use ($page, $form): void {
			$text->getRules()->reset();
			$text->addRule([$form, 'validateUrl',], 'URL již existuje', [$this->pageRepository, $mutation, $page->getPK(),]);
		});
	}

	public function renderValueDetail(): void
	{
		$this->template->headerLabel = 'Detail';
		$this->template->headerTree = [
			['Atributy', 'default',],
			['Hodnoty', 'default',],
			['Detail'],
		];
		$this->template->displayButtons = [$this->createBackButton('default')];
		$this->template->displayControls = [$this->getComponent('valuesForm')];
	}
	
	/**
	 * @param array<string|int> $ids
	 * @param bool $createOrDelete
	 */
	public function renderCreatePages(array $ids, bool $createOrDelete): void
	{
		unset($ids);
		unset($createOrDelete);

		$this->template->headerLabel = 'Vytvořit stránky';
		$this->template->headerTree = [
			['Atributy', 'default',],
			['Hodnoty', 'default',],
		];
		$this->template->displayButtons = [$this->createBackButton('default')];
		$this->template->displayControls = [$this->getComponent('createPagesForm')];
	}

	public function createComponentCreatePagesForm(): AdminForm
	{
		/** @var \Grid\Datagrid $productGrid */
		$productGrid = $this->getComponent('valuesGrid');

		$ids = $this->getParameter('ids') ?: [];
		$totalNo = $productGrid->getFilteredSource()->enum();
		$selectedNo = \count($ids);

		$form = $this->formFactory->create();
		$form->setAction($this->link('this', ['selected' => $this->getParameter('selected')]));
		$form->addRadioList('bulkType', 'Upravit', [
			'selected' => "vybrané ($selectedNo)",
			'all' => "celý výsledek ($totalNo)",
		])->setDefaultValue('selected');

		if ($this->getParameter('createOrDelete')) {
			$form->addSelect('pageTemplate', 'Šablona stránky', $this->pageTemplateRepository->getArrayForSelect(true, 'product_list'))->setPrompt('Žádná')
				->addCondition($form::BLANK)
				->toggle('frm-createPagesForm-hidden');

			$form->addGroup('Stránka')->setOption('id', 'frm-createPagesForm-hidden');

			$form->addLocaleText('title', 'Titulek')->forAll(function (TextInput $text): void {
				$text->setHtmlAttribute('data-characters', 70);
			});

			$form->addLocaleTextArea('description', 'Popisek')->forAll(function (TextArea $text): void {
				$text->setHtmlAttribute('style', 'width: 862px !important;')
					->setHtmlAttribute('data-characters', 150);
			});

			$form->addGroup();

			$form->addSubmit('submit', 'Vytvořit / Upravit');
		} else {
			$form->addSubmit('delete', 'Smazat')->setHtmlAttribute('class', 'btn btn-danger btn-sm ml-0 mt-1 mb-1 mr-1');
		}

		$form->onSuccess[] = function (AdminForm $form) use ($ids, $productGrid): void {
			$values = $form->getValues('array');

			/** @var \Nette\Forms\Controls\SubmitButton $submitter */
			$submitter = $form->isSubmitted();
			$submitName = $submitter->getName();

			/** @var \Eshop\DB\AttributeValue[] $attributeValues */
			$attributeValues = $values['bulkType'] === 'selected' ? $this->attributeValueRepository->many()->where('uuid', $ids) : $productGrid->getFilteredSource();

			if ($submitName === 'submit') {
				$pageTemplate = $values['pageTemplate'] ? $this->pageTemplateRepository->one($values['pageTemplate']) : null;

				foreach ($attributeValues as $attributeValue) {
					$page = $this->pageRepository->getPageByTypeAndParams('product_list', null, ['attributeValue' => $attributeValue->getPK()]);

					$pageValues = [];

					if (!$page) {
						foreach ($form->getMutations() as $mutation) {
							$attributeName = $attributeValue->attribute->getValue('name', $mutation);
							$attributeValueLabel = $attributeValue->getValue('label', $mutation);

							if (!$attributeName || !$attributeValueLabel) {
								continue;
							}

							$url = Strings::webalize($attributeName . '-' . $attributeValueLabel);

							if (!$this->pageRepository->isUrlAvailable($url, $mutation)) {
								$url = Random::generate(4, '0-9') . '-' . $url;
							}

							$pageValues['url'][$mutation] = $url;
							$pageValues['title'][$mutation] = $attributeName . ' - ' . $attributeValueLabel;
						}
					}

					$properties = ['title', 'description'];

					foreach ($form->getMutations() as $mutation) {
						foreach ($properties as $property) {
							if ($pageTemplate) {
								$pageValues[$property][$mutation] = $pageTemplate->getValue($property, $mutation);
							} elseif ($values[$property][$mutation]) {
								$pageValues[$property][$mutation] = $values[$property][$mutation];
							}
						}
					}

					$pageValues['type'] = 'product_list';
					$pageValues['params'] = Helpers::serializeParameters(['attributeValue' => $attributeValue->getPK()]);

					$this->pageRepository->syncOne($pageValues);
				}
			} elseif ($submitName === 'delete') {
				foreach ($attributeValues as $attributeValue) {
					/** @var \Web\DB\Page|null $page */
					$page = $this->pageRepository->getPageByTypeAndParams('product_list', null, ['attributeValue' => $attributeValue->getPK()]);

					if ($page === null) {
						continue;
					}

					$page->delete();
				}
			}

			$this->redirect('default');
		};

		return $form;
	}

	public function actionRangeDetail(AttributeValueRange $attributeValueRange): void
	{
		/** @var \Forms\Form $form */
		$form = $this->getComponent('rangeForm');

		$form->setDefaults($attributeValueRange->toArray(['attributeValues']));
	}

	public function renderRangeNew(): void
	{
		$this->template->headerLabel = 'Nová položka';
		$this->template->headerTree = [
			['Atributy', 'default'],
			['Rozsahy', 'default'],
			['Nová položka'],
		];
		$this->template->displayButtons = [$this->createBackButton('default')];
		$this->template->displayControls = [$this->getComponent('rangeForm')];
	}

	public function renderRangeDetail(): void
	{
		$this->template->headerLabel = 'Detail';
		$this->template->headerTree = [
			['Atributy', 'default'],
			['Rozsahy', 'default'],
			['Detail'],
		];
		$this->template->displayButtons = [$this->createBackButton('default')];
		$this->template->displayControls = [$this->getComponent('rangeForm')];
	}

	public function onDelete(Entity $object): void
	{
		/** @var \Web\DB\Page|null $page */
		$page = $this->pageRepository->getPageByTypeAndParams('product_list', null, ['attributeValue' => $object->getPK()]);

		if (!$page) {
			return;
		}

		$page->delete();
	}
}
