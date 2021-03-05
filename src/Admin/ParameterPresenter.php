<?php

declare(strict_types=1);

namespace Eshop\Admin;

use App\Admin\Controls\AdminForm;
use App\Admin\Controls\AdminFormFactory;
use App\Admin\Controls\AdminGrid;
use App\Admin\Controls\AdminGridFactory;
use App\Admin\PresenterTrait;
use Eshop\DB\Parameter;
use Eshop\DB\ParameterCategory;
use Eshop\DB\ParameterCategoryRepository;
use Eshop\DB\ParameterGroup;
use Eshop\DB\ParameterGroupRepository;
use Eshop\DB\ParameterRepository;
use Eshop\DB\Product;
use Forms\Form;
use StORM\DIConnection;
use StORM\ICollection;
use Tracy\Debugger;

class ParameterPresenter extends \Nette\Application\UI\Presenter
{
	use PresenterTrait;

	/** @inject */
	public ParameterRepository $parameterRepository;

	/** @inject */
	public ParameterGroupRepository $groupRepository;

	/** @inject */
	public ParameterCategoryRepository $parameterCategoryRepo;

	public const TABS = [
		'categories' => 'Kategorie',
		'groups' => 'Skupiny',
	];

	/** @persistent */
	public string $tab = 'categories';

	private const TYPES = [
		'bool' => 'Ano / Ne',
		'list' => 'Seznam',
		'text' => 'Text',
	];

	public function createComponentGrid(): AdminGrid
	{
		$grid = $this->gridFactory->create($this->parameterRepository->many()->where('fk_group', $this->getParameter('group')->getPK()), 20, 'priority', 'ASC', true);
		$grid->addColumnSelector();
		$grid->addColumnText('Název', 'name', '%s', 'name');
		$grid->addColumn('Typ', function (Parameter $parameter, AdminGrid $dataGrid) {
			return $this::TYPES[$parameter->type];
		}, '%s', 'type');
		$grid->addColumnInputInteger('Priorita', 'priority', '', '', 'priority', [], true);
		$grid->addColumnInputCheckbox('Náhled', 'isPreview', '', '', 'isPreview');
		$grid->addColumnInputCheckbox('<i title="Skryto" class="far fa-eye-slash"></i>', 'hidden', '', '', 'hidden');

		$grid->addColumnLinkDetail('Detail', ['group' => $this->getParameter('group')]);
		$grid->addColumnActionDeleteSystemic();

		$grid->addButtonSaveAll();
		$grid->addButtonDeleteSelected();

		$grid->addFilterTextInput('search', ['name_cs'], null, 'Název');
		$grid->addFilterDataMultiSelect(function (ICollection $source, $value) {
			$source->where('type', $value);
		}, '', 'type', 'Typ', $this::TYPES, ['placeholder' => '- Typ -']);
		$grid->addFilterButtons(['default', $this->getParameter('group')]);

		return $grid;
	}

	public function createComponentParameterCategoriesGrid(): AdminGrid
	{
		$grid = $this->gridFactory->create($this->parameterCategoryRepo->many(), 20, 'priority', 'ASC', true);
		$grid->addColumnSelector();
		$grid->addColumnText('Název', 'name', '%s', 'name');
		$grid->addColumnInputInteger('Priorita', 'priority', '', '', 'priority', [], true);
		$grid->addColumnInputCheckbox('<i title="Skryto" class="far fa-eye-slash"></i>', 'hidden', '', '', 'hidden');

		$grid->addColumnLinkDetail('parameterCategoryDetail');
		$grid->addColumnActionDelete();

		$grid->addButtonSaveAll();
		$grid->addButtonDeleteSelected();

		$grid->addFilterTextInput('search', ['name_cs'], null, 'Název');
		$grid->addFilterButtons(['groupDefault']);

		return $grid;
	}

	public function createComponentNewForm(): Form
	{
		$form = $this->formFactory->create();

		$form->addLocaleText('name', 'Název');

		/** @var Parameter $parameter */
		$parameter = $this->getParameter('parameter');

		$form->addLocaleTextArea('description','Popisek');

		$form->addSelect('type', 'Typ', $this::TYPES)->setHtmlAttribute('onchange', 'onTypeChange(this)');

		$localeContainer = $form->addLocaleText('allowedValues', 'Povolené hodnoty');
		$localeContainer->getComponents()['cs']->setHtmlAttribute('data-info', 'Zadejte hodnoty oddělené středníkem. Např.: "Test; Test2;". Poznámka: Řetězec musí vždy končit středníkem!');

		$form->addText('priority', 'Priorita')
			->addRule($form::INTEGER)
			->setRequired()
			->setDefaultValue(10);
		$form->addCheckbox('isPreview', 'Náhled')->setHtmlAttribute('data-info', 'Parametr se zobrazí v náhledu produktu.');
		$form->addCheckbox('hidden', 'Skryto');

		$form->addSubmits(!$this->getParameter('parameter'));

		$form->onSuccess[] = function (AdminForm $form) use ($parameter) {
			$values = $form->getValues('array');

			if (!$values['uuid']) {
				$values['uuid'] = DIConnection::generateUuid();
			}

			$values['group'] = $this->getParameter('group') ? $this->getParameter('group')->getPK() : $parameter->group->getPK();

			$parameter = $this->parameterRepository->syncOne($values, null, true);

			$form->getPresenter()->flashMessage('Uloženo', 'success');

			$form->processRedirect('detail', 'default',
				['parameter' => $parameter, 'group' => $this->getParameter('group')],
				['group' => $this->getParameter('group')],
			);
		};

		return $form;
	}

	public function renderDefault(ParameterGroup $group, ?string $backLink = null)
	{
		$this->template->headerLabel = 'Parametry skupiny: ' . $group->internalName;
		$this->template->headerTree = [
			['Skupiny parametrů', 'groupDefault'],
			['Parametry'],
		];
		$this->template->displayButtons = [$this->createBackButton('groupDefault'), $this->createNewItemButton('new', [$group])];
		$this->template->displayControls = [$this->getComponent('grid')];
	}

	public function renderNew(ParameterGroup $group, ?string $backLink = null)
	{
		$this->template->setFile(__DIR__ . \DIRECTORY_SEPARATOR . 'templates' . \DIRECTORY_SEPARATOR . '_parameters-js.latte');
		$this->template->headerLabel = 'Nová položka';
		$this->template->headerTree = [
			['Skupiny parametrů', 'groupDefault'],
			['Parametry', 'default', $group],
			['Nová položka'],
		];
		$this->template->displayButtons = [$this->createBackButton('default', $group)];
		$this->template->displayControls = [$this->getComponent('newForm')];
	}

	public function renderDetail(Parameter $parameter, ?string $backLink = null)
	{
		$this->template->setFile(__DIR__ . \DIRECTORY_SEPARATOR . 'templates' . \DIRECTORY_SEPARATOR . '_parameters-js.latte');
		$this->template->headerLabel = 'Detail';
		$this->template->headerTree = [
			['Skupiny parametrů', 'groupDefault'],
			['Parametry', 'default', $parameter->group],
			['Detail'],
		];
		$this->template->displayButtons = [$this->createBackButton('default', $parameter->group)];
		$this->template->displayControls = [$this->getComponent('newForm')];
	}

	public function renderParameterCategoryNew()
	{
		$this->template->setFile(__DIR__ . \DIRECTORY_SEPARATOR . 'templates' . \DIRECTORY_SEPARATOR . '_parameters-js.latte');
		$this->template->headerLabel = 'Nová položka';
		$this->template->headerTree = [
			['Parametry', 'groupDefault'],
			['Kategorie'],
			['Nová položka'],
		];
		$this->template->displayButtons = [$this->createBackButton('groupDefault')];
		$this->template->displayControls = [$this->getComponent('parameterCategoryForm')];
	}

	public function renderParameterCategoryDetail(ParameterCategory $parameterCategory, ?string $backLink = null)
	{
		$this->template->setFile(__DIR__ . \DIRECTORY_SEPARATOR . 'templates' . \DIRECTORY_SEPARATOR . '_parameters-js.latte');
		$this->template->headerLabel = 'Detail';
		$this->template->headerTree = [
			['Parametry', 'groupDefault'],
			['Kategorie'],
			['Detail'],
		];
		$this->template->displayButtons = [$this->createBackButton('groupDefault')];
		$this->template->displayControls = [$this->getComponent('parameterCategoryForm')];
	}

	public function actionDefault(?ParameterGroup $group, ?string $backLink = null)
	{
	}

	public function actionNew(ParameterGroup $group, ?string $backLink = null)
	{
	}

	public function actionDetail(Parameter $parameter, ParameterGroup $group, ?string $backLink = null)
	{
		/** @var Form $form */
		$form = $this->getComponent('newForm');

		$form->setDefaults($parameter->toArray());
	}

	public function createComponentGroupGrid(): AdminGrid
	{
		$grid = $this->gridFactory->create($this->groupRepository->many(), 20, 'parameterCategory.name', 'ASC', true);
		$grid->addColumnSelector();

		$grid->addColumnText('Název', 'name', '%s', 'name');
		$grid->addColumnText('Interní název', 'internalName', '%s', 'internalName');

		$grid->addColumn('Kategorie parametrů', function (ParameterGroup $object, $datagrid) {
			$link = $this->admin->isAllowed(':Eshop:Admin:Parameter:parameterCategoryDetail') && $object->parameterCategory ? $datagrid->getPresenter()->link(':Eshop:Admin:Parameter:parameterCategoryDetail', [$object->parameterCategory, 'backLink' => $this->storeRequest()]) : '#';

			return $object->parameterCategory ? "<a href='$link'><i class='fa fa-external-link-alt fa-sm'></i>&nbsp;" . $object->parameterCategory->name . "</a>" : '';
		}, '%s');

		$grid->addColumnInputInteger('Priorita', 'priority', '', '', 'priority', [], true);
		$grid->addColumnInputCheckbox('<i title="Skryto" class="far fa-eye-slash"></i>', 'hidden', '', '', 'hidden');
		$grid->addColumnLink('default', 'Parametry');
		$grid->addColumnLinkDetail('groupDetail');
		$grid->addColumnActionDeleteSystemic();

		$grid->addButtonSaveAll();
		$grid->addButtonDeleteSelected();

		$grid->addFilterTextInput('search', ['name_cs'], null, 'Název');
		$grid->addFilterDataMultiSelect(function (ICollection $source, $value) {
			$source->where('fk_parameterCategory', $value);
		}, '', 'category', 'Kategorie parametrů', $this->parameterCategoryRepo->getArrayForSelect(), ['placeholder' => '- Kategorie parametrů -']);
		$grid->addFilterButtons(['groupDefault']);

		return $grid;
	}

	public function createComponentGroupNewForm(): Form
	{
		$form = $this->formFactory->create();

		$form->addLocaleText('name', 'Název');
		$form->addText('internalName', 'Interní název')->setNullable();
		$form->addText('priority', 'Priorita')
			->addRule($form::INTEGER)
			->setRequired()
			->setDefaultValue(10);
		$form->addCheckbox('hidden', 'Skryto');
		$form->addDataSelect('parameterCategory', 'Kategorie parametrů', $this->parameterCategoryRepo->getArrayForSelect())->setRequired();

		$form->addSubmits(!$this->getParameter('group'));

		$form->onSuccess[] = function (AdminForm $form) {
			$values = $form->getValues('array');

			if (!$values['uuid']) {
				$values['uuid'] = DIConnection::generateUuid();
			}

			$group = $this->groupRepository->syncOne($values, null, true);

			$this->flashMessage('Uloženo', 'success');
			$form->processRedirect('groupDetail', 'groupDefault', [$group]);
		};

		return $form;
	}

	public function createComponentParameterCategoryForm(): Form
	{
		$form = $this->formFactory->create();

		$form->addLocaleText('name', 'Název');
		$form->addText('priority', 'Priorita')
			->addRule($form::INTEGER)
			->setRequired()
			->setDefaultValue(10);
		$form->addCheckbox('hidden', 'Skryto');

		$form->addSubmits(!$this->getParameter('parameterCategory'));

		$form->onSuccess[] = function (AdminForm $form) {
			$values = $form->getValues('array');

			if (!$values['uuid']) {
				$values['uuid'] = DIConnection::generateUuid();
			}

			$parameterCategory = $this->parameterCategoryRepo->syncOne($values, null, true);

			$this->flashMessage('Uloženo', 'success');
			$form->processRedirect('parameterCategoryDetail', 'groupDefault', [$parameterCategory]);
		};

		return $form;
	}

	public function renderGroupDefault()
	{
		$this->template->headerLabel = 'Parametry';
		$this->template->headerTree = [
			['Parametry', 'this'],
			[self::TABS[$this->tab]]
		];

		if ($this->tab == 'groups') {
			$this->template->displayButtons = [$this->createNewItemButton('groupNew')];
			$this->template->displayControls = [$this->getComponent('groupGrid')];
		} elseif ($this->tab == 'categories') {
			$this->template->displayButtons = [$this->createNewItemButton('parameterCategoryNew')];
			$this->template->displayControls = [$this->getComponent('parameterCategoriesGrid')];
		}

		$this->template->tabs = self::TABS;
	}

	public function renderGroupNew()
	{
		$this->template->headerLabel = 'Nová položka';
		$this->template->headerTree = [
			['Skupiny parametrů', 'default'],
			['Nová položka'],
		];
		$this->template->displayButtons = [$this->createBackButton('groupDefault')];
		$this->template->displayControls = [$this->getComponent('groupNewForm')];
	}

	public function renderGroupDetail(ParameterGroup $group, ?string $backLink = null)
	{
		$this->template->headerLabel = 'Detail';
		$this->template->headerTree = [
			['Skupiny parametrů', 'default'],
			['Detail'],
		];
		$this->template->displayButtons = [$this->createBackButton('groupDefault')];
		$this->template->displayControls = [$this->getComponent('groupNewForm')];
	}

	public function actionGroupDetail(ParameterGroup $group, $back = null)
	{
		/** @var Form $form */
		$form = $this->getComponent('groupNewForm');

		$form->setDefaults($group->toArray());
	}

}