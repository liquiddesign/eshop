<?php

declare(strict_types=1);

namespace Eshop\Admin;

use Admin\BackendPresenter;
use Admin\Controls\AdminForm;
use Admin\Controls\AdminGrid;
use Eshop\DB\DisplayDelivery;
use Eshop\DB\DisplayDeliveryRepository;
use Nette\Forms\Form;

class DisplayDeliveryPresenter extends BackendPresenter
{
	/** @inject */
	public DisplayDeliveryRepository $displayDeliveryRepository;

	public function createComponentGrid(): AdminGrid
	{
		$grid = $this->gridFactory->create($this->displayDeliveryRepository->many(), 20, 'priority', 'ASC');

		$grid->addColumnSelector();

		$grid->addColumnText('Popisek', 'label', '%s', 'label');
		$grid->addColumnText('Časový práh', 'timeThreshold', '%s', 'timeThreshold');
		$grid->addColumnInputInteger('Priorita', 'priority', '', '', 'priority', [], true);

		$grid->addColumnLinkDetail();
		$grid->addColumnActionDelete();

		$grid->addButtonSaveAll();
		$grid->addButtonDeleteSelected();

		$grid->addFilterTextInput('search', ['label_cs'], null, 'Popisek');
		$grid->addFilterButtons();

		return $grid;
	}

	public function createComponentNewForm(): AdminForm
	{
		$form = $this->formFactory->create(true);

		$form->addLocaleText('label', 'Popisek');
		$form->addInteger('priority', 'Priorita')->setDefaultValue(10)->setRequired();
		$timeThreshold = $form->addText('timeThreshold', 'Časový práh')->setHtmlType('time')
			->setNullable()
			->setHtmlAttribute('data-info', 'Pokud nastavíte u produktu doručení na automatické a zvolíte časový práh, tak se bude zobrazovat popisek před a po v závislosti na skutečném čase.')
			->addCondition(Form::FILLED);

		foreach ($form->getMutations() as $mutation) {
			$timeThreshold->toggle("frm-newForm-beforeTimeThresholdLabel-$mutation-toogle")->toggle("frm-newForm-afterTimeThresholdLabel-$mutation-toogle");
		}

		$form->addLocaleText('beforeTimeThresholdLabel', 'Popisek před');
		$form->addLocaleText('afterTimeThresholdLabel', 'Popisek po');

		$form->addSubmits(!$this->getParameter('displayDelivery'));

		$form->onSuccess[] = function (AdminForm $form): void {
			$values = $form->getValues('array');

			$displayDelivery = $this->displayDeliveryRepository->syncOne($values);

			$this->flashMessage('Uloženo', 'success');

			$form->processRedirect('detail', 'default', [$displayDelivery]);
		};

		return $form;
	}

	public function renderDefault(): void
	{
		$this->template->headerLabel = 'Doručení';
		$this->template->headerTree = [
			['Doručení'],
		];
		$this->template->displayButtons = [$this->createNewItemButton('new')];
		$this->template->displayControls = [$this->getComponent('grid')];
	}

	public function renderNew(): void
	{
		$this->template->headerLabel = 'Nová položka';
		$this->template->headerTree = [
			['Zobrazovaná doprava', 'default'],
			['Nová položka'],
		];
		$this->template->displayButtons = [$this->createBackButton('default')];
		$this->template->displayControls = [$this->getComponent('newForm')];
	}

	public function renderDetail(): void
	{
		$this->template->headerLabel = 'Detail';
		$this->template->headerTree = [
			['Zobrazovaná doprava', 'default'],
			['Detail'],
		];
		$this->template->displayButtons = [$this->createBackButton('default')];
		$this->template->displayControls = [$this->getComponent('newForm')];
	}

	public function actionDetail(DisplayDelivery $displayDelivery): void
	{
		/** @var \Admin\Controls\AdminForm $form */
		$form = $this->getComponent('newForm');
		$form->setDefaults($displayDelivery->jsonSerialize());
	}
}
