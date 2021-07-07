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
use Eshop\DB\SupplierRepository;
use Forms\Form;
use Grid\Datagrid;
use Nette\Forms\Controls\TextArea;
use Nette\Forms\Controls\TextInput;
use Nette\Utils\Arrays;
use Nette\Utils\Random;
use Pages\DB\PageRepository;
use Pages\Helpers;
use StORM\Collection;
use StORM\DIConnection;
use Pages\DB\PageTemplateRepository;
use StORM\ICollection;

class AttributePresenter extends BackendPresenter
{
	protected const CONFIGURATIONS = [
		'wizard' => false,
		'wizardSteps' => []
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

		$grid->setItemCountCallback(function (ICollection $filteredSource) use ($connection) {
			return (int) $connection->rows()->select(['count' => 'count(*)'])->from(['derived' => $filteredSource->select(['assignCount' => 'COUNT(assign.uuid)'])], $filteredSource->getVars())->firstValue('count');
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

		$grid->addColumn('', function (Attribute $object, Datagrid $datagrid) use ($btnSecondary) {
			$attributeValues = $this->attributeRepository->getAttributeValues($object, true);

			return \count($attributeValues) > 0 ?
				"<a class='$btnSecondary' href='" . $datagrid->getPresenter()->link('this', ['tab' => 'values', 'valuesGrid-attribute' => $object->code]) . "'>Hodnoty</a>" :
				"<a class='$btnSecondary' href='" . $datagrid->getPresenter()->link('valueNew', $object) . "'>Vytvořit&nbsp;hodnotu</a>";
		}, '%s', null, ['class' => 'minimal']);

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
				$source->where('category.uuid', $value);
			}, '', 'category', null, $categories)->setPrompt('- Kategorie -');
		}

		if ($suppliers = $this->supplierRepository->getArrayForSelect()) {
			$grid->addFilterDataSelect(function (Collection $source, $value) {
				$source->where('supplier.uuid', $value);
			}, '', 'supplier', null, $suppliers)->setPrompt('- Zdroj -');
		}

		$grid->addFilterDataSelect(function (Collection $source, $value) {
			if ($value === null) {
				$source->setGroupBy(['this.uuid']);
			} else {
				$source->setGroupBy(['this.uuid'], $value == 1 ? 'assignCount != 0' : 'assignCount = 0');
			}
		}, '', 'assign', null, [0 => 'Pouze nepřiřazené', 1 => 'Pouze přiřazené'])->setPrompt('- Přiřazené -');

		$grid->addFilterButtons(['default']);

		return $grid;
	}

	public function createComponentAttributeForm()
	{
		$form = $this->formFactory->create(true);

		$form->addText('code', 'Kód');
		$form->addLocaleText('name', 'Název');
		$form->addLocaleTextArea('note', 'Dodatečné informace');
		$form->addDataMultiSelect('categories', 'Kategorie', $this->categoryRepository->getArrayForSelect());
		$form->addText('priority', 'Priorita')
			->addRule($form::INTEGER)
			->setRequired()
			->setDefaultValue(10);
		$form->addCheckbox('showProduct', 'Náhled')->setHtmlAttribute('data-info', 'Atribut se zobrazí v náhledu produktu.');
		$form->addCheckbox('hidden', 'Skryto');

		$form->addGroup('Filtr');
		$form->addCheckbox('showFilter', 'Filtr')->setHtmlAttribute('data-info', 'Atribut se zobrazí při filtrování.');
		$form->addSelect('filterType', 'Typ filtru', Attribute::FILTER_TYPES);

		if (isset(static::CONFIGURATIONS['wizard']) && static::CONFIGURATIONS['wizard']) {
			$form->addGroup('Průvodce');
			$form->addCheckbox('showWizard', 'Zobrazit v průvodci');
			$form->addSelect('wizardStep', 'Pozice v průvodci (krok)', static::CONFIGURATIONS['wizardSteps']);
			$form->addText('wizardLabel', 'Název v průvodci')->setNullable()->setHtmlAttribute('data-info', 'Pokud necháte prázdné, použije se název atributu.');
		}

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
		$source = $this->attributeValueRepository->many()->setGroupBy(['this.uuid'])
			->select(['assignCount' => 'COUNT(assign.uuid)', 'supplierName' => 'supplier.name'])
			->join(['attribute' => 'eshop_attribute'], 'this.fk_attribute = attribute.uuid')
			->join(['supplier' => 'eshop_supplier'], 'attribute.fk_supplier = supplier.uuid')
			->join(['assign' => 'eshop_attributeassign'], 'this.uuid = assign.fk_value');

		$grid = $this->gridFactory->create($source, 20, 'code', 'ASC', true);

		$grid->setItemCountCallback(function (ICollection $filteredSource) {
			return (int)$this->attributeRepository->getConnection()->rows()->select(['count' => 'count(*)'])->from(['derived' => $filteredSource->setSelect(['uuid' => 'this.uuid', 'assignCount' => 'COUNT(assign.uuid)'])], $filteredSource->getVars())->firstValue('count');
		});

		$grid->addColumnSelector();
		$grid->addColumnText('Kód', 'code', '%s', 'code');
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
		$grid->addFilterTextInput('attribute', ['attribute.code'], null, 'Kód atributu', null, '%s');

		if ($suppliers = $this->supplierRepository->getArrayForSelect()) {
			$grid->addFilterDataSelect(function (Collection $source, $value) {
				$source->where('supplier.uuid', $value);
			}, '', 'supplier', null, $suppliers)->setPrompt('- Zdroj -');
		}

		$grid->addFilterDataSelect(function (Collection $source, $value) {
			if ($value === null) {
				$source->setGroupBy(['this.uuid']);
			} else {
				$source->setGroupBy(['this.uuid'], $value == 1 ? 'assignCount != 0' : 'assignCount = 0');
			}
		}, '', 'assign', null, [0 => 'Pouze nepřiřazené', 1 => 'Pouze přiřazené'])->setPrompt('- Přiřazené -');

		$grid->addFilterButtons(['default']);

		if ($this->formFactory->getPrettyPages()) {
			$submit = $grid->getForm()->addSubmit('createPages', 'Vytvořit stránky')->setHtmlAttribute('class', 'btn btn-outline-primary btn-sm');

			$submit->onClick[] = function ($button) use ($grid) {
				$grid->getPresenter()->redirect('createPages', [$grid->getSelectedIds(), true]);
			};

			$submit = $grid->getForm()->addSubmit('deletePages', 'Smazat stránky')->setHtmlAttribute('class', 'btn btn-outline-primary btn-sm');

			$submit->onClick[] = function ($button) use ($grid) {
				$grid->getPresenter()->redirect('createPages', [$grid->getSelectedIds(), false]);
			};
		}

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

		$nameInput = $form->addLocaleText('label', 'Popisek');

		$form->addLocaleTextArea('note', 'Dodatečné informace');
		$form->addText('metaValue', 'Doprovodná hodnota');
		$form->addText('number', 'Číselná reprezentace')->addFilter('floatval')->setNullable()->addCondition($form::FILLED)->addRule($form::FLOAT);
		$form->addText('priority', 'Priorita')
			->addRule($form::INTEGER)
			->setRequired()
			->setDefaultValue(10);
		$form->addCheckbox('hidden', 'Skryto');

		if (isset(static::CONFIGURATIONS['wizard']) && static::CONFIGURATIONS['wizard']) {
			$form->addGroup('Průvodce');
			$form->addCheckbox('showWizard', 'Zobrazit v průvodci');
//			$form->addText('wizardLabel', 'Název v průvodci')->setNullable()->setHtmlAttribute('data-info', 'Pokud necháte prázdné, použije se popisek.');
		}

		if ($form->getPrettyPages()) {
//			$form->addCheckbox('standalonePage', 'Samostatná stránka');

//			if ($this->pageRepository->getPageByTypeAndParams('product_list', null, ['attributeValue' => $this->getParameter('attributeValue')])) {
//				$form['standalonePage']->setDefaultValue(true);
			$form->addPageContainer('product_list', ['attributeValue' => null], $nameInput, false, false, true, 'Stránka');
//			}
		}

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

			/** @var AttributeValue $object */
			$object = $this->attributeValueRepository->syncOne($values, null, true);

			if (!$values['page']['url'][Arrays::first($this->formFactory->getMutations())]) {
				foreach ($this->pageRepository->getConnection()->getAvailableMutations() as $mutation => $suffix) {
					$page = $this->pageRepository->getPageByTypeAndParams('product_list', $mutation, ['attributeValue' => $this->getParameter('attributeValue')]);

					if ($page) {
						$page->delete();
					}
				}
			} else {
				$values['page']['type'] = 'product_list';
				$values['page']['params'] = Helpers::serializeParameters(['attributeValue' => $object->getPK()]);

				$this->pageRepository->syncOne($values['page']);
			}

//			if (isset($values['standalonePage']) && $values['standalonePage']) {
//				$page = $this->pageRepository->getPageByTypeAndParams('product_list', null, ['attributeValue' => $this->getParameter('attributeValue')]);
//				$object = $this->attributeValueRepository->one($object->getPK());
//
//				if (!$page) {
//					$values['page']['type'] = 'product_list';
//
//					$url = \strtolower($this->removeAccents($object->attribute->name . '-' . $object->label));
//					$url = \preg_replace('~[^a-z0-9_/-]+~', '-', $url);
//					$url = \preg_replace('~-+~', '-', $url);
//					$url = \preg_replace('~^-~', '', $url);
//					$url = \preg_replace('~-$~', '', $url);
//					$url = \urlencode($url);
//
//					if (!$this->pageRepository->isUrlAvailable($url, Arrays::first($this->formFactory->getMutations()))) {
//						$url = Random::generate(4, '0-9') . '-' . $url;
//					}
//
//					$values['page']['url'][Arrays::first($this->formFactory->getMutations())] = $url;
//					$values['page']['title'][Arrays::first($this->formFactory->getMutations())] = $object->attribute->name . ' - ' . $object->label;
//				}
//
//				$values['page']['params'] = Helpers::serializeParameters(['attributeValue' => $object->getPK()]);
//
//				$this->pageRepository->syncOne($values['page']);
//			}

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

		if ($form->getPrettyPages()) {
			if ($page = $this->pageRepository->getPageByTypeAndParams('product_list', null, ['attributeValue' => $attributeValue->getPK()])) {
				$form['page']->setDefaults($page->toArray());

				$form['page']['url']->forAll(function (TextInput $text, $mutation) use ($page, $form) {
					$text->getRules()->reset();
					$text->addRule([$form, 'validateUrl'], 'URL již existuje', [$this->pageRepository, $mutation, $page->getPK()]);
				});
			}
		}
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

	protected function removeAccents(string $string): string
	{
		if (!preg_match('/[\x80-\xff]/', $string))
			return $string;

		$chars = array(
			// Decompositions for Latin-1 Supplement
			chr(195) . chr(128) => 'A', chr(195) . chr(129) => 'A',
			chr(195) . chr(130) => 'A', chr(195) . chr(131) => 'A',
			chr(195) . chr(132) => 'A', chr(195) . chr(133) => 'A',
			chr(195) . chr(135) => 'C', chr(195) . chr(136) => 'E',
			chr(195) . chr(137) => 'E', chr(195) . chr(138) => 'E',
			chr(195) . chr(139) => 'E', chr(195) . chr(140) => 'I',
			chr(195) . chr(141) => 'I', chr(195) . chr(142) => 'I',
			chr(195) . chr(143) => 'I', chr(195) . chr(145) => 'N',
			chr(195) . chr(146) => 'O', chr(195) . chr(147) => 'O',
			chr(195) . chr(148) => 'O', chr(195) . chr(149) => 'O',
			chr(195) . chr(150) => 'O', chr(195) . chr(153) => 'U',
			chr(195) . chr(154) => 'U', chr(195) . chr(155) => 'U',
			chr(195) . chr(156) => 'U', chr(195) . chr(157) => 'Y',
			chr(195) . chr(159) => 's', chr(195) . chr(160) => 'a',
			chr(195) . chr(161) => 'a', chr(195) . chr(162) => 'a',
			chr(195) . chr(163) => 'a', chr(195) . chr(164) => 'a',
			chr(195) . chr(165) => 'a', chr(195) . chr(167) => 'c',
			chr(195) . chr(168) => 'e', chr(195) . chr(169) => 'e',
			chr(195) . chr(170) => 'e', chr(195) . chr(171) => 'e',
			chr(195) . chr(172) => 'i', chr(195) . chr(173) => 'i',
			chr(195) . chr(174) => 'i', chr(195) . chr(175) => 'i',
			chr(195) . chr(177) => 'n', chr(195) . chr(178) => 'o',
			chr(195) . chr(179) => 'o', chr(195) . chr(180) => 'o',
			chr(195) . chr(181) => 'o', chr(195) . chr(182) => 'o',
			chr(195) . chr(182) => 'o', chr(195) . chr(185) => 'u',
			chr(195) . chr(186) => 'u', chr(195) . chr(187) => 'u',
			chr(195) . chr(188) => 'u', chr(195) . chr(189) => 'y',
			chr(195) . chr(191) => 'y',
			// Decompositions for Latin Extended-A
			chr(196) . chr(128) => 'A', chr(196) . chr(129) => 'a',
			chr(196) . chr(130) => 'A', chr(196) . chr(131) => 'a',
			chr(196) . chr(132) => 'A', chr(196) . chr(133) => 'a',
			chr(196) . chr(134) => 'C', chr(196) . chr(135) => 'c',
			chr(196) . chr(136) => 'C', chr(196) . chr(137) => 'c',
			chr(196) . chr(138) => 'C', chr(196) . chr(139) => 'c',
			chr(196) . chr(140) => 'C', chr(196) . chr(141) => 'c',
			chr(196) . chr(142) => 'D', chr(196) . chr(143) => 'd',
			chr(196) . chr(144) => 'D', chr(196) . chr(145) => 'd',
			chr(196) . chr(146) => 'E', chr(196) . chr(147) => 'e',
			chr(196) . chr(148) => 'E', chr(196) . chr(149) => 'e',
			chr(196) . chr(150) => 'E', chr(196) . chr(151) => 'e',
			chr(196) . chr(152) => 'E', chr(196) . chr(153) => 'e',
			chr(196) . chr(154) => 'E', chr(196) . chr(155) => 'e',
			chr(196) . chr(156) => 'G', chr(196) . chr(157) => 'g',
			chr(196) . chr(158) => 'G', chr(196) . chr(159) => 'g',
			chr(196) . chr(160) => 'G', chr(196) . chr(161) => 'g',
			chr(196) . chr(162) => 'G', chr(196) . chr(163) => 'g',
			chr(196) . chr(164) => 'H', chr(196) . chr(165) => 'h',
			chr(196) . chr(166) => 'H', chr(196) . chr(167) => 'h',
			chr(196) . chr(168) => 'I', chr(196) . chr(169) => 'i',
			chr(196) . chr(170) => 'I', chr(196) . chr(171) => 'i',
			chr(196) . chr(172) => 'I', chr(196) . chr(173) => 'i',
			chr(196) . chr(174) => 'I', chr(196) . chr(175) => 'i',
			chr(196) . chr(176) => 'I', chr(196) . chr(177) => 'i',
			chr(196) . chr(178) => 'IJ', chr(196) . chr(179) => 'ij',
			chr(196) . chr(180) => 'J', chr(196) . chr(181) => 'j',
			chr(196) . chr(182) => 'K', chr(196) . chr(183) => 'k',
			chr(196) . chr(184) => 'k', chr(196) . chr(185) => 'L',
			chr(196) . chr(186) => 'l', chr(196) . chr(187) => 'L',
			chr(196) . chr(188) => 'l', chr(196) . chr(189) => 'L',
			chr(196) . chr(190) => 'l', chr(196) . chr(191) => 'L',
			chr(197) . chr(128) => 'l', chr(197) . chr(129) => 'L',
			chr(197) . chr(130) => 'l', chr(197) . chr(131) => 'N',
			chr(197) . chr(132) => 'n', chr(197) . chr(133) => 'N',
			chr(197) . chr(134) => 'n', chr(197) . chr(135) => 'N',
			chr(197) . chr(136) => 'n', chr(197) . chr(137) => 'N',
			chr(197) . chr(138) => 'n', chr(197) . chr(139) => 'N',
			chr(197) . chr(140) => 'O', chr(197) . chr(141) => 'o',
			chr(197) . chr(142) => 'O', chr(197) . chr(143) => 'o',
			chr(197) . chr(144) => 'O', chr(197) . chr(145) => 'o',
			chr(197) . chr(146) => 'OE', chr(197) . chr(147) => 'oe',
			chr(197) . chr(148) => 'R', chr(197) . chr(149) => 'r',
			chr(197) . chr(150) => 'R', chr(197) . chr(151) => 'r',
			chr(197) . chr(152) => 'R', chr(197) . chr(153) => 'r',
			chr(197) . chr(154) => 'S', chr(197) . chr(155) => 's',
			chr(197) . chr(156) => 'S', chr(197) . chr(157) => 's',
			chr(197) . chr(158) => 'S', chr(197) . chr(159) => 's',
			chr(197) . chr(160) => 'S', chr(197) . chr(161) => 's',
			chr(197) . chr(162) => 'T', chr(197) . chr(163) => 't',
			chr(197) . chr(164) => 'T', chr(197) . chr(165) => 't',
			chr(197) . chr(166) => 'T', chr(197) . chr(167) => 't',
			chr(197) . chr(168) => 'U', chr(197) . chr(169) => 'u',
			chr(197) . chr(170) => 'U', chr(197) . chr(171) => 'u',
			chr(197) . chr(172) => 'U', chr(197) . chr(173) => 'u',
			chr(197) . chr(174) => 'U', chr(197) . chr(175) => 'u',
			chr(197) . chr(176) => 'U', chr(197) . chr(177) => 'u',
			chr(197) . chr(178) => 'U', chr(197) . chr(179) => 'u',
			chr(197) . chr(180) => 'W', chr(197) . chr(181) => 'w',
			chr(197) . chr(182) => 'Y', chr(197) . chr(183) => 'y',
			chr(197) . chr(184) => 'Y', chr(197) . chr(185) => 'Z',
			chr(197) . chr(186) => 'z', chr(197) . chr(187) => 'Z',
			chr(197) . chr(188) => 'z', chr(197) . chr(189) => 'Z',
			chr(197) . chr(190) => 'z', chr(197) . chr(191) => 's'
		);

		$string = strtr($string, $chars);

		return $string;
	}

	public function actionCreatePages(array $ids, bool $createOrDelete)
	{
	}


	public function renderCreatePages(array $ids, bool $createOrDelete)
	{
		$this->template->headerLabel = 'Vytvořit stránky';
		$this->template->headerTree = [
			['Atributy', 'default'],
			['Hodnoty', 'default']
		];
		$this->template->displayButtons = [$this->createBackButton('default')];
		$this->template->displayControls = [$this->getComponent('createPagesForm')];
	}

	public function createComponentCreatePagesForm()
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

			$form->addLocaleText('title', 'Titulek')->forAll(function (TextInput $text) {
				$text->setHtmlAttribute('data-characters', 70);
			});

			$form->addLocaleTextArea('description', 'Popisek')->forAll(function (TextArea $text) {
				$text->setHtmlAttribute('style', 'width: 862px !important;')
					->setHtmlAttribute('data-characters', 150);
			});

			$form->addGroup();

			$form->addSubmit('submit', 'Vytvořit / Upravit');
		} else {
			$form->addSubmit('delete', 'Smazat')->setHtmlAttribute('class', 'btn btn-danger btn-sm ml-0 mt-1 mb-1 mr-1');
		}

		$form->onSuccess[] = function (AdminForm $form) use ($ids, $productGrid) {
			$values = $form->getValues('array');
			$submitName = $form->isSubmitted()->getName();

			/** @var AttributeValue[] $attributeValues */
			$attributeValues = $values['bulkType'] == 'selected' ? $this->attributeValueRepository->many()->where('uuid', $ids) : $productGrid->getFilteredSource();

			if ($submitName == 'submit') {
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

							$url = \strtolower($this->removeAccents($attributeName . '-' . $attributeValueLabel));
							$url = \preg_replace('~[^a-z0-9_/-]+~', '-', $url);
							$url = \preg_replace('~-+~', '-', $url);
							$url = \preg_replace('~^-~', '', $url);
							$url = \preg_replace('~-$~', '', $url);
							$url = \urlencode($url);

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
			} elseif ($submitName == 'delete') {
				foreach ($attributeValues as $attributeValue) {
					$page = $this->pageRepository->getPageByTypeAndParams('product_list', null, ['attributeValue' => $attributeValue->getPK()]);

					if ($page) {
						$page->delete();
					}
				}
			}

			$this->redirect('default');
		};

		return $form;
	}
}