<?php

declare(strict_types=1);

namespace Eshop\Admin;

use Eshop\BackendPresenter;
use Admin\Controls\AdminGrid;
use Admin\Controls\AdminForm;
use Eshop\DB\ProductRepository;
use Eshop\DB\Related;
use Eshop\DB\RelatedRepository;
use Eshop\DB\RelatedType;
use Eshop\DB\RelatedTypeRepository;
use Forms\Form;
use League\Csv\Reader;
use League\Csv\Writer;
use Nette\Application\Application;
use Nette\Application\Responses\FileResponse;
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

	/** @inject */
	public Application $application;

	public const TABS = [
		'relations' => 'Vazby',
		'types' => 'Typy',
	];

	/** @persistent */
	public string $tab = 'relations';

	public function createComponentRelationGrid(): AdminGrid
	{
		$grid = $this->gridFactory->create($this->relatedRepository->many(), 20, 'this.priority', 'ASC', true);
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

		$grid->addColumnInputInteger('Priorita', 'priority', '', '', 'this.priority', [], true);
		$grid->addColumnInputCheckbox('<i title="Skryto" class="far fa-eye-slash"></i>', 'hidden', '', '', 'this.hidden');

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

		$grid->addFilterTextInput('master', ['master.code', 'master.ean', 'master.name_cs'], null, 'Master: EAN, kód, název', '', '%s%%');
		$grid->addFilterTextInput('slave', ['slave.code', 'slave.ean', 'slave.name_cs'], null, 'Slave: EAN, kód, název', '', '%s%%');

		$grid->addFilterButtons();

		$submit = $grid->getForm()->addSubmit('export', 'Exportovat ...')->setHtmlAttribute('class', 'btn btn-outline-primary btn-sm');

		$submit->onClick[] = function ($button) use ($grid) {
			$grid->getPresenter()->redirect('export', [$grid->getSelectedIds()]);
		};

		return $grid;
	}

	public function createComponentRelationForm(): Form
	{
		$form = $this->formFactory->create();

		$form->addSelect2('type', 'Typ', $this->relatedTypeRepository->getArrayForSelect())->setRequired();

		$form->addText('priority', 'Priorita')
			->addRule($form::INTEGER)
			->setRequired()
			->setDefaultValue(10);
		$form->addCheckbox('hidden', 'Skryto');

		$master = $form->addSelect2('master', 'První produkt', null, [
			'ajax' => [
				'url' => $this->link('getProductsForSelect2!')
			]
		])->checkDefaultValue(false);

		$slave = $form->addSelect2('slave', 'Druhý produkt', null, [
			'ajax' => [
				'url' => $this->link('getProductsForSelect2!')
			]
		])->checkDefaultValue(false);

		/** @var Related $relation */
		if ($relation = $this->getParameter('relation')) {
			$this->template->select2AjaxDefaults[$master->getHtmlId()] = [$relation->getValue('master') => $relation->master->name];
			$this->template->select2AjaxDefaults[$slave->getHtmlId()] = [$relation->getValue('slave') => $relation->slave->name];
		}

		$form->addSubmits(!$this->getParameter('relation'));

		$form->onValidate[] = function (AdminForm $form) {
			$data = $this->getHttpRequest()->getPost();

			if (!isset($data['master'])) {
				$form['master']->addError('Toto pole je povinné!');
			}

			if (!isset($data['slave'])) {
				$form['slave']->addError('Toto pole je povinné!');
			}
		};

		$form->onSuccess[] = function (AdminForm $form) {
			$values = $form->getValues('array');

			$values['master'] = $this->productRepository->one($form->getHttpData(Form::DATA_TEXT, 'master'))->getPK();
			$values['slave'] = $this->productRepository->one($form->getHttpData(Form::DATA_TEXT, 'slave'))->getPK();

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
			$this->template->displayButtons = [
				$this->createNewItemButton('newRelation'),
				$this->createButtonWithClass('import', '<i class="fas fa-file-import"></i> Import', 'btn btn-outline-primary btn-sm')
			];
			$this->template->displayControls = [$this->getComponent('relationGrid'),];
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
		$grid->addColumnText('Kód', 'code', '%s', 'code');
		$grid->addColumnText('Název', 'name', '%s', 'name');
		$grid->addColumnInputCheckbox('Podobný produkt', 'similar', '', '', 'similar');

		$grid->addColumnLinkDetail('detailType');
		$grid->addColumnActionDeleteSystemic();

		$grid->addButtonSaveAll();
		$grid->addButtonDeleteSelected();

		$grid->addFilterTextInput('name', ['name_cs', 'code'], 'Kód, název', 'Kód, název');
		$grid->addFilterButtons();

		return $grid;
	}

	public function createComponentTypeForm()
	{
		$form = $this->formFactory->create();

		$form->addText('code', 'Kód')->setRequired();
		$form->addLocaleText('name', 'Název');
		$form->addCheckbox('similar', 'Podobné')->setHtmlAttribute('data-info', 'Produkty v této vazbě budou zobrazeny v detailu produktu jako podobné produkty. Na pořadí nezáleží.');

		$form->addSubmits(!$this->getParameter('relatedType'));

		$form->onValidate[] = function (AdminForm $form) {
			$values = $form->getValues('array');

			if ($this->relatedTypeRepository->many()->where('code', $values['code'])->first()) {
				$form['code']->addError('Již existuje typ s tímto kódem!');
			}
		};

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

	public function actionExport(array $ids)
	{
	}


	public function renderExport(array $ids)
	{
		$this->template->headerLabel = 'Exportovat';
		$this->template->headerTree = [
			['Vazby', 'default'],
			['Export']
		];
		$this->template->displayButtons = [$this->createBackButton('default')];
		$this->template->displayControls = [$this->getComponent('exportForm')];
	}

	public function createComponentExportForm()
	{
		/** @var \Grid\Datagrid $productGrid */
		$grid = $this->getComponent('relationGrid');

		$ids = $this->getParameter('ids') ?: [];
		$totalNo = $grid->getFilteredSource()->enum();
		$selectedNo = \count($ids);

		$form = $this->formFactory->create();
		$form->setAction($this->link('this', ['selected' => $this->getParameter('selected')]));
		$form->addRadioList('bulkType', 'Upravit', [
			'selected' => "vybrané ($selectedNo)",
			'all' => "celý výsledek ($totalNo)",
		])->setDefaultValue('selected');

		$form->addSubmit('submit', 'Exportovat');

		$form->onSuccess[] = function (AdminForm $form) use ($ids, $grid) {
			$values = $form->getValues('array');

			/** @var Related[] $relations */
			$relations = $values['bulkType'] == 'selected' ? $this->relatedRepository->many()->where('uuid', $ids) : $grid->getFilteredSource();

			$tempFilename = \tempnam($this->tempDir, "csv");

			$this->application->onShutdown[] = function () use ($tempFilename) {
				\unlink($tempFilename);
			};

			$this->relatedRepository->exportCsv(Writer::createFromPath($tempFilename, 'w+'), $relations);

			$this->getPresenter()->sendResponse(new FileResponse($tempFilename, "relations.csv", 'text/csv'));
		};

		return $form;
	}

	public function actionImport()
	{
	}


	public function renderImport()
	{
		$this->template->headerLabel = 'Importovat';
		$this->template->headerTree = [
			['Vazby', 'default'],
			['Import']
		];
		$this->template->displayButtons = [$this->createBackButton('default')];
		$this->template->displayControls = [$this->getComponent('importForm')];
	}

	public function createComponentImportForm()
	{
		$form = $this->formFactory->create();
		$form->addUpload('file', 'CSV soubor')->setRequired();
		$form->addSubmit('submit', 'Uložit');

		$form->onSuccess[] = function (Form $form) {
			/** @var \Nette\Http\FileUpload $file */
			$file = $form->getValues()->file;

			$this->relatedRepository->importCsv(Reader::createFromString($file->getContents()));

			$form->getPresenter()->flashMessage('Uloženo', 'success');
			$form->getPresenter()->redirect('default');
		};

		return $form;
	}

}