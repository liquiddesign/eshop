<?php

declare(strict_types=1);

namespace Eshop\Admin;

use Admin\BackendPresenter;
use App\Admin\Controls\AdminForm;
use App\Admin\Controls\AdminGrid;
use App\Admin\Controls\CustomValidators;
use App\Admin\PresenterTrait;
use Eshop\DB\ProductRepository;
use Eshop\DB\Related;
use Eshop\DB\RelatedRepository;
use Eshop\DB\RelatedType;
use Eshop\DB\RelatedTypeRepository;
use Forms\Form;
use StORM\DIConnection;
use StORM\ICollection;

class RelatedPresenter extends BackendPresenter
{
	/** @inject */
	public RelatedRepository $relatedRepository;

	/** @inject */
	public RelatedTypeRepository $relatedTypeRepository;

	/** @inject */
	public ProductRepository $productRepository;

	public const TABS = [
		'relations' => 'Vazby',
		'types' => 'Typy',
	];

	/** @persistent */
	public string $tab = 'relations';

	public function createComponentRelationGrid(): AdminGrid
	{
		$grid = $this->gridFactory->create($this->relatedRepository->many(), 20, 'priority', 'ASC', true);
		$grid->addColumnSelector();
		$grid->addColumn('Typ', function (Related $object, $datagrid) {
			$link = $this->admin->isAllowed(':Eshop:Admin:Related:detailType') && $object->type ? $datagrid->getPresenter()->link(':Eshop:Admin:Related:detailType', [$object->type, 'backLink' => $this->storeRequest()]) : '#';

			return $object->type ? "<a href='$link'><i class='fa fa-external-link-alt fa-sm'></i>&nbsp;" . $object->type->name . "</a>" : '';
		}, '%s');

		$grid->addColumn('Master produkt', function (Related $object, $datagrid) {
			$link = $this->admin->isAllowed(':Eshop:Admin:Product:edit') && $object->master ? $datagrid->getPresenter()->link(':Eshop:Admin:Product:edit', [$object->master, 'backLink' => $this->storeRequest()]) : '#';

			return $object->master ? "<a href='$link'><i class='fa fa-external-link-alt fa-sm'></i>&nbsp;" . $object->master->name . "</a>" : '';
		}, '%s');

		$grid->addColumn('Slave produkt', function (Related $object, $datagrid) {
			$link = $this->admin->isAllowed(':Eshop:Admin:Product:edit') && $object->slave ? $datagrid->getPresenter()->link(':Eshop:Admin:Product:edit', [$object->slave, 'backLink' => $this->storeRequest()]) : '#';

			return $object->slave ? "<a href='$link'><i class='fa fa-external-link-alt fa-sm'></i>&nbsp;" . $object->slave->name . "</a>" : '';
		}, '%s');

		$grid->addColumnInputInteger('Priorita', 'priority', '', '', 'priority', [], true);
		$grid->addColumnInputCheckbox('<i title="Skryto" class="far fa-eye-slash"></i>', 'hidden', '', '', 'hidden');

		$grid->addColumnLinkDetail('detailRelation');
		$grid->addColumnActionDelete();

		$grid->addButtonSaveAll();
		$grid->addButtonDeleteSelected();

		$types = $this->relatedTypeRepository->getArrayForSelect();

		if (\count($types) > 0) {
			$grid->addFilterDataMultiSelect(function (ICollection $source, $value) {
				$source->where('fk_type', $value);
			}, '- Typ -', 'type', 'Typ', $this->relatedTypeRepository->getArrayForSelect(), ['placeholder' => '- Typ -']);
		}

		$grid->addFilterButtons();

		return $grid;
	}

	public function createComponentRelationForm(): Form
	{
		$form = $this->formFactory->create();

		$form->addDataSelect('type', 'Typ', $this->relatedTypeRepository->getArrayForSelect())->setRequired();

		$form->addText('priority', 'Priorita')
			->addRule($form::INTEGER)
			->setRequired()
			->setDefaultValue(10);
		$form->addCheckbox('hidden', 'Skryto');

		$form->addText('master', 'První produkt')
			->setHtmlAttribute('data-info', 'Zadejte kód, subkód nebo EAN')
			->addRule(CustomValidators::IS_PRODUCT_EXISTS, 'Produkt neexistuje!', [
				$this->productRepository,
				$form
			])
			->setRequired();

		$form->addText('slave', 'Druhý produkt')
			->setHtmlAttribute('data-info', 'Zadejte kód, subkód nebo EAN')
			->addRule(CustomValidators::IS_PRODUCT_EXISTS, 'Produkt neexistuje!', [
				$this->productRepository,
				$form
			])
			->setRequired();

		$form->addSubmits(!$this->getParameter('relation'));

		$form->onSuccess[] = function (AdminForm $form) {
			$values = $form->getValues('array');

			$values['master'] = $this->productRepository->getProductByCodeOrEAN($values['master'])->getPK();
			$values['slave'] = $this->productRepository->getProductByCodeOrEAN($values['slave'])->getPK();

			if (!$values['uuid']) {
				$values['uuid'] = DIConnection::generateUuid();
			}

			$related = $this->relatedRepository->syncOne($values);

			$this->flashMessage('Uloženo', 'success');
			$form->processRedirect('detailRelation', 'default', [$related]);
		};

		return $form;
	}

	public function renderDefault()
	{
		$this->template->tabs = $this::TABS;
		$this->template->headerLabel = 'Vazby';
		$this->template->headerTree = [
			['Vazby'],
			[$this::TABS[$this->tab]]
		];

		if ($this->tab == 'relations') {
			$this->template->displayButtons = [$this->createNewItemButton('newRelation')];
			$this->template->displayControls = [$this->getComponent('relationGrid')];
		} elseif ($this->tab == 'types') {
			$this->template->displayButtons = [$this->createNewItemButton('newType')];
			$this->template->displayControls = [$this->getComponent('typeGrid')];
		}
	}

	public function renderNewRelation()
	{
		$this->template->headerLabel = 'Nová položka';
		$this->template->headerTree = [
			['Vazby', 'default'],
			['Nová položka'],
		];
		$this->template->displayButtons = [$this->createBackButton('default')];
		$this->template->displayControls = [$this->getComponent('relationForm')];
	}

	public function actionDetailRelation(Related $relation)
	{
		/** @var \Forms\Form $form */
		$form = $this->getComponent('relationForm');

		$form['uuid']->setDefaultValue($relation->getPK());
		$form['type']->setDefaultValue($relation->type);
		$form['hidden']->setDefaultValue($relation->hidden);
		$form['priority']->setDefaultValue($relation->priority);
		$form['master']->setDefaultValue($relation->master->getFullCode() ?? $relation->master->ean);
		$form['slave']->setDefaultValue($relation->slave->getFullCode() ?? $relation->slave->ean);
	}

	public function renderDetailRelation(Related $relation)
	{
		$this->template->headerLabel = 'Detail';
		$this->template->headerTree = [
			['Vazby', 'default'],
			['Detail']
		];
		$this->template->displayButtons = [$this->createBackButton('default')];
		$this->template->displayControls = [$this->getComponent('relationForm')];
	}

	public function createComponentTypeGrid()
	{
		$grid = $this->gridFactory->create($this->relatedTypeRepository->many(), 20, 'name_cs', 'ASC', true);
		$grid->addColumnSelector();
		$grid->addColumnText('Název', 'name', '%s', 'name');
		$grid->addColumnInputCheckbox('Podobný produkt', 'similar', '', '', 'similar');

		$grid->addColumnLinkDetail('detailType');
		$grid->addColumnActionDeleteSystemic();

		$grid->addButtonSaveAll();
		$grid->addButtonDeleteSelected();

		$grid->addFilterTextInput('name', ['name_cs'], 'Název', 'Název');
		$grid->addFilterButtons();

		return $grid;
	}

	public function createComponentTypeForm()
	{
		$form = $this->formFactory->create();

		$form->addLocaleText('name', 'Název');
		$form->addCheckbox('similar', 'Podobné');

		$form->addSubmits(!$this->getParameter('relatedType'));

		$form->onSuccess[] = function (AdminForm $form) {
			$values = $form->getValues('array');

			if (!$values['uuid']) {
				$values['uuid'] = DIConnection::generateUuid();
			}

			$relatedType = $this->relatedTypeRepository->syncOne($values);

			$this->flashMessage('Uloženo', 'success');
			$form->processRedirect('detailType', 'default', [$relatedType]);
		};

		return $form;
	}

	public function renderNewType()
	{
		$this->template->headerLabel = 'Nový typ';
		$this->template->headerTree = [
			['Vazby', 'default'],
			['Nový typ'],
		];
		$this->template->displayButtons = [$this->createBackButton('default')];
		$this->template->displayControls = [$this->getComponent('typeForm')];
	}

	public function actionDetailType(RelatedType $relatedType)
	{
		/** @var \Forms\Form $form */
		$form = $this->getComponent('typeForm');

		$form->setDefaults($relatedType->toArray());
	}

	public function renderDetailType(RelatedType $relatedType)
	{
		$this->template->headerLabel = 'Detail typu';
		$this->template->headerTree = [
			['Vazby', 'default'],
			['Detail typu']
		];
		$this->template->displayButtons = [$this->createBackButton('default')];
		$this->template->displayControls = [$this->getComponent('typeForm')];
	}

}