<?php
declare(strict_types=1);

namespace Eshop\Admin;

use Admin\BackendPresenter;
use Admin\Controls\AdminForm;
use Admin\Controls\AdminGrid;
use Eshop\DB\CurrencyRepository;
use Eshop\DB\Tax;
use Eshop\DB\TaxRepository;
use Nette\Forms\Controls\TextInput;
use Nette\Http\Request;
use Pages\DB\PageRepository;
use StORM\DIConnection;

class TaxPresenter extends BackendPresenter
{
	#[\Nette\DI\Attributes\Inject]
	public TaxRepository $taxRepository;
	
	#[\Nette\DI\Attributes\Inject]
	public PageRepository $pageRepository;
	
	#[\Nette\DI\Attributes\Inject]
	public CurrencyRepository $currencyRepository;
	
	#[\Nette\DI\Attributes\Inject]
	public Request $request;
	
	public function createComponentGrid(): AdminGrid
	{
		$grid = $this->gridFactory->create($this->taxRepository->many(), 20, 'name', 'ASC', true);
		$grid->addColumnSelector();
		$grid->addColumnText('Měna', 'currency.code', '%s', 'currency.code', ['class' => 'fit']);
		$grid->addColumnText('Název', 'name', '%s', 'name');
		
		$grid->addColumnInputFloat('Cena', 'price', '', '', 'price', [], true);
		
		$grid->addColumnLinkDetail('Detail');
		$grid->addColumnActionDelete();

		$grid->addButtonSaveAll(['price'], ['price' => 'float'], null, false, null, null, false);
		$grid->addButtonDeleteSelected();
		
		$grid->addFilterTextInput('search', ['name_cs'], null, 'Název');
		$grid->addFilterSelectInput('currency', 'fk_currency = :q', 'Měna', '- Měna -', null, $this->currencyRepository->getArrayForSelect());
		$grid->addFilterButtons();
		
		return $grid;
	}
	
	public function createComponentNewForm(): AdminForm
	{
		$form = $this->formFactory->create(true);
		$form->addLocaleText('name', 'Název')->forPrimary(function (TextInput $input): void {
			$input->setRequired();
		});
		$form->addDataSelect('currency', 'Měna', $this->currencyRepository->getArrayForSelect());
		$form->addText('price', 'Cena')->addRule($form::FLOAT)->setNullable();
		
		$tax = $this->getParameter('tax');
		
		$form->addSubmits(!$tax);
		
		$form->onSuccess[] = function (AdminForm $form): void {
			$values = $form->getValues('array');
			
			if (!$values['uuid']) {
				$values['uuid'] = DIConnection::generateUuid();
			}

			$tax = $this->taxRepository->syncOne($values, null, true);
			
			$this->flashMessage('Uloženo', 'success');
			$form->processRedirect('detail', 'default', [$tax]);
		};
		
		return $form;
	}
	
	public function renderDefault(): void
	{
		$this->template->headerLabel = 'Poplatky a daně';
		$this->template->headerTree = [
			['Poplatky a daně'],
		];
		$this->template->displayButtons = [$this->createNewItemButton('new')];
		$this->template->displayControls = [$this->getComponent('grid')];
	}
	
	public function renderNew(): void
	{
		$this->template->headerLabel = 'Nová položka';
		$this->template->headerTree = [
			['Poplatky a daně', 'default'],
			['Nová položka'],
		];
		$this->template->displayButtons = [$this->createBackButton('default')];
		$this->template->displayControls = [$this->getComponent('newForm')];
	}
	
	public function renderDetail(): void
	{
		$this->template->headerLabel = 'Detail';
		$this->template->headerTree = [
			['Poplatky a daně', 'default'],
			['Detail'],
		];
		$this->template->displayButtons = [$this->createBackButton('default')];
		$this->template->displayControls = [$this->getComponent('newForm')];
	}
	
	public function actionDetail(Tax $tax): void
	{
		/** @var \Forms\Form $form */
		$form = $this->getComponent('newForm');
		
		$form->setDefaults($tax->toArray());
	}
}
