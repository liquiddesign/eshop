<?php

declare(strict_types=1);

namespace Eshop\Admin;

use Admin\BackendPresenter;
use Admin\Controls\AdminForm;
use Admin\Controls\AdminGrid;
use Eshop\DB\DeliveryCarrier;
use Eshop\DB\DeliveryCarrierRepository;
use Eshop\DB\DeliveryRegion;
use Eshop\DB\DeliveryRegionRepository;
use Forms\Form;
use Nette\DI\Attributes\Inject;
use Nette\Http\Request;

/**
 * Správa dopravců (rozvozových aut) — per shop. Každý dopravce má přiřazené kraje (M:N přes pivot DeliveryCarrier::regions).
 */
class DeliveryCarrierPresenter extends BackendPresenter
{
	#[Inject]
	public DeliveryCarrierRepository $deliveryCarrierRepository;

	#[Inject]
	public DeliveryRegionRepository $deliveryRegionRepository;

	#[Inject]
	public Request $request;

	public function createComponentGrid(): AdminGrid
	{
		$grid = $this->gridFactory->create($this->deliveryCarrierRepository->many(), 20, 'priority', 'ASC', true);
		$grid->addColumnSelector();

		$grid->addColumnText('Kód', 'code', '%s', 'code', ['class' => 'minimal']);
		$grid->addColumnText('Název', 'name', '%s', 'name');
		$grid->addColumn('Kraje', static function (DeliveryCarrier $carrier): string {
			$names = [];

			foreach ($carrier->regions->orderBy(['priority' => 'ASC'])->toArray() as $region) {
				$names[] = $region->name;
			}

			return \implode(', ', $names);
		});
		$grid->addColumnText('QI TransportTypeID', 'qiTransportTypeId', '%s', 'qiTransportTypeId', ['class' => 'minimal']);
		$grid->addColumnText('QI GoodsID dopravy', 'qiDeliveryGoodsId', '%s', 'qiDeliveryGoodsId', ['class' => 'minimal']);
		$grid->addColumnInputInteger('Priorita', 'priority', '', '', 'priority', [], true);
		$grid->addColumnInputCheckbox('<i title="Skryto" class="far fa-eye-slash"></i>', 'hidden', '', '', 'hidden');

		$grid->addColumnLinkDetail('Detail');
		$grid->addColumnActionDelete();

		$grid->addButtonSaveAll();

		$grid->addFilterTextInput('search', ['code', 'name'], null, 'Kód nebo název');
		$grid->addFilterButtons();

		return $grid;
	}

	public function createComponentForm(): Form
	{
		$form = $this->formFactory->create(true);

		/** @var \Eshop\DB\DeliveryCarrier|null $carrier */
		$carrier = $this->getParameter('deliveryCarrier');

		$form->addText('code', 'Kód')
			->setRequired()
			->addRule(Form::PATTERN, 'Pouze malá písmena, číslice a pomlčky', '[a-z0-9-]+');
		$form->addLocaleText('name', 'Název');
		$form->addInteger('priority', 'Priorita')->setDefaultValue(10);
		$form->addCheckbox('hidden', 'Skryto');

		$form->addText('qiTransportTypeId', 'QI TransportTypeID')
			->setRequired()
			->setHtmlAttribute('placeholder', 'Např. 12345,10')
			->setOption('description', 'Odesílá se v hlavičce QI objednávky (DocumentHeader/TransportTypeID).');
		$form->addText('qiDeliveryGoodsId', 'QI GoodsID dopravy')
			->setRequired()
			->setHtmlAttribute('placeholder', 'Např. 67890,10')
			->setOption('description', 'Odesílá se jako kód položky s cenou dopravy v QI objednávce.');

		$form->addMultiSelect2('regions', 'Kraje', $this->deliveryRegionRepository->getArrayForSelect());

		$form->addSubmits(!$carrier);

		$form->onSuccess[] = function (AdminForm $form): void {
			/** @var array<mixed> $values */
			$values = $form->getValues('array');

			$regionPks = $values['regions'] ?? [];
			unset($values['regions']);

			$entity = $this->deliveryCarrierRepository->syncOne($values, ignore: false);

			$entity->regions->unrelateAll();

			foreach ($regionPks as $regionPk) {
				$entity->regions->relate([$regionPk]);
			}

			$this->flashMessage('Uloženo', 'success');
			$form->processRedirect('detail', 'default', [$entity]);
		};

		return $form;
	}

	public function renderDefault(): void
	{
		$this->template->headerLabel = 'Dopravci (rozvozová auta)';
		$this->template->headerTree = [
			['Dopravci (rozvozová auta)'],
		];
		$this->template->displayButtons = [$this->createNewItemButton('new')];
		$this->template->displayControls = [$this->getComponent('grid')];
	}

	public function renderNew(): void
	{
		$this->template->headerLabel = 'Nový dopravce';
		$this->template->headerTree = [
			['Dopravci (rozvozová auta)', 'default'],
			['Nový'],
		];
		$this->template->displayButtons = [$this->createBackButton('default')];
		$this->template->displayControls = [$this->getComponent('form')];
	}

	public function actionDetail(DeliveryCarrier $deliveryCarrier): void
	{
		/** @var \Forms\Form $form */
		$form = $this->getComponent('form');

		$values = $deliveryCarrier->toArray();
		$values['regions'] = \array_map(static fn (DeliveryRegion $r): string => $r->getPK(), $deliveryCarrier->regions->toArray());

		$form->setDefaults($values);
	}

	public function renderDetail(DeliveryCarrier $deliveryCarrier): void
	{
		$this->template->headerLabel = 'Detail: ' . ($deliveryCarrier->name ?? $deliveryCarrier->code);
		$this->template->headerTree = [
			['Dopravci (rozvozová auta)', 'default'],
			['Detail'],
		];
		$this->template->displayButtons = [$this->createBackButton('default')];
		$this->template->displayControls = [$this->getComponent('form')];
	}
}
