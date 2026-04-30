<?php

declare(strict_types=1);

namespace Eshop\Admin;

use Admin\BackendPresenter;
use Admin\Controls\AdminForm;
use Admin\Controls\AdminGrid;
use Eshop\DB\DeliveryRegion;
use Eshop\DB\DeliveryRegionRepository;
use Eshop\DB\DeliveryRegionZip;
use Eshop\DB\DeliveryRegionZipRepository;
use Forms\Form;
use Nette\Application\Attributes\Persistent;
use Nette\DI\Attributes\Inject;
use StORM\ICollection;

/**
 * Správa krajů (globální) a jejich PSČ mapování. Každé PSČ patří do právě jednoho kraje.
 * Hraniční obce může admin ručně přemapovat.
 */
class DeliveryRegionPresenter extends BackendPresenter
{
	public const array TABS = [
		'regions' => 'Kraje',
		'zips' => 'Mapování PSČ',
	];

	#[Persistent]
	public string $tab = 'regions';

	#[Inject]
	public DeliveryRegionRepository $deliveryRegionRepository;

	#[Inject]
	public DeliveryRegionZipRepository $deliveryRegionZipRepository;

	public function createComponentGrid(): AdminGrid
	{
		$grid = $this->gridFactory->create($this->deliveryRegionRepository->many(), 20, 'priority', 'ASC', true);
		$grid->addColumnSelector();

		$grid->addColumnText('Kód', 'code', '%s', 'code', ['class' => 'minimal']);
		$grid->addColumnText('Název', 'name', '%s', 'name');
		$grid->addColumn('Počet PSČ', function (DeliveryRegion $region): int {
			return $this->deliveryRegionZipRepository->many()->where('fk_region', $region->getPK())->count();
		});
		$grid->addColumnInputInteger('Priorita', 'priority', '', '', 'priority', [], true);

		$grid->addColumnLinkDetail('Detail');
		$grid->addColumnActionDelete();

		$grid->addButtonSaveAll();

		$grid->addFilterTextInput('search', ['code', 'name'], null, 'Kód nebo název');
		$grid->addFilterButtons();

		return $grid;
	}

	public function createComponentZipGrid(): AdminGrid
	{
		$grid = $this->gridFactory->create($this->deliveryRegionZipRepository->many(), 50, 'zipcode', 'ASC', true, useShops: false);

		$grid->addColumnText('PSČ', 'zipcode', '%s', 'zipcode', ['class' => 'minimal']);
		$grid->addColumnText('Obec', 'municipality', '%s', 'municipality');
		$grid->addColumnText('Okres', 'district', '%s', 'district');
		$grid->addColumnText('Kraj', 'region.name', '%s');

		$grid->addColumnLinkDetail('zipDetail');
		$grid->addColumnActionDelete();

		$grid->addFilterTextInput('zipSearch', ['this.zipcode', 'this.municipality'], null, 'PSČ nebo obec');
		$grid->addFilterDataSelect(static function (ICollection $source, $value): void {
			$source->where('this.fk_region', $value);
		}, '', 'fk_region', null, $this->deliveryRegionRepository->getArrayForSelect())->setPrompt('- Kraj -');
		$grid->addFilterDataSelect(static function (ICollection $source, $value): void {
			$source->where('this.district', $value);
		}, '', 'district', null, $this->deliveryRegionZipRepository->getDistrictsForSelect())->setPrompt('- Okres -');
		$grid->addFilterButtons(['default', ['tab' => 'zips']]);

		return $grid;
	}

	public function createComponentForm(): Form
	{
		$form = $this->formFactory->create(true);

		/** @var \Eshop\DB\DeliveryRegion|null $region */
		$region = $this->getParameter('deliveryRegion');

		$form->addText('code', 'Kód')
			->setRequired()
			->addRule(Form::PATTERN, 'Pouze malá písmena, číslice a pomlčky', '[a-z0-9-]+');
		$form->addText('name', 'Název')->setRequired();
		$form->addInteger('priority', 'Priorita')->setDefaultValue(10);

		$form->addSubmits(!$region);

		$form->onSuccess[] = function (AdminForm $form): void {
			/** @var array<mixed> $values */
			$values = $form->getValues('array');

			$entity = $this->deliveryRegionRepository->syncOne($values, ignore: false);

			$this->flashMessage('Uloženo', 'success');
			$form->processRedirect('detail', 'default', [$entity]);
		};

		return $form;
	}

	public function createComponentZipForm(): Form
	{
		$form = $this->formFactory->create(true);

		/** @var \Eshop\DB\DeliveryRegionZip|null $zip */
		$zip = $this->getParameter('deliveryRegionZip');

		$form->addText('zipcode', 'PSČ')
			->setRequired()
			->addRule(Form::PATTERN, '5 číslic bez mezer', '[0-9]{5}');
		$form->addText('municipality', 'Obec')->setNullable();
		$form->addText('district', 'Okres')->setNullable();
		$form->addDataSelect('region', 'Kraj', $this->deliveryRegionRepository->getArrayForSelect())
			->setRequired();

		$form->addSubmits(!$zip);

		$form->onSuccess[] = function (AdminForm $form): void {
			/** @var array<mixed> $values */
			$values = $form->getValues('array');

			$entity = $this->deliveryRegionZipRepository->syncOne($values, ignore: false);

			$this->flashMessage('Uloženo', 'success');
			$form->processRedirect('zipDetail', 'default', [$entity], ['tab' => 'zips']);
		};

		return $form;
	}

	public function renderDefault(): void
	{
		$this->template->tabs = self::TABS;
		$this->template->headerLabel = 'Kraje a PSČ';
		$this->template->headerTree = [
			['Kraje a PSČ', 'default'],
		];

		if ($this->tab === 'zips') {
			$this->template->displayButtons = [$this->createNewItemButton('zipNew')];
			$this->template->displayControls = [$this->getComponent('zipGrid')];

			return;
		}

		$this->template->displayButtons = [$this->createNewItemButton('new')];
		$this->template->displayControls = [$this->getComponent('grid')];
	}

	public function renderNew(): void
	{
		$this->template->headerLabel = 'Nový kraj';
		$this->template->headerTree = [
			['Kraje a PSČ', 'default'],
			['Nový'],
		];
		$this->template->displayButtons = [$this->createBackButton('default')];
		$this->template->displayControls = [$this->getComponent('form')];
	}

	public function actionDetail(DeliveryRegion $deliveryRegion): void
	{
		/** @var \Forms\Form $form */
		$form = $this->getComponent('form');
		$form->setDefaults($deliveryRegion->toArray());
	}

	public function renderDetail(DeliveryRegion $deliveryRegion): void
	{
		$this->template->headerLabel = 'Detail: ' . $deliveryRegion->name;
		$this->template->headerTree = [
			['Kraje a PSČ', 'default'],
			['Detail'],
		];
		$this->template->displayButtons = [$this->createBackButton('default')];
		$this->template->displayControls = [$this->getComponent('form')];
	}

	public function renderZipNew(): void
	{
		$this->template->headerLabel = 'Nové PSČ';
		$this->template->headerTree = [
			['Kraje a PSČ', 'default'],
			['Nové'],
		];
		$this->template->displayButtons = [$this->createBackButton('default', ['tab' => 'zips'])];
		$this->template->displayControls = [$this->getComponent('zipForm')];
	}

	public function actionZipDetail(DeliveryRegionZip $deliveryRegionZip): void
	{
		/** @var \Forms\Form $form */
		$form = $this->getComponent('zipForm');
		$form->setDefaults($deliveryRegionZip->toArray());
	}

	public function renderZipDetail(DeliveryRegionZip $deliveryRegionZip): void
	{
		$this->template->headerLabel = 'PSČ ' . $deliveryRegionZip->zipcode;
		$this->template->headerTree = [
			['Kraje a PSČ', 'default'],
			['Detail'],
		];
		$this->template->displayButtons = [$this->createBackButton('default', ['tab' => 'zips'])];
		$this->template->displayControls = [$this->getComponent('zipForm')];
	}
}
