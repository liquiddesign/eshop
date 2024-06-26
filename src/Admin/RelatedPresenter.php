<?php

declare(strict_types=1);

namespace Eshop\Admin;

use Admin\Controls\AdminForm;
use Admin\Controls\AdminGrid;
use Eshop\BackendPresenter;
use Eshop\DB\ProductRepository;
use Eshop\DB\Related;
use Eshop\DB\RelatedRepository;
use Eshop\DB\RelatedType;
use Eshop\DB\RelatedTypeRepository;
use Eshop\FormValidators;
use Forms\Form;
use League\Csv\Writer;
use Nette\Application\Application;
use Nette\Application\Responses\FileResponse;
use Nette\Forms\Controls\TextInput;
use Nette\Utils\Arrays;
use Nette\Utils\FileSystem;
use Nette\Utils\Html;
use Nette\Utils\Strings;
use StORM\DIConnection;
use StORM\Expression;
use StORM\ICollection;
use Tracy\Debugger;
use Tracy\ILogger;

class RelatedPresenter extends BackendPresenter
{
	#[\Nette\DI\Attributes\Inject]
	public RelatedRepository $relatedRepository;

	#[\Nette\DI\Attributes\Inject]
	public RelatedTypeRepository $relatedTypeRepository;

	#[\Nette\DI\Attributes\Inject]
	public ProductRepository $productRepository;

	#[\Nette\DI\Attributes\Inject]
	public Application $application;

	/** @persistent */
	public string $tab = 'none';

	protected ?RelatedType $relatedType;

	/**
	 * @var array<string>
	 */
	private array $tabs = [];

	public function createComponentRelationGrid(): AdminGrid
	{
		$grid = $this->gridFactory->create(
			$this->relatedRepository->many()->where('this.fk_type', $this->tab),
			20,
			'this.priority',
			'ASC',
			true,
		);

		$grid->addColumnSelector();
		$grid->addColumnText('Obchody', 'shops', '%s', 'this.shops');

		$grid->addColumn($this->relatedType->getMasterInternalName(), function (Related $object, $datagrid) {
			$link = $this->admin->isAllowed(':Eshop:Admin:Product:edit') ? $datagrid->getPresenter()->link(':Eshop:Admin:Product:edit', [$object->master, 'backLink' => $this->storeRequest()]) : '#';

			return "<a href='$link'><i class='fa fa-external-link-alt fa-sm'></i>&nbsp;" . $object->master->name . '</a>';
		}, '%s');

		$grid->addColumn($this->relatedType->getSlaveInternalName(), function (Related $object, $datagrid) {
			$link = $this->admin->isAllowed(':Eshop:Admin:Product:edit') ? $datagrid->getPresenter()->link(':Eshop:Admin:Product:edit', [$object->slave, 'backLink' => $this->storeRequest()]) : '#';

			return "<a href='$link'><i class='fa fa-external-link-alt fa-sm'></i>&nbsp;" . $object->slave->name . '</a>';
		}, '%s');

		$grid->addColumnInputInteger('Množství', 'amount', '', '', 'this.amount', [], true);

		if ($this->relatedType->defaultDiscountPct) {
			$grid->addColumnInputFloat('Sleva (%)', 'discountPct', '', '', 'discountPct');
		} elseif ($this->relatedType->defaultMasterPct) {
			$grid->addColumnInputFloat('Procentuální cena (%)', 'masterPct', '', '', 'masterPct');
		}

		$grid->addColumnInputInteger('Priorita', 'priority', '', '', 'this.priority', [], true);
		$grid->addColumnInputCheckbox('<i title="Skryto" class="far fa-eye-slash"></i>', 'hidden', '', '', 'this.hidden');

		$grid->addColumnLinkDetail('detailRelation');
		$grid->addColumnActionDeleteSystemic();

		$grid->addButtonSaveAll([], [], null, false, null, function ($id, &$data): void {
			if (!isset($data['amount']) || $data['amount'] === '') {
				$data['amount'] = 1;
			}

			if ($this->relatedType->defaultDiscountPct) {
				if (!isset($data['discountPct']) || $data['discountPct'] === '' || $data['discountPct'] < 0 || $data['discountPct'] > 100) {
					$data['discountPct'] = 0;
				}
			} else {
				$data['discountPct'] = null;
			}

			if ($this->relatedType->defaultMasterPct) {
				if (!isset($data['masterPct']) || $data['masterPct'] === '' || $data['masterPct'] < 0) {
					$data['masterPct'] = 0;
				}
			} else {
				$data['masterPct'] = null;
			}
		}, false);

		$grid->addButtonDeleteSelected(null, false, function ($object) {
			if ($object) {
				return !$object->isSystemic();
			}

			return false;
		});

		$grid->addButtonBulkEdit('relationForm', ['amount', 'priority', 'hidden'], 'relationGrid');

		$mutationSuffix = $this->relatedTypeRepository->getConnection()->getMutationSuffix();

		$grid->addFilterTextInput('master', ['master.code', 'master.ean', "master.name$mutationSuffix"], null, $this->relatedType->getMasterInternalName() .
			': EAN, kód, název', '');
		$grid->addFilterTextInput('slave', ['slave.code', 'slave.ean', "slave.name$mutationSuffix"], null, $this->relatedType->getSlaveInternalName() .
			': EAN, kód, název', '');
		$grid->addFilterText(function (ICollection $source, $value): void {
			$parsed = \explode(',', Strings::trim($value));
			$expression = new Expression();

			$i = 0;

			foreach ($parsed as $value) {
				$value = Strings::trim($value);

				$expression->add('OR', "this.shops LIKE :shop__$i", ["shop__$i" => "%$value%"]);
			}

			$source->where('this.shops LIKE :shops', ['shops' => Strings::trim($value)]);
		}, '', 'shops')->setHtmlAttribute('placeholder', 'Obchody')->setHtmlAttribute('class', 'form-control form-control-sm');

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
		$form->addInteger('amount', 'Množství')->setRequired()->setDefaultValue($this->relatedType->defaultAmount);

		if ($this->relatedType->defaultDiscountPct) {
			$form->addText('discountPct', 'Sleva (%)')
				->setDefaultValue($this->relatedType->defaultDiscountPct)
				->addRule($form::REQUIRED)
				->addRule($form::FLOAT)
				->addRule([FormValidators::class, 'isPercent'], 'Zadaná hodnota není procento!');
		} elseif ($this->relatedType->defaultMasterPct) {
			$form->addText('masterPct', 'Procento ceny z ' . $this->relatedType->getMasterInternalName() . ' (%)')
				->setDefaultValue($this->relatedType->defaultMasterPct)
				->addRule($form::REQUIRED)
				->addRule($form::FLOAT)
				->addRule([FormValidators::class, 'isPercentNoMax'], 'Zadaná hodnota není procento!');
		}

		$master = $form->addSelect2Ajax('master', $this->link('getProductsForSelect2!'), $this->relatedType->getMasterInternalName(), [], 'Zvolte produkt');
		$slave = $form->addSelect2Ajax('slave', $this->link('getProductsForSelect2!'), $this->relatedType->getSlaveInternalName(), [], 'Zvolte produkt');

		/** @var \Eshop\DB\Related|null $relation */
		$relation = $this->getParameter('relation');

		if ($relation) {
			$this->template->select2AjaxDefaults[$master->getHtmlId()] = [$relation->getValue('master') => $relation->master->name];
			$this->template->select2AjaxDefaults[$slave->getHtmlId()] = [$relation->getValue('slave') => $relation->slave->name];
		}

		$form->addMultiSelect2('shops', 'Obchody', $this->shopsConfig->getAvailableShopsArrayForSelect());

		$form->addSubmits(!$this->getParameter('relation'));

		$form->onValidate[] = function (AdminForm $form): void {
			$data = $this->getHttpRequest()->getPost();

			if (!isset($data['master'])) {
				/** @var \Nette\Forms\Controls\SelectBox $input */
				$input = $form['master'];
				$input->addError('Toto pole je povinné!');
			}

			if (isset($data['slave'])) {
				return;
			}

			/** @var \Nette\Forms\Controls\SelectBox $input */
			$input = $form['slave'];
			$input->addError('Toto pole je povinné!');
		};

		$form->onSuccess[] = function (AdminForm $form): void {
			$values = $form->getValues('array');

			\sort($values['shops']);
			$values['shops'] = $values['shops'] ? \implode(',', $values['shops']) : null;

			$values['master'] = $this->productRepository->one($form->getHttpData()['master'])->getPK();
			$values['slave'] = $this->productRepository->one($form->getHttpData()['slave'])->getPK();

			if (!$values['uuid']) {
				$values['uuid'] = DIConnection::generateUuid();
			}

			/** @var \Eshop\DB\Related $related */
			$related = $this->relatedRepository->syncOne($values);

			$this->flashMessage('Uloženo', 'success');
			$form->processRedirect('detailRelation', 'default', [$related]);
		};

		return $form;
	}

	public function actionDefault(): void
	{
		$this->tabs = $this->relatedTypeRepository->getArrayForSelect(true, false);
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

			$this->template->headerTree = [
				['Vazby'],
				['Typy'],
			];
		} else {
			$this->template->displayButtons = [
				$this->createNewItemButton('newRelation'),
				$this->createButtonWithClass('import', '<i class="fas fa-file-upload"></i> Import', 'btn btn-primary btn-sm'),
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

		$relationArray = $relation->toArray();
		$relationArray['shops'] = $relationArray['shops'] ? \explode(',', $relationArray['shops']) : [];

		$form->setDefaults($relationArray);
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
		$grid->addColumnText('Název master produktu', 'masterName', '%s', 'masterName');
		$grid->addColumnText('Název slave produktu', 'slaveName', '%s', 'slaveName');
		$grid->addColumnInputCheckbox('Zobrazit v košíku', 'showCart', '', '', 'showCart');
		$grid->addColumnInputCheckbox('Zobrazit v našeptávači', 'showSearch', '', '', 'showSearch');
		$grid->addColumnInputCheckbox('Zobrazit v detailu', 'showDetail', '', '', 'showDetail');
		$grid->addColumnInputCheckbox('Zobrazit jako set', 'showAsSet', '', '', 'showAsSet');
		$grid->addColumnInputCheckbox('<i title="Skryto" class="far fa-eye-slash"></i>', 'hidden', '', '', 'hidden');

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

		/** @var \Eshop\DB\RelatedType|null $relatedType */
		$relatedType = $this->getParameter('relatedType');

		$form->addText('code', 'Kód')->setRequired();
		$form->addLocaleText('name', 'Název');
		$form->addCheckbox('hidden', 'Skryto');

		$form->addText('masterName', 'Název master produktu')->setNullable();
		$form->addText('slaveName', 'Název slave produktu')->setNullable()->setHtmlAttribute('data-info', 'Slouží pro lepší rozpoznání v administraci.');

		$form->addInteger('defaultAmount', 'Výchozí množství')->setRequired()->setDefaultValue(1);
		$typeInput = $form->addSelect('type', 'Typ přepočtu ceny', ['none' => 'Žádný', 'discount' => 'Sleva', 'master' => 'Procento z master produktu']);

		if ($relatedType) {
			$typeInput->setDefaultValue($relatedType->defaultDiscountPct ? 'discount' : ($relatedType->defaultMasterPct ? 'master' : 'none'));
		}

		$form->addText('defaultDiscountPct', 'Výchozí sleva (%)')
			->setNullable()
			->addConditionOn($typeInput, $form::EQUAL, 'discount')
			->addRule($form::REQUIRED)
			->addRule($form::FLOAT)
			->addRule([FormValidators::class, 'isPercent'], 'Zadaná hodnota není procento!');
		$form->addText('defaultMasterPct', 'Výchozí výpočet ceny z master produktu (%)')
			->setNullable()
			->addConditionOn($typeInput, $form::EQUAL, 'master')
			->addRule($form::REQUIRED)
			->addRule($form::FLOAT)
			->addRule([FormValidators::class, 'isPercentNoMax'], 'Zadaná hodnota není procento!');

		$typeInput->addCondition($form::EQUAL, 'discount')
			->toggle('frm-typeForm-defaultDiscountPct-toogle')
			->endCondition();

		$typeInput->addCondition($form::EQUAL, 'master')
			->toggle('frm-typeForm-defaultMasterPct-toogle')
			->endCondition();

		$form->addCheckbox('showCart', 'Zobrazit v košíku');
		$form->addCheckbox('showSearch', 'Zobrazit v našeptávači');
		$detailCheckbox = $form->addCheckbox('showDetail', 'Zobrazit v detailu produktu')
			->setHtmlAttribute('data-info', 'Zobrazí se v detailu produktu jako seznam produktů. Platí pouze pokud není současně použito "Zobrazit jako set".');

		$form->addLocaleText('frontMasterName', 'Název pro eshop (master)')->forAll(function (TextInput $input) use ($form, $detailCheckbox): void {
			$input->setNullable();

			$detailCheckbox->addCondition($form::EQUAL, true)
				->toggle($input->getHtmlId() . '-toogle')
				->endCondition();

			$input->setHtmlAttribute('data-info', 'Pokud nevyplníte tak nebude na této straně vazba zobrazena.<br>Lze použít tyto proměnné:<br>
{$productName} - Název produktu<br>');
		});

		$form->addLocaleText('frontSlaveName', 'Název pro eshop (slave)')->forAll(function (TextInput $input) use ($form, $detailCheckbox): void {
			$input->setNullable();

			$detailCheckbox->addCondition($form::EQUAL, true)
				->toggle($input->getHtmlId() . '-toogle')
				->endCondition();

			$input->setHtmlAttribute('data-info', 'Pokud nevyplníte tak nebude na této straně vazba zobrazena.<br>Lze použít tyto proměnné:<br>
{$productName} - Název produktu<br>');
		});

		$form->addCheckbox('showAsSet', 'Zobrazit jako set')->setHtmlAttribute('data-info', 'Zobrazí v detailu produktu odkazy na produkty setu.');

		$form->addSubmits(!$relatedType);

		$form->onValidate[] = function (AdminForm $form): void {
			if (!$form->isValid()) {
				return;
			}

			$values = $form->getValues('array');

			$columnsToCheck = ['frontMasterName', 'frontSlaveName'];

			foreach ($columnsToCheck as $column) {
				foreach ($values[$column] as $mutation => $content) {
					if (!$this->relatedTypeRepository->isDefaultContentValid($content)) {
						/** @var \Nette\Forms\Controls\TextInput $input */
						$input = $form[$column][$mutation];
						$input->addError('Neplatný text! Zkontrolujte správnost proměnných!');
					}
				}
			}

			/** @var \Eshop\DB\RelatedType|null $existing */
			$existing = $this->relatedTypeRepository->many()->where('code', $values['code'])->first();

			if ($existing === null || ($existing->getPK() === $values['uuid'])) {
				return;
			}

			/** @var \Nette\Forms\Controls\TextInput $input */
			$input = $form['code'];
			$input->addError('Již existuje typ s tímto kódem!');
		};

		$form->onSuccess[] = function (AdminForm $form): void {
			$values = $form->getValues('array');

			$type = Arrays::pick($values, 'type');

			if (!$values['uuid']) {
				$values['uuid'] = DIConnection::generateUuid();
			}

			$values['defaultDiscountPct'] = $type === 'discount' ? $values['defaultDiscountPct'] : null;
			$values['defaultMasterPct'] = $type === 'master' ? $values['defaultMasterPct'] : null;

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
		unset($ids);

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

			/** @var \StORM\Collection $relations */
			$relations = $values['bulkType'] === 'selected' ? $this->relatedRepository->many()->where('uuid', $ids) : $grid->getFilteredSource();

			$tempFilename = \tempnam($this->tempDir, 'csv');

			$this->application->onShutdown[] = function () use ($tempFilename): void {
				try {
					FileSystem::delete($tempFilename);
				} catch (\Throwable $e) {
					Debugger::log($e, ILogger::WARNING);
				}
			};

			$this->relatedRepository->exportCsv(Writer::createFromPath($tempFilename, 'w+'), $relations);

			$this->getPresenter()->sendResponse(new FileResponse($tempFilename, 'relations.csv', 'text/csv'));
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
type - Kód typu<br>
			master - Kód/EAN master produktu<br>
			slave - Kód/EAN slave produktu<br>
			amount - Množství - celé číslo větší nebo rovno 1<br>
			discountPct - Procentuální sleva - 0 až 100<br>
			masterPct - Procentuální cena z master produktu - číslo větší než 0<br>
			priority - Priorita - celé číslo<br>
			hidden - Skryto - 0/1<br>
			shops - Obchody oddělené čárkou<br><br>
			
Sloupce discountPct a masterPct <b>nejsou</b> kombinovatelné a může být nastavený vždy maxímálně jeden nebo žádný.<br>
Pokud nebude nalezen produkt tak se daný řádek ignoruje. V případě chyby nedojde k žádným změnám.');
		$form->addSubmit('submit', 'Uložit');

		$form->onRender[] = function (AdminForm $form): void {
			$presenter = $form->getPresenter();

			foreach ($presenter->template->flashes as $flash) {
				$form->addError(Html::fromHtml($flash->message));
			}
		};

		$form->onSuccess[] = function (Form $form): void {
			$values = $form->getValues('array');

			/** @var \Nette\Http\FileUpload $file */
			$file = $values['file'];

			$connection = $this->productRepository->getConnection();

			$connection->getLink()->beginTransaction();

			try {
				$result = $this->relatedRepository->importCsv($file->getContents());

				$connection->getLink()->commit();

				$notFoundRelationTypes = $result['notFoundRelationTypes'] ? \implode('<br>', $result['notFoundRelationTypes']) : '-';
				$notFoundProducts = $result['notFoundProducts'] ? \implode('<br>', $result['notFoundProducts']) : '-';

				$this->flashMessage("Provedeno: {$result['importedCount']}<br>
Nenalezené vazby:<br>$notFoundRelationTypes<br>
Nenalezené produkty:<br>$notFoundProducts<br>
", 'success');
			} catch (\Exception $e) {
				$connection->getLink()->rollBack();

				$this->flashMessage($e->getMessage() !== '' ? $e->getMessage() : 'Import dat se nezdařil!', 'error');
			}

			$form->getPresenter()->redirect('this');
		};

		return $form;
	}

	protected function startup(): void
	{
		parent::startup();

		$this->relatedType = $this->relatedTypeRepository->one($this->tab);
	}
}
