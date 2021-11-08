<?php

declare(strict_types=1);

namespace Eshop\Admin;

use Admin\BackendPresenter;
use Admin\Controls\AdminForm;
use Admin\Controls\AdminGrid;
use Eshop\DB\Parameter;
use Eshop\DB\ParameterAvailableValueRepository;
use Eshop\DB\ParameterCategory;
use Eshop\DB\ParameterCategoryRepository;
use Eshop\DB\ParameterGroup;
use Eshop\DB\ParameterGroupRepository;
use Eshop\DB\ParameterRepository;
use Forms\Form;
use StORM\DIConnection;
use StORM\ICollection;

class ParameterPresenter extends BackendPresenter
{
	public const TABS = [
		'groups' => 'Skupiny',
		'categories' => 'Kategorie',
	];

	protected const TYPES = [
		'bool' => 'Ano / Ne',
		'list' => 'Seznam',
//      'text' => 'Text',
	];

	/** @inject */
	public ParameterRepository $parameterRepository;

	/** @inject */
	public ParameterGroupRepository $groupRepository;

	/** @inject */
	public ParameterCategoryRepository $parameterCategoryRepo;

	/** @inject */
	public ParameterAvailableValueRepository $parameterAvailableValueRepository;

	/** @persistent */
	public string $tab = 'groups';

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
		$grid->addFilterDataMultiSelect(function (ICollection $source, $value): void {
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
		$form = $this->formFactory->create(true);

		$form->addLocaleText('name', 'Název');

		/** @var \Eshop\DB\Parameter $parameter */
		$parameter = $this->getParameter('parameter');

		$form->addLocaleTextArea('description', 'Popisek');

		$form->addSelect('type', 'Typ', $this::TYPES)->setHtmlAttribute('onchange', 'onTypeChange(this)')
			->setHtmlAttribute('data-info', 'Pozor! Pokud změníte typ a existují již vazby tohoto parametru a produktů, tak budou veškeré vazby a hodnoty smazány!');

		$form->addText('allowedKeys', 'Povolené klíče')
			->setHtmlAttribute('data-info', "Zadejte hodnoty oddělené středníkem. Např.: \"red; blue\". Počet položek musí být stejný jako v poli \"Povolené hodnoty\"<br>
			Pozor! Pokud změníte jakkoliv klíče, dojde k smazání všech vazeb mezi parametrem a produkty!");

		$localeContainer = $form->addLocaleText('allowedValues', 'Povolené hodnoty');
		$localeContainer->getComponents()['cs']->setHtmlAttribute('data-info', 'Zadejte hodnoty oddělené středníkem. Např.: "Červená; Modrá". Počet položek musí být stejný jako v poli "Povolené klíče"');

		$form->addSelect('filterType', 'Typ filtru', Parameter::FILTER_TYPES);
		$form->addText('priority', 'Priorita')
			->addRule($form::INTEGER)
			->setRequired()
			->setDefaultValue(10);
		$form->addCheckbox('isPreview', 'Náhled')->setHtmlAttribute('data-info', 'Parametr se zobrazí v náhledu produktu.');
		$form->addCheckbox('hidden', 'Skryto');

		$form->addSubmits(!$parameter);

		$form->onValidate[] = function (AdminForm $form): void {
			$values = $form->getValues('array');

			if ($values['type'] === 'list' && (!$values['allowedKeys'] || !$values['allowedValues'])) {
				$form['allowedKeys']->addError('Toto pole je povinné!');
				$form['allowedValues']->addError('Toto pole je povinné!');
			}

			if (!$values['allowedKeys'] || !$values['allowedValues']) {
				return;
			}

			$keysCount = \count(\explode(';', $values['allowedKeys']));

			foreach ($form->getMutations() as $mutation) {
				if ($keysCount !== \count(\explode(';', $values['allowedValues'][$mutation]))) {
					$form['allowedKeys']->addError('Nesprávný počet položek!');

					break;
				}
			}
		};

		$form->onSuccess[] = function (AdminForm $form) use ($parameter): void {
			$values = $form->getValues('array');

			if (!$values['uuid']) {
				$values['uuid'] = DIConnection::generateUuid();
				$oldParameter = null;
			} else {
				/** @var \Eshop\DB\Parameter $oldParameter */
				$oldParameter = $this->parameterRepository->one($values['uuid']);

				if ($values['type'] !== $oldParameter->type) {
					$this->parameterAvailableValueRepository->many()->where('fk_parameter', $values['uuid'])->delete();
				}
			}

			$values['group'] = $this->getParameter('group') ? $this->getParameter('group')->getPK() : $parameter->group->getPK();

			$parameter = $this->parameterRepository->syncOne($values, null, true);

			if ($values['type'] === 'bool') {
				if (!$existingValue = $this->parameterAvailableValueRepository->many()->where('allowedKey', '0')->where('fk_parameter', $parameter->getPK())->first()) {
					$this->parameterAvailableValueRepository->createOne([
						'allowedKey' => '0',
						'parameter' => $parameter->getPK(),
					]);
				}

				if (!$existingValue = $this->parameterAvailableValueRepository->many()->where('allowedKey', '1')->where('fk_parameter', $parameter->getPK())->first()) {
					$this->parameterAvailableValueRepository->createOne([
						'allowedKey' => '1',
						'parameter' => $parameter->getPK(),
					]);
				}
			} elseif ($values['type'] === 'list') {
				$oldKeys = \array_values($this->parameterAvailableValueRepository->many()->where('fk_parameter', $values['uuid'])->toArrayOf('allowedKey'));
				$keys = \explode(';', $values['allowedKeys']);

				if (!(\count(\array_diff(\array_merge($oldKeys, $keys), \array_intersect($oldKeys, $keys))) === 0)) {
					$this->parameterAvailableValueRepository->many()->where('fk_parameter', $values['uuid'])->delete();
				}

				$parameterValues = [];

				foreach ($form->getMutations() as $mutation) {
					$mutationValues = \explode(';', $values['allowedValues'][$mutation]);
					$i = 0;

					foreach ($keys as $key) {
						$parameterValues[$key][$mutation] = $mutationValues[$i++];
					}
				}

				foreach ($keys as $key) {
					if (!$existingValue = $this->parameterAvailableValueRepository->many()->where('allowedKey', $key)->where('fk_parameter', $parameter->getPK())->first()) {
						$this->parameterAvailableValueRepository->syncOne([
							'allowedKey' => $key,
							'allowedValue' => $parameterValues[$key],
							'parameter' => $parameter->getPK(),
						]);
					} else {
						$existingValue->update([
							'allowedValue' => $parameterValues[$key],
						]);
					}
				}
			} elseif ($values['type'] === 'text') {
			}

			$form->getPresenter()->flashMessage('Uloženo', 'success');

			$form->processRedirect(
				'detail',
				'default',
				['parameter' => $parameter, 'group' => $this->getParameter('group')],
				['group' => $this->getParameter('group')],
			);
		};

		return $form;
	}

	public function renderDefault(ParameterGroup $group, ?string $backLink = null): void
	{
		$this->template->headerLabel = 'Parametry skupiny: ' . ($group->name ?? $group->internalName);
		$this->template->headerTree = [
			['Skupiny parametrů', 'groupDefault'],
			['Parametry'],
		];
		$this->template->displayButtons = [$this->createBackButton('groupDefault'), $this->createNewItemButton('new', [$group])];
		$this->template->displayControls = [$this->getComponent('grid')];
	}

	public function renderNew(ParameterGroup $group, ?string $backLink = null): void
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

	public function renderDetail(Parameter $parameter, ?string $backLink = null): void
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

	public function renderParameterCategoryNew(): void
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

	public function actionParameterCategoryDetail(ParameterCategory $parameterCategory, ?string $backLink = null): void
	{
		/** @var \Forms\Form $form */
		$form = $this->getComponent('parameterCategoryForm');

		$form->setDefaults($parameterCategory->toArray());
	}

	public function renderParameterCategoryDetail(ParameterCategory $parameterCategory, ?string $backLink = null): void
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

	public function actionDefault(?ParameterGroup $group, ?string $backLink = null): void
	{
	}

	public function actionNew(ParameterGroup $group, ?string $backLink = null): void
	{
	}

	public function actionDetail(Parameter $parameter, ParameterGroup $group, ?string $backLink = null): void
	{
		/** @var \Forms\Form $form */
		$form = $this->getComponent('newForm');

		$values = $parameter->toArray();

		if ($parameter->type === 'list') {
			$allowedKeys = \array_values($this->parameterAvailableValueRepository->many()->where('fk_parameter', $values['uuid'])->toArrayOf('allowedKey'));
			$values['allowedKeys'] = \implode(';', $allowedKeys);
			$values['allowedValues'] = [];

			foreach ($allowedKeys as $key) {
				$availableValue = $this->parameterAvailableValueRepository->many()->where('fk_parameter', $values['uuid'])->where('allowedKey', $key)->first();

				foreach ($form->getMutations() as $mutation) {
					if (isset($values['allowedValues'][$mutation])) {
						$values['allowedValues'][$mutation] .= $availableValue->getValue('allowedValue', $mutation) . ';';
					} else {
						$values['allowedValues'][$mutation] = $availableValue->getValue('allowedValue', $mutation) . ';';
					}
				}
			}

			foreach ($form->getMutations() as $mutation) {
				$values['allowedValues'][$mutation] = \substr($values['allowedValues'][$mutation], 0, -1);
			}
		}

		$form->setDefaults($values);
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
		$grid->addFilterDataMultiSelect(function (ICollection $source, $value): void {
			$source->where('fk_parameterCategory', $value);
		}, '', 'category', 'Kategorie parametrů', $this->parameterCategoryRepo->getArrayForSelect(), ['placeholder' => '- Kategorie parametrů -']);
		$grid->addFilterButtons(['groupDefault']);

		return $grid;
	}

	public function createComponentGroupNewForm(): Form
	{
		$form = $this->formFactory->create(true);

		$form->addLocaleText('name', 'Název');
		$form->addText('internalName', 'Interní název')->setNullable();
		$form->addText('priority', 'Priorita')
			->addRule($form::INTEGER)
			->setRequired()
			->setDefaultValue(10);
		$form->addCheckbox('hidden', 'Skryto');
		$form->addDataSelect('parameterCategory', 'Kategorie parametrů', $this->parameterCategoryRepo->getArrayForSelect())->setRequired();

		$form->addSubmits(!$this->getParameter('group'));

		$form->onSuccess[] = function (AdminForm $form): void {
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
		$form = $this->formFactory->create(true);

		$form->addLocaleText('name', 'Název');
		$form->addText('priority', 'Priorita')
			->addRule($form::INTEGER)
			->setRequired()
			->setDefaultValue(10);
		$form->addCheckbox('hidden', 'Skryto');

		$form->addSubmits(!$this->getParameter('parameterCategory'));

		$form->onSuccess[] = function (AdminForm $form): void {
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

	public function renderGroupDefault(): void
	{
		$this->template->headerLabel = 'Parametry';
		$this->template->headerTree = [
			['Parametry', 'this'],
			[self::TABS[$this->tab]],
		];

		if ($this->tab === 'groups') {
			$this->template->displayButtons = [$this->createNewItemButton('groupNew')];
			$this->template->displayControls = [$this->getComponent('groupGrid')];
		} elseif ($this->tab === 'categories') {
			$this->template->displayButtons = [$this->createNewItemButton('parameterCategoryNew')];
			$this->template->displayControls = [$this->getComponent('parameterCategoriesGrid')];
		}

		$this->template->tabs = self::TABS;
	}

	public function renderGroupNew(): void
	{
		$this->template->headerLabel = 'Nová položka';
		$this->template->headerTree = [
			['Skupiny parametrů', 'default'],
			['Nová položka'],
		];
		$this->template->displayButtons = [$this->createBackButton('groupDefault')];
		$this->template->displayControls = [$this->getComponent('groupNewForm')];
	}

	public function renderGroupDetail(ParameterGroup $group, ?string $backLink = null): void
	{
		$this->template->headerLabel = 'Detail';
		$this->template->headerTree = [
			['Skupiny parametrů', 'default'],
			['Detail'],
		];
		$this->template->displayButtons = [$this->createBackButton('groupDefault')];
		$this->template->displayControls = [$this->getComponent('groupNewForm')];
	}

	public function actionGroupDetail(ParameterGroup $group, $back = null): void
	{
		/** @var \Forms\Form $form */
		$form = $this->getComponent('groupNewForm');

		$form->setDefaults($group->toArray());
	}
}
