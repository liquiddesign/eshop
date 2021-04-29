<?php
declare(strict_types=1);

namespace Eshop\Admin;

use Admin\BackendPresenter;
use Admin\Controls\AdminForm;
use Eshop\DB\CurrencyRepository;
use Eshop\DB\Tag;
use Eshop\DB\Tax;
use Eshop\DB\TaxRepository;
use Forms\Form;
use Nette\Http\Request;
use Pages\DB\PageRepository;
use StORM\DIConnection;

class TaxPresenter extends BackendPresenter
{
	/** @inject */
	public TaxRepository $taxRepository;
	
	/** @inject */
	public PageRepository $pageRepository;
	
	/** @inject */
	public CurrencyRepository $currencyRepository;
	
	/** @inject */
	public Request $request;
	
	public function createComponentGrid()
	{
		$grid = $this->gridFactory->create($this->taxRepository->many(), 20, 'name', 'ASC', true);
		$grid->addColumnSelector();
		$grid->addColumnText('Měna', 'currency.code', '%s', 'currency.code', ['class' => 'fit']);
		$grid->addColumnText('Název', 'name', '%s', 'name');
		
		$grid->addColumnInputFloat('Cena', 'price', '', '', 'price', [], true);
		
		$grid->addColumnLinkDetail('Detail');
		$grid->addColumnActionDelete();
		
		$grid->addButtonSaveAll();
		$grid->addButtonDeleteSelected();
		
		$grid->addFilterTextInput('search', ['name_cs'], null, 'Název');
		$grid->addFilterSelectInput('currency', 'fk_currency = :q', 'Měna', '- Měna -', null, $this->currencyRepository->getArrayForSelect());
		$grid->addFilterButtons();
		
		return $grid;
	}
	
	public function createComponentNewForm(): AdminForm
	{
		$form = $this->formFactory->create(true);
		$form->addLocaleText('name', 'Název');
		$form->addDataSelect('currency', 'Měna', $this->currencyRepository->getArrayForSelect());
		$form->addText('price', 'Cena')->addRule($form::FLOAT)->setNullable();
		
		$tax = $this->getParameter('tax');
		
		$form->addSubmits(!$tax);
		
		$form->onSuccess[] = function (AdminForm $form) {
			$values = $form->getValues('array');
			
			$this->createImageDirs(Tag::IMAGE_DIR);
			
			if (!$values['uuid']) {
				$values['uuid'] = DIConnection::generateUuid();
			}
			$tax = $this->taxRepository->syncOne($values, null, true);
			
			$this->flashMessage('Uloženo', 'success');
			$form->processRedirect('detail', 'default', [$tax]);
		};
		
		return $form;
	}
	
	public function renderDefault()
	{
		$this->template->headerLabel = 'Poplatky a daně';
		$this->template->headerTree = [
			['Poplatky a daně'],
		];
		$this->template->displayButtons = [$this->createNewItemButton('new')];
		$this->template->displayControls = [$this->getComponent('grid')];
	}
	
	public function renderNew()
	{
		$this->template->headerLabel = 'Nová položka';
		$this->template->headerTree = [
			['Poplatky a daně', 'default'],
			['Nová položka'],
		];
		$this->template->displayButtons = [$this->createBackButton('default')];
		$this->template->displayControls = [$this->getComponent('newForm')];
	}
	
	public function renderDetail()
	{
		$this->template->headerLabel = 'Detail';
		$this->template->headerTree = [
			['Poplatky a daně', 'default'],
			['Detail'],
		];
		$this->template->displayButtons = [$this->createBackButton('default')];
		$this->template->displayControls = [$this->getComponent('newForm')];
	}
	
	public function actionDetail(Tax $tax)
	{
		/** @var Form $form */
		$form = $this->getComponent('newForm');
		
		$form->setDefaults($tax->toArray());
	}
}