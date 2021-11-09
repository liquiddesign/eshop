<?php

declare(strict_types=1);

namespace Eshop\Admin;

use Admin\Controls\AdminForm;
use Admin\Controls\AdminGrid;
use Eshop\BackendPresenter;
use Eshop\DB\ProductRepository;
use Eshop\DB\Related;
use Eshop\DB\RelatedMaster;
use Eshop\DB\RelatedMasterRepository;
use Eshop\DB\RelatedRepository;
use Eshop\DB\RelatedSlave;
use Eshop\DB\RelatedSlaveRepository;
use Eshop\DB\RelatedType;
use Eshop\DB\RelatedTypeRepository;
use Eshop\FormValidators;
use Forms\Form;
use Grid\Datagrid;
use League\Csv\Reader;
use League\Csv\Writer;
use Nette\Application\Application;
use Nette\Application\Responses\FileResponse;
use Nette\Utils\Arrays;
use StORM\DIConnection;

class RelatedPresenter extends BackendPresenter
{
	/** @inject */
	public RelatedRepository $relatedRepository;

	/** @inject */
	public RelatedTypeRepository $relatedTypeRepository;

	/** @inject */
	public ProductRepository $productRepository;

	/** @inject */
	public RelatedMasterRepository $relatedMasterRepository;

	/** @inject */
	public RelatedSlaveRepository $relatedSlaveRepository;

	/** @inject */
	public Application $application;

	/** @persistent */
	public string $tab = 'none';

	/**
	 * @var string[]
	 */
	private array $tabs = [];

	private ?RelatedType $relatedType;

	public function createComponentRelationGrid(): AdminGrid
	{
		$grid = $this->gridFactory->create($this->relatedRepository->many()->where('this.fk_type', $this->tab), 20, 'this.priority', 'ASC', true);
		$grid->addColumnSelector();

		$grid->addColumn($this->relatedType->getMasterInternalName(), function (Related $object, $datagrid) {
			$result = '';

			foreach ($object->masters as $relatedProduct) {
				$link = $this->admin->isAllowed(':Eshop:Admin:Product:edit') ?
					$datagrid->getPresenter()->link(':Eshop:Admin:Product:edit', [$relatedProduct->product, 'backLink' => $this->storeRequest()]) : '#';

				$result .= (\strlen($relatedProduct->product->name) > 15 ? "<abbr title='" . $relatedProduct->product->name . "'>" : '') .
					"<a href='$link'><i class='fa fa-external-link-alt fa-sm'></i>&nbsp;" .
					(\strlen($relatedProduct->product->name) > 15 ? \substr($relatedProduct->product->name, 0, 15) . '...' . "(" . $relatedProduct->amount . ")" :
						($relatedProduct->product->name . "(" . $relatedProduct->amount . ")")) .
					"</a>" . (\strlen($relatedProduct->product->name) > 15 ? "</abbr>" : '') .
					",&nbsp;";
			}

			return \strlen($result) > 0 ? \substr($result, 0, -7) : $result;
		}, '%s');

		$grid->addColumn($this->relatedType->getSlaveInternalName(), function (Related $object, $datagrid) {
			$result = '';

			foreach ($object->slaves as $relatedProduct) {
				$link = $this->admin->isAllowed(':Eshop:Admin:Product:edit') ?
					$datagrid->getPresenter()->link(':Eshop:Admin:Product:edit', [$relatedProduct->product, 'backLink' => $this->storeRequest()]) : '#';

				$result .= (\strlen($relatedProduct->product->name) > 15 ? "<abbr title='" . $relatedProduct->product->name . "'>" : '') .
					"<a href='$link'><i class='fa fa-external-link-alt fa-sm'></i>&nbsp;" .
					(\strlen($relatedProduct->product->name) > 15 ?
						\substr($relatedProduct->product->name, 0, 15) . '...' . "(" . $relatedProduct->amount . ", " . $relatedProduct->discountPct . "%)" :
						($relatedProduct->product->name . "(" . $relatedProduct->amount . ", " . $relatedProduct->discountPct . "%)")) .
					"</a>" . (\strlen($relatedProduct->product->name) > 15 ? "</abbr>" : '') .
					",&nbsp;";
			}

			return \strlen($result) > 0 ? \substr($result, 0, -7) : $result;
		}, '%s');

		$grid->addColumnLink('masters', $this->relatedType->getMasterInternalName());
		$grid->addColumnLink('slaves', $this->relatedType->getSlaveInternalName());

		$grid->addColumnInputInteger('Priorita', 'priority', '', '', 'this.priority', [], true);
		$grid->addColumnInputCheckbox('<i title="Skryto" class="far fa-eye-slash"></i>', 'hidden', '', '', 'this.hidden');

		$grid->addColumnLinkDetail('detailRelation');
		$grid->addColumnActionDeleteSystemic();

		$grid->addButtonSaveAll();
		$grid->addButtonDeleteSelected(null, false, function ($object) {
			if ($object) {
				return !$object->isSystemic();
			}

			return false;
		});

		$grid->addFilterTextInput('master', ['master.code', 'master.ean', 'master.name_cs'], null, 'Master: EAN, kód, název', '', '%s%%');
		$grid->addFilterTextInput('slave', ['slave.code', 'slave.ean', 'slave.name_cs'], null, 'Slave: EAN, kód, název', '', '%s%%');

		$grid->addFilterButtons();

		$submit = $grid->getForm()->addSubmit('export', 'Exportovat ...')->setHtmlAttribute('class', 'btn btn-outline-primary btn-sm');

		$submit->onClick[] = function ($button) use ($grid): void {
			$grid->getPresenter()->redirect('export', [$grid->getSelectedIds()]);
		};

		return $grid;
	}

	public function createComponentRelationForm(): Form
	{
		$form = $this->formFactory->create();

		$typeInput = $form->addSelect2('type', 'Typ', $this->relatedTypeRepository->getArrayForSelect())->setRequired();

		if ($this->tab) {
			$typeInput->setDefaultValue($this->tab);
		}

		$form->addText('priority', 'Priorita')
			->addRule($form::INTEGER)
			->setRequired()
			->setDefaultValue(10);
		$form->addCheckbox('hidden', 'Skryto');

		$form->addSubmit('submit', 'Uložit');

		$form->onSuccess[] = function (AdminForm $form): void {
			$values = $form->getValues('array');

			if (!$values['uuid']) {
				$values['uuid'] = DIConnection::generateUuid();
			}

			/** @var \Eshop\DB\Related $related */
			$related = $this->relatedRepository->syncOne($values);

			$this->flashMessage('Uloženo', 'success');
			$this->redirect('masterNew', $related);
		};

		return $form;
	}

	public function actionDefault(): void
	{
		$this->tabs = $this->relatedTypeRepository->getArrayForSelect();
		$this->tabs['types'] = '<i class="fa fa-bars"></i> Typy';

		if ($this->tab !== 'none') {
			return;
		}

		$this->tab = \count($this->tabs) > 1 ? Arrays::first(\array_keys($this->tabs)) : 'types';
	}

	public function renderDefault(): void
	{
		$this->template->tabs = $this->tabs;
		$this->template->headerLabel = 'Vazby';
		$this->template->headerTree = [
			['Vazby'],
			[$this->tabs[$this->tab]],
		];

		if ($this->tab === 'types') {
			$this->template->displayButtons = [$this->createNewItemButton('newType')];
			$this->template->displayControls = [$this->getComponent('typeGrid')];
		} else {
			$this->template->displayButtons = [
				$this->createNewItemButton('masterNew'),
				$this->createButtonWithClass('import', '<i class="fas fa-file-import"></i> Import', 'btn btn-outline-primary btn-sm'),
			];
			$this->template->displayControls = [$this->getComponent('relationGrid'),];
		}
	}

	public function renderNewRelation(): void
	{
		$this->template->headerLabel = 'Nová položka';
		$this->template->headerTree = [
			['Vazby', 'default'],
			['Nová položka'],
		];
		$this->template->displayButtons = [$this->createBackButton('default')];
		$this->template->displayControls = [$this->getComponent('relationForm')];
	}

	public function actionDetailRelation(Related $relation): void
	{
		/** @var \Forms\Form $form */
		$form = $this->getComponent('relationForm');

		$form->setDefaults($relation->toArray());
	}

	public function renderDetailRelation(): void
	{
		$this->template->headerLabel = 'Detail';
		$this->template->headerTree = [
			['Vazby', 'default'],
			['Detail'],
		];
		$this->template->displayButtons = [$this->createBackButton('default')];
		$this->template->displayControls = [$this->getComponent('relationForm')];
	}

	public function createComponentTypeGrid(): AdminGrid
	{
		$grid = $this->gridFactory->create($this->relatedTypeRepository->many(), 20, 'name_cs', 'ASC', true);
		$grid->addColumnSelector();
		$grid->addColumnText('Kód', 'code', '%s', 'code');
		$grid->addColumnText('Název', 'name', '%s', 'name');
		$grid->addColumnInputCheckbox('Podobný produkt', 'similar', '', '', 'similar');

		$grid->addColumnLinkDetail('detailType');
		$grid->addColumnActionDeleteSystemic();

		$grid->addButtonSaveAll();
		$grid->addButtonDeleteSelected(null, false, function ($object) {
			if ($object) {
				return !$object->isSystemic();
			}

			return false;
		});

		$grid->addFilterTextInput('search', ['name_cs', 'code'], 'Kód, název', 'Kód, název');
		$grid->addFilterButtons();

		return $grid;
	}

	public function createComponentTypeForm(): AdminForm
	{
		$form = $this->formFactory->create(true);

		$form->addText('code', 'Kód')->setRequired();
		$form->addLocaleText('name', 'Název');

		$form->addText('masterName', 'Název master produktů');
		$form->addText('slaveName', 'Název slave produktů')->setHtmlAttribute('data-info', 'Slouží pro lepší rozpoznání v administraci.');

		$form->addInteger('masterDefaultAmount', 'Výchozí počet master produktů')->setRequired()->setDefaultValue(1);
		$form->addInteger('slaveDefaultAmount', 'Výchozí poče produktů')->setRequired()->setDefaultValue(1);
		$form->addText('defaultDiscountPct', 'Výchozí sleva (%)')
			->setRequired()
			->setDefaultValue(0)
			->addRule($form::FLOAT)
			->addRule([FormValidators::class, 'isPercent'], 'Zadaná hodnota není procento!');
		$form->addCheckbox('similar', 'Podobné')->setHtmlAttribute('data-info', 'Produkty v této vazbě budou zobrazeny v detailu produktu.');

		$form->addSubmits(!$this->getParameter('relatedType'));

		$form->onValidate[] = function (AdminForm $form): void {
			if (!$form->isValid()) {
				return;
			}

			$values = $form->getValues('array');

			$existing = $this->relatedTypeRepository->many()->where('code', $values['code'])->first();

			if (!$existing || ($existing && $existing->getPK() === $values['uuid'])) {
				return;
			}

			$form['code']->addError('Již existuje typ s tímto kódem!');
		};

		$form->onSuccess[] = function (AdminForm $form): void {
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

	public function renderNewType(): void
	{
		$this->template->headerLabel = 'Nový typ';
		$this->template->headerTree = [
			['Vazby', 'default'],
			['Nový typ'],
		];
		$this->template->displayButtons = [$this->createBackButton('default')];
		$this->template->displayControls = [$this->getComponent('typeForm')];
	}

	public function actionDetailType(RelatedType $relatedType): void
	{
		/** @var \Forms\Form $form */
		$form = $this->getComponent('typeForm');

		$form->setDefaults($relatedType->toArray());
	}

	public function renderDetailType(): void
	{
		$this->template->headerLabel = 'Detail typu';
		$this->template->headerTree = [
			['Vazby', 'default'],
			['Detail typu'],
		];
		$this->template->displayButtons = [$this->createBackButton('default')];
		$this->template->displayControls = [$this->getComponent('typeForm')];
	}

	public function renderExport(array $ids): void
	{
		$this->template->headerLabel = 'Exportovat';
		$this->template->headerTree = [
			['Vazby', 'default'],
			['Export'],
		];
		$this->template->displayButtons = [$this->createBackButton('default')];
		$this->template->displayControls = [$this->getComponent('exportForm')];
	}

	public function createComponentExportForm(): AdminForm
	{
		/** @var \Grid\Datagrid $grid */
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

		$form->onSuccess[] = function (AdminForm $form) use ($ids, $grid): void {
			$values = $form->getValues('array');

			/** @var \Eshop\DB\Related[] $relations */
			$relations = $values['bulkType'] === 'selected' ? $this->relatedRepository->many()->where('uuid', $ids) : $grid->getFilteredSource();

			$tempFilename = \tempnam($this->tempDir, "csv");

			$this->application->onShutdown[] = function () use ($tempFilename): void {
				\unlink($tempFilename);
			};

			$this->relatedRepository->exportCsv(Writer::createFromPath($tempFilename, 'w+'), $relations);

			$this->getPresenter()->sendResponse(new FileResponse($tempFilename, "relations.csv", 'text/csv'));
		};

		return $form;
	}

	public function renderImport(): void
	{
		$this->template->headerLabel = 'Importovat';
		$this->template->headerTree = [
			['Vazby', 'default'],
			['Import'],
		];
		$this->template->displayButtons = [$this->createBackButton('default')];
		$this->template->displayControls = [$this->getComponent('importForm')];
	}

	public function createComponentImportForm(): AdminForm
	{
		$form = $this->formFactory->create();
		$form->addUpload('file', 'CSV soubor')->setRequired()->setHtmlAttribute('data-info', '<h5 class="mt-2">Nápověda</h5>
Povinné sloupce:<br>
type - Kód typu vazby<br>
master - Kód hlavního produktu vazby<br>
slave - Kód sekundárního produktu vazby<br><br>
Pokud nebude nalezen typ vazby nebo některý z produktů tak se daný řádek ignoruje.');
		$form->addSubmit('submit', 'Uložit');

		$form->onSuccess[] = function (Form $form): void {
			/** @var \Nette\Http\FileUpload $file */
			$file = $form->getValues()->file;

			$this->relatedRepository->importCsv(Reader::createFromString($file->getContents()));

			$form->getPresenter()->flashMessage('Uloženo', 'success');
			$form->getPresenter()->redirect('default');
		};

		return $form;
	}

	public function createComponentRelatedMasterGrid(): AdminGrid
	{
		$grid = $this->gridFactory->create($this->relatedMasterRepository->many()->where('fk_related', $this->getParameter('related')), 20, 'product', 'ASC', true);
		$grid->addColumnSelector();

		$grid->addColumnText('Kód', 'product.code', '%s', 'product.code');
		$grid->addColumn('Produkt', function (RelatedMaster $relatedProduct, Datagrid $datagrid) {
			$link = $this->admin->isAllowed(':Eshop:Admin:Product:edit') && $relatedProduct->product ? $datagrid->getPresenter()->link(
				':Eshop:Admin:Product:edit',
				[$relatedProduct->product, 'backLink' => $this->storeRequest()],
			) : '#';

			return '<a href="' . $link . '">' . $relatedProduct->product->name . '</a>';
		}, '%s', 'product.name');
		$grid->addColumnInputInteger('Množství', 'amount', '', 'amount');

		$grid->addColumnActionDelete();

		$grid->addButtonSaveAll([], [], null, false, null, function ($id, &$data): void {
			if (!isset($data['amount']) || $data['amount'] === '') {
				$data['amount'] = 1;
			}
		}, false);
		$grid->addButtonDeleteSelected();

		$mutationSuffix = $this->relatedTypeRepository->getConnection()->getMutationSuffix();

		$grid->addFilterTextInput('search', ["product.name$mutationSuffix", "product.code"], 'Kód, název', 'Kód, název');
		$grid->addFilterButtons(['masters', $this->getParameter('related')]);

		return $grid;
	}

	public function createComponentRelatedSlaveGrid(): AdminGrid
	{
		$grid = $this->gridFactory->create($this->relatedSlaveRepository->many()->where('fk_related', $this->getParameter('related')), 20, 'product', 'ASC', true);
		$grid->addColumnSelector();

		$grid->addColumnText('Kód', 'product.code', '%s', 'product.code');
		$grid->addColumn('Produkt', function (RelatedSlave $relatedProduct, Datagrid $datagrid) {
			$link = $this->admin->isAllowed(':Eshop:Admin:Product:edit') && $relatedProduct->product ? $datagrid->getPresenter()->link(
				':Eshop:Admin:Product:edit',
				[$relatedProduct->product, 'backLink' => $this->storeRequest()],
			) : '#';

			return '<a href="' . $link . '">' . $relatedProduct->product->name . '</a>';
		}, '%s', 'product.name');
		$grid->addColumnInputInteger('Množství', 'amount', '', 'amount');
		$grid->addColumnInputFloat('Sleva (%)', 'discountPct', '', 'discountPct');

		$grid->addColumnActionDelete();

		$grid->addButtonSaveAll([], [], null, false, null, function ($id, &$data): void {
			if (!isset($data['amount']) || $data['amount'] === '') {
				$data['amount'] = 1;
			}

			if (isset($data['discountPct']) && $data['discountPct'] !== '' && $data['discountPct'] >= 0 && $data['discountPct'] <= 100) {
				return;
			}

			$data['discountPct'] = 0;
		}, false);
		$grid->addButtonDeleteSelected();

		$mutationSuffix = $this->relatedTypeRepository->getConnection()->getMutationSuffix();

		$grid->addFilterTextInput('search', ["product.name$mutationSuffix", "product.code"], 'Kód, název', 'Kód, název');
		$grid->addFilterButtons(['slaves', $this->getParameter('related')]);

		return $grid;
	}

	public function renderMasters(Related $related): void
	{
		$this->template->headerLabel = $related->type->getMasterInternalName();
		$this->template->headerTree = [
			['Vazby', 'default'],
			[$related->type->getMasterInternalName()],
		];
		$this->template->displayButtons = [
			$this->createBackButton('default'),
			$this->createNewItemButton('masterNew', [$related]),
			$this->createButtonWithClass('slaves', ($related->type->getSlaveInternalName() ?? 'Slave produkty') .
				'&nbsp;<i class="fas fa-arrow-right"></i>', 'btn btn-sm btn-outline-primary', $related),
		];
		$this->template->displayControls = [$this->getComponent('relatedMasterGrid')];
	}

	public function renderMasterNew(?Related $related = null): void
	{
		$this->template->headerLabel = $related ? $related->type->getMasterInternalName() : $this->relatedType->getMasterInternalName();
		$this->template->headerTree = [
			['Vazby', 'default'],
			[$related ? $related->type->getMasterInternalName() : $this->relatedType->getMasterInternalName()],
		];
		$this->template->displayButtons = [$related ? $this->createBackButton('masters', $related) : $this->createBackButton('default')];
		$this->template->displayControls = [$this->getComponent('relatedMasterForm')];
	}

	public function createComponentRelatedMasterForm(): Form
	{
		$form = $this->formFactory->create();

		/** @var \Eshop\DB\Related $related */
		$related = $this->getParameter('related');

		if ($related) {
			$form->addHidden('related')->setDefaultValue($related->getPK());
		}

		$form->addSelect2Ajax('product', $this->link('getProductsForSelect2!'), $related ? $related->type->getMasterInternalName() : $this->relatedType->getMasterInternalName(), [], 'Zvolte produkt');
		$form->addInteger('amount', 'Množství')->setRequired()->setDefaultValue($this->relatedType->masterDefaultAmount);

		$form->addSubmits(true, false);

		$form->onValidate[] = function (AdminForm $form): void {
			if (!$form->isValid()) {
				return;
			}

			$data = $form->getHttpData();

			if (isset($data['product'])) {
				return;
			}

			$form['product']->addError('Toto pole je povinné!');
		};

		$form->onSuccess[] = function (AdminForm $form): void {
			$values = $form->getValues('array');
			$data = $form->getHttpData();
			$values['product'] = $data['product'];

			if (!$values['uuid']) {
				$values['uuid'] = DIConnection::generateUuid();
			}

			$newRelated = false;

			if (!isset($values['related'])) {
				$newRelated = true;
				$values['related'] = $this->relatedRepository->createOne(['type' => $this->relatedType]);
			}

			/** @var \Eshop\DB\RelatedMaster $relatedMaster */
			$relatedMaster = $this->relatedMasterRepository->syncOne($values);

			$this->flashMessage('Uloženo', 'success');
			$form->processRedirect('', $newRelated ? 'slaveNew' : 'masters', [], [$relatedMaster->related], [$relatedMaster->related]);
		};

		return $form;
	}

	public function renderSlaves(Related $related): void
	{
		$this->template->headerLabel = $related->type->getSlaveInternalName();
		$this->template->headerTree = [
			['Vazby', 'default'],
			[$related->type->getSlaveInternalName()],
		];
		$this->template->displayButtons = [
			$this->createBackButton('default'),
			$this->createNewItemButton('slaveNew', [$related]),
			$this->createButtonWithClass('masters', ($related->type->getMasterInternalName() ?? 'Master produkty') .
				'&nbsp;<i class="fas fa-arrow-right"></i>', 'btn btn-sm btn-outline-primary', $related),
		];
		$this->template->displayControls = [$this->getComponent('relatedSlaveGrid')];
	}

	public function renderSlaveNew(Related $related): void
	{
		$this->template->headerLabel = $related->type->getSlaveInternalName();
		$this->template->headerTree = [
			['Vazby', 'default'],
			[$related->type->getSlaveInternalName()],
		];
		$this->template->displayButtons = [$this->createBackButton('slaves', $related)];
		$this->template->displayControls = [$this->getComponent('relatedSlaveForm')];
	}

	public function createComponentRelatedSlaveForm(): Form
	{
		$form = $this->formFactory->create();

		/** @var \Eshop\DB\Related $related */
		$related = $this->getParameter('related');

		$form->addHidden('related')->setDefaultValue($related->getPK());

		$form->addSelect2Ajax('product', $this->link('getProductsForSelect2!'), $related->type->getSlaveInternalName(), [], 'Zvolte produkt');
		$form->addInteger('amount', 'Množství')->setRequired()->setDefaultValue($this->relatedType->slaveDefaultAmount);
		$form->addText('discountPct', 'Sleva (%)')
			->setRequired()
			->setDefaultValue($related->type->defaultDiscountPct)
			->addRule($form::FLOAT)
			->addRule([FormValidators::class, 'isPercent'], 'Zadaná hodnota není procento!');

		$form->addSubmits(true, false);

		$form->onValidate[] = function (AdminForm $form): void {
			if (!$form->isValid()) {
				return;
			}

			$data = $form->getHttpData();

			if (isset($data['product'])) {
				return;
			}

			$form['product']->addError('Toto pole je povinné!');
		};

		$form->onSuccess[] = function (AdminForm $form): void {
			$values = $form->getValues('array');
			$data = $form->getHttpData();
			$values['product'] = $data['product'];

			if (!$values['uuid']) {
				$values['uuid'] = DIConnection::generateUuid();
			}

			/** @var \Eshop\DB\RelatedSlave $relatedSlave */
			$relatedSlave = $this->relatedSlaveRepository->syncOne($values);

			$this->flashMessage('Uloženo', 'success');
			$form->processRedirect('', 'slaves', [], [$relatedSlave->related], [$relatedSlave->related]);
		};

		return $form;
	}

	protected function startup(): void
	{
		parent::startup();

		$this->relatedType = $this->relatedTypeRepository->one($this->tab);
	}
}
