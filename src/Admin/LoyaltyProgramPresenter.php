<?php

declare(strict_types=1);

namespace Eshop\Admin;

use Admin\Controls\AdminForm;
use Admin\Controls\AdminGrid;
use Eshop\BackendPresenter;
use Eshop\DB\CurrencyRepository;
use Eshop\DB\CustomerRepository;
use Eshop\DB\LoyaltyProgram;
use Eshop\DB\LoyaltyProgramDiscountLevel;
use Eshop\DB\LoyaltyProgramDiscountLevelRepository;
use Eshop\DB\LoyaltyProgramHistory;
use Eshop\DB\LoyaltyProgramHistoryRepository;
use Eshop\DB\LoyaltyProgramRepository;
use Eshop\FormValidators;
use Grid\Datagrid;
use Nette\Caching\Cache;
use Nette\Caching\Storage;
use StORM\Collection;
use StORM\DIConnection;

class LoyaltyProgramPresenter extends BackendPresenter
{
	public const TABS = [
		'programs' => 'Programy',
		'levels' => 'Slevové hladiny',
		'history' => 'Historie',
	];

	/** @persistent */
	public string $tab = 'programs';

	#[\Nette\DI\Attributes\Inject]
	public LoyaltyProgramRepository $loyaltyProgramRepository;

	#[\Nette\DI\Attributes\Inject]
	public LoyaltyProgramDiscountLevelRepository $loyaltyProgramDiscountLevelRepository;

	#[\Nette\DI\Attributes\Inject]
	public LoyaltyProgramHistoryRepository $loyaltyProgramHistoryRepository;

	#[\Nette\DI\Attributes\Inject]
	public CurrencyRepository $currencyRepository;

	#[\Nette\DI\Attributes\Inject]
	public CustomerRepository $customerRepository;

	#[\Nette\DI\Attributes\Inject]
	public Storage $storage;

	private Cache $cache;

	public function createComponentProgramGrid(): AdminGrid
	{
		$grid = $this->gridFactory->create($this->loyaltyProgramRepository->many(), 20, 'name', 'ASC', true);
		$grid->addColumnSelector();

		$grid->addColumnText('Název', 'name', '%s', 'name');
		$grid->addColumnText('Platnost od', "validFrom|date:'d.m.Y G:i'", '%s', 'validFrom', ['class' => 'fit'])->onRenderCell[] = [$grid, 'decoratorNowrap'];
		$grid->addColumnText('Platnost do', "validTo|date:'d.m.Y G:i'", '%s', 'validTo', ['class' => 'fit'])->onRenderCell[] = [$grid, 'decoratorNowrap'];
		$grid->addColumnText('Obrat od', "turnoverFrom|date:'d.m.Y G:i'", '%s', 'validTo', ['class' => 'fit'])->onRenderCell[] = [$grid, 'decoratorNowrap'];
		$grid->addColumnText('Měna', 'currency.code', '%s', 'currency.code', ['class' => 'fit'])->onRenderCell[] = [$grid, 'decoratorNowrap'];

		$btnSecondary = 'btn btn-sm btn-outline-primary';

		$grid->addColumn('', function (LoyaltyProgram $object, Datagrid $datagrid) use ($btnSecondary): string {
			$objects = $this->loyaltyProgramRepository->getLevelsByProgram($object);

			return \count($objects) > 0 ?
				"<a class='$btnSecondary' href='" . $datagrid->getPresenter()->link('this', ['tab' => 'levels', 'levelGrid-loyaltyProgram' => $object->getPK(),]) . "'>Slevové hladiny</a>" :
				"<a class='$btnSecondary' href='" . $datagrid->getPresenter()->link('levelNew', $object) . "'>Vytvořit&nbsp;slevovou&nbsp;hladinu</a>";
		}, '%s', null, ['class' => 'minimal']);

		$grid->addColumnLinkDetail('programDetail');
		$grid->addColumnActionDelete();

		$grid->addButtonDeleteSelected();

		$grid->addFilterTextInput('search', ['name_cs'], null, 'Název');
		$grid->addFilterButtons();

		$grid->onDelete[] = [$this, 'clearCache'];

		return $grid;
	}

	public function createComponentProgramForm(): AdminForm
	{
		$form = $this->formFactory->create(true);

		$object = $this->getParameter('program');

		$form->addLocaleText('name', 'Název')->forPrimary(function ($input): void {
			$input->setRequired();
		});

		$form->addDatetime('validFrom', 'Platný od')->setNullable(true);
		$form->addDatetime('validTo', 'Platný do')->setNullable(true);
		$form->addDatetime('turnoverFrom', 'Obrat od')->setNullable(true)->setHtmlAttribute('data-info', 'Pokud nevyplníte, bude brán v potaz veškerý obrat zákazníka.');

		$form->addSelect2('currency', 'Měna', $this->currencyRepository->getArrayForSelectFromCollection($this->currencyRepository->getCollection(true)->where('cashback', true)))->setRequired()
			->setHtmlAttribute('data-info', 'Zobrazuje pouze měny označené jako "Cashback".');

		$form->addSubmits(!$object);

		$form->onSuccess[] = function (AdminForm $form): void {
			$values = $form->getValues('array');

			if (!$values['uuid']) {
				$values['uuid'] = DIConnection::generateUuid();
			}

			$object = $this->loyaltyProgramRepository->syncOne($values, null, true);

			$this->clearCache();

			$this->flashMessage('Uloženo', 'success');
			$form->processRedirect('programDetail', 'default', [$object]);
		};

		return $form;
	}

	public function renderDefault(): void
	{
		$this->template->tabs = $this::TABS;

		if ($this->tab === 'programs') {
			$this->template->headerLabel = 'Věrnostní programy';
			$this->template->headerTree = [
				['Věrnostní programy'],
			];
			$this->template->displayButtons = [$this->createNewItemButton('programNew')];
			$this->template->displayControls = [$this->getComponent('programGrid')];
		} elseif ($this->tab === 'levels') {
			$this->template->headerLabel = 'Slevové hladiny';
			$this->template->headerTree = [
				['Věrnostní programy'],
				['Slevové hladiny'],
			];
			$this->template->displayButtons = [$this->createNewItemButton('levelNew')];
			$this->template->displayControls = [$this->getComponent('levelGrid')];
		} elseif ($this->tab === 'history') {
			$this->template->headerLabel = 'Historie';
			$this->template->headerTree = [
				['Věrnostní programy'],
				['Historie'],
			];
			$this->template->displayButtons = [$this->createNewItemButton('historyNew')];
			$this->template->displayControls = [$this->getComponent('historyGrid')];
		}
	}

	public function renderProgramNew(): void
	{
		$this->template->headerLabel = 'Nová položka';
		$this->template->headerTree = [
			['Věrnostní programy', 'default'],
			['Nová položka'],
		];
		$this->template->displayButtons = [$this->createBackButton('default')];
		$this->template->displayControls = [$this->getComponent('programForm')];
	}

	public function actionProgramDetail(LoyaltyProgram $object): void
	{
		/** @var \Admin\Controls\AdminForm $form */
		$form = $this->getComponent('programForm');

		$form->setDefaults($object->toArray());
	}

	public function renderProgramDetail(): void
	{
		$this->template->headerLabel = 'Detail';
		$this->template->headerTree = [
			['Věrnostní programy', 'default'],
			['Detail'],
		];
		$this->template->displayButtons = [$this->createBackButton('default')];
		$this->template->displayControls = [$this->getComponent('programForm')];
	}

	public function createComponentLevelGrid(): AdminGrid
	{
		$grid = $this->gridFactory->create($this->loyaltyProgramDiscountLevelRepository->many(), 20, 'priceThreshold', 'ASC', true);
		$grid->addColumnSelector();

//		$grid->addColumnText('Věrnostní program', 'loyaltyProgram.name', '%s', 'loyaltyProgram.name');
		$grid->addColumnText('Obratový práh', 'priceThreshold', '%s', 'priceThreshold');
		$grid->addColumnText('Procentuální sleva', 'discountLevel', '%s %%', 'discountLevel');

		$grid->addColumnLinkDetail('levelDetail');
		$grid->addColumnActionDelete();

		$grid->addButtonDeleteSelected();

		if ($items = $this->loyaltyProgramRepository->getArrayForSelect()) {
			$grid->addFilterDataSelect(function (Collection $source, $value): void {
				$source->where('loyaltyProgram.uuid', $value);
			}, '', 'loyaltyProgram', null, $items)->setPrompt('- Věrnostní program -');
		}

		$grid->addFilterButtons();

		$grid->onDelete[] = [$this, 'clearCache'];

		return $grid;
	}

	public function createComponentLevelForm(): AdminForm
	{
		$form = $this->formFactory->create(true);

		$object = $this->getParameter('level');

		$form->addText('priceThreshold', 'Obratový práh')
			->setRequired()
			->setHtmlAttribute('Obratový práh od kterého platí procentuální sleva.')
			->addCondition($form::FILLED)
			->addRule($form::FLOAT)
			->endCondition()
			->addFilter(function ($value) {
				return $value !== null ? \floatval($value) : $value;
			});
		$form->addText('discountLevel', 'Procentuální sleva (%)')
			->setHtmlAttribute(
				'data-info',
				'Aplikuje se vždy největší z čtveřice: procentuální slevy produktu, procentuální slevy zákazníka, slevy věrnostního programu zákazníka nebo slevového kupónu.<br>
Platí jen pokud má ceník povoleno "Povolit procentualni slevy".',
			)
			->setRequired()
			->setDefaultValue(0)
			->addRule($form::INTEGER)
			->addRule([FormValidators::class, 'isPercent'], 'Zadaná hodnota není procento!');
		$form->addSelect2('loyaltyProgram', 'Věrnostní program', $this->loyaltyProgramRepository->getArrayForSelect())->setRequired();

		$form->addSubmits(!$object);

		$form->onSuccess[] = function (AdminForm $form): void {
			$values = $form->getValues('array');

			if (!$values['uuid']) {
				$values['uuid'] = DIConnection::generateUuid();
			}

			$object = $this->loyaltyProgramDiscountLevelRepository->syncOne($values, null, true);

			$this->clearCache();

			$this->flashMessage('Uloženo', 'success');
			$form->processRedirect('levelDetail', 'default', [$object]);
		};

		return $form;
	}

	public function actionLevelNew(?LoyaltyProgram $loyaltyProgram = null): void
	{
		if ($loyaltyProgram !== null) {
			/** @var \Admin\Controls\AdminForm $form */
			$form = $this->getComponent('levelForm');

			$form->setDefaults(['loyaltyProgram' => $loyaltyProgram->getPK()]);
		}
	}

	public function renderLevelNew(): void
	{
		$this->template->headerLabel = 'Nová položka';
		$this->template->headerTree = [
			['Věrnostní programy', 'default'],
			['Procentuální sleva', 'default'],
			['Nová položka'],
		];
		$this->template->displayButtons = [$this->createBackButton('default')];
		$this->template->displayControls = [$this->getComponent('levelForm')];
	}

	public function actionLevelDetail(LoyaltyProgramDiscountLevel $level): void
	{
		/** @var \Admin\Controls\AdminForm $form */
		$form = $this->getComponent('levelForm');

		$form->setDefaults($level->toArray());
	}

	public function renderLevelDetail(): void
	{
		$this->template->headerLabel = 'Detail';
		$this->template->headerTree = [
			['Věrnostní programy', 'default'],
			['Procentuální sleva', 'default'],
			['Detail'],
		];
		$this->template->displayButtons = [$this->createBackButton('default')];
		$this->template->displayControls = [$this->getComponent('levelForm')];
	}

	public function createComponentHistoryGrid(): AdminGrid
	{
		$grid = $this->gridFactory->create($this->loyaltyProgramHistoryRepository->many(), 20, 'createdTs', 'DESC', true);
		$grid->addColumnSelector();

		$grid->addColumnText('Vytvořeno', "createdTs|date:'d.m.Y G:i'", '%s', 'createdTs', ['class' => 'fit']);
		$grid->addColumnText('Zákazník', 'customer.fullname', '%s', 'customer.fullname');
		$grid->addColumnText('Věrnostní program', 'loyaltyProgram.name', '%s', 'loyaltyProgram.name');
		$grid->addColumnText('Změna bodů', 'points', '%s', 'points');

		$grid->addColumnLinkDetail('historyDetail');
		$grid->addColumnActionDelete();

		$grid->addButtonDeleteSelected();

		if ($items = $this->loyaltyProgramRepository->getArrayForSelect()) {
			$grid->addFilterDataSelect(function (Collection $source, $value): void {
				$source->where('loyaltyProgram.uuid', $value);
			}, '', 'loyaltyProgram', null, $items)->setPrompt('- Věrnostní program -');
		}

		if ($items = $this->customerRepository->getArrayForSelect()) {
			$grid->addFilterDataSelect(function (Collection $source, $value): void {
				$source->where('customer.uuid', $value);
			}, '', 'customer', null, $items)->setPrompt('- Zákazník -');
		}

		$grid->addFilterButtons();

		$grid->onDelete[] = [$this, 'clearCache'];

		return $grid;
	}

	public function createComponentHistoryForm(): AdminForm
	{
		$form = $this->formFactory->create(true);

		$object = $this->getParameter('history');

		$form->addText('points', 'Změna bodů')->setRequired()->addRule($form::FLOAT);
		$form->addDatetime('createdTs', 'Vytvořeno')->setDisabled();
		$inputCustomer = $form->addSelect2('customer', 'Zákazník', $this->customerRepository->getArrayForSelect());
		$inputProgram = $form->addSelect2('loyaltyProgram', 'Věrnostní program', $this->loyaltyProgramRepository->getArrayForSelect());

		if ($object) {
			$inputCustomer->setDisabled();
			$inputProgram->setDisabled();
		}

		$form->addSubmits(!$object);

		$form->onSuccess[] = function (AdminForm $form): void {
			$values = $form->getValues('array');

			if (!$values['uuid']) {
				$values['uuid'] = DIConnection::generateUuid();
			}

			$object = $this->loyaltyProgramHistoryRepository->syncOne($values, null, true);

			$this->clearCache();

			$this->flashMessage('Uloženo', 'success');
			$form->processRedirect('levelDetail', 'default', [$object]);
		};

		return $form;
	}

	public function renderHistoryNew(): void
	{
		$this->template->headerLabel = 'Nová položka';
		$this->template->headerTree = [
			['Věrnostní programy', 'default'],
			['Historie', 'default'],
			['Nová položka'],
		];
		$this->template->displayButtons = [$this->createBackButton('default')];
		$this->template->displayControls = [$this->getComponent('historyForm')];
	}

	public function actionHistoryDetail(LoyaltyProgramHistory $history): void
	{
		/** @var \Admin\Controls\AdminForm $form */
		$form = $this->getComponent('historyForm');

		$form->setDefaults($history->toArray());
	}

	public function renderHistoryDetail(): void
	{
		$this->template->headerLabel = 'Detail';
		$this->template->headerTree = [
			['Věrnostní programy', 'default'],
			['Historie', 'default'],
			['Detail'],
		];
		$this->template->displayButtons = [$this->createBackButton('default')];
		$this->template->displayControls = [$this->getComponent('historyForm')];
	}

	public function clearCache(): void
	{
		$this->cache->clean([
			Cache::TAGS => ['products', 'pricelists'],
		]);
	}

	protected function startup(): void
	{
		parent::startup();

		$this->cache = new Cache($this->storage);
	}
}
