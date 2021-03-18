<?php

declare(strict_types=1);

namespace Eshop\Admin;

use Admin\BackendPresenter;
use App\Admin\Controls\AdminForm;
use App\Admin\Controls\AdminFormFactory;
use Eshop\DB\DisplayDelivery;
use Eshop\DB\DisplayDeliveryRepository;

class DisplayDeliveryPresenter extends BackendPresenter
{
	/** @inject */
	public DisplayDeliveryRepository $displayDeliveryRepository;
	
	public function createComponentGrid()
	{
		$grid = $this->gridFactory->create($this->displayDeliveryRepository->many(), 20, 'priority', 'ASC');
		
		$grid->addColumnSelector();
		
		$grid->addColumnText('Popisek', 'label', '%s', 'label');
		$grid->addColumnText('Dní od', 'daysFrom', '%s', 'daysFrom', ['class' => 'fit'])->onRenderCell[] = [$grid, 'decoratorNumber'];
		$grid->addColumnText('Dní do', 'daysTo', '%s', 'daysTo', ['class' => 'fit'])->onRenderCell[] = [$grid, 'decoratorNumber'];
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
		$form = $this->formFactory->create();
		
		$form->addLocaleText('label', 'Popisek');
		$form->addIntegerNullable('daysFrom', 'Dní od');
		$form->addIntegerNullable('daysTo', 'Dní do');
		$form->addInteger('priority', 'Priorita')->setDefaultValue(10);
		
		$form->addSubmits(!$this->getParameter('displayDelivery'));
		
		$form->onSuccess[] = function (AdminForm $form) {
			$values = $form->getValues('array');
			
			$displayDelivery = $this->displayDeliveryRepository->syncOne($values);
			
			$this->flashMessage('Uloženo', 'success');
			
			$form->processRedirect('detail', 'default', [$displayDelivery]);
		};
		
		return $form;
	}
	
	public function renderDefault()
	{
		$this->template->headerLabel = 'Doručení';
		$this->template->headerTree = [
			['Doručení'],
		];
		$this->template->displayButtons = [$this->createNewItemButton('new')];
		$this->template->displayControls = [$this->getComponent('grid')];
	}
	
	public function renderNew()
	{
		$this->template->headerLabel = 'Nová položka';
		$this->template->headerTree = [
			['Zobrazovaná doprava', 'default'],
			['Nová položka'],
		];
		$this->template->displayButtons = [$this->createBackButton('default')];
		$this->template->displayControls = [$this->getComponent('newForm')];
	}
	
	public function renderDetail()
	{
		$this->template->headerLabel = 'Detail';
		$this->template->headerTree = [
			['Zobrazovaná doprava', 'default'],
			['Detail'],
		];
		$this->template->displayButtons = [$this->createBackButton('default')];
		$this->template->displayControls = [$this->getComponent('newForm')];
	}
	
	public function actionDetail(DisplayDelivery $displayDelivery)
	{
		/** @var AdminForm $form */
		$form = $this->getComponent('newForm');
		$form->setDefaults($displayDelivery->jsonSerialize());
	}
}