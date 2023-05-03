<?php
declare(strict_types=1);

namespace Eshop\Admin;

use Admin\BackendPresenter;
use Admin\Controls\AdminForm;
use Admin\Controls\AdminGrid;
use Eshop\DB\AddressRepository;
use Eshop\DB\Autoship;
use Eshop\DB\AutoshipRepository;
use Eshop\DB\CustomerRepository;
use Nette\Utils\Arrays;
use StORM\DIConnection;

class AutoshipPresenter extends BackendPresenter
{
	#[\Nette\DI\Attributes\Inject]
	public AutoshipRepository $autoshipRepository;
	
	#[\Nette\DI\Attributes\Inject]
	public CustomerRepository $customerRepository;
	
	#[\Nette\DI\Attributes\Inject]
	public AddressRepository $addressRepository;
	
	public function createComponentGrid(): AdminGrid
	{
		$grid = $this->gridFactory->create($this->autoshipRepository->many(), 20, 'createdTs', 'DESC', true);
		$grid->addColumnSelector();
		$grid->addColumnText('ID', 'id', '%s', 'id', ['class' => 'fit']);
		$grid->addColumnText('Vytvořeno', 'createdTs|date', '%s', 'createdTs', ['class' => 'fit']);
		$grid->addColumnText('Interval', 'dayInterval', '%s dnů', 'dayInterval', ['class' => 'fit']);
		$grid->addColumnText('Aktivní od', 'activeFrom|date', '%s', 'activeFrom', ['class' => 'fit']);
		$grid->addColumnText('Aktivní do', 'activeTo|date', '%s', 'activeTo', ['class' => 'fit']);
		$grid->addColumnText('Zákazník', 'purchase.fullname', '%s', null);
		$grid->addColumnText('E-mail', 'purchase.email', '%s', null, ['class' => 'fit']);
		$grid->addColumnText('Telefon', 'purchase.phone', '%s', null, ['class' => 'fit']);
		
		$grid->addColumnInputCheckbox('Aktivní', 'active', '', '', 'active');
		//$grid->addColumnInputFloat('Cena', 'price', '', '', 'price', [], true);
		
		$grid->addColumnLinkDetail('Detail');
		$grid->addColumnActionDelete();
		
		$grid->addButtonSaveAll(['active'], [], null, false, null, null, false);
		$grid->addButtonDeleteSelected();
		
		$grid->addFilterTextInput('id', ['id'], null, 'ID');
		$grid->addFilterTextInput('search', ['purchase.fullname', 'purchase.email', 'purchase.phone'], null, 'E-mail, telefon, jméno');
		$grid->addFilterButtons();
		
		return $grid;
	}
	
	public function createComponentNewForm(): AdminForm
	{
		/** @var \Eshop\DB\Autoship|null $autoship */
		$autoship = $this->getParameter('autoship');
		
		$form = $this->formFactory->create();
		$form->addInteger('dayInterval', 'Interval (dnů)')->setRequired();
		$form->addDate('activeFrom', 'Aktivní od');
		$form->addDate('activeTo', 'Aktivní do');
		$form->addCheckbox('active', 'Aktivní');
		
		$form->addGroup('Zákazník');
		$purchase = $form->addContainer('purchase');
		$form->addSelect2('customer', 'Zákazník', $this->customerRepository->getArrayForSelect());
		$purchase->addText('fullname', 'Jméno / firma')->setNullable();
		$purchase->addText('phone', 'Telefon')->setNullable();
		$purchase->addText('email', 'E-mail')->setNullable();
		$purchase->addText('ic', 'IČ')->setNullable();
		$purchase->addText('dic', 'DIČ')->setNullable();
		
		$form->addGroup('Fakturační adresa');
		$billAddress = $form->addContainer('billAddress');
		$billAddress->addHidden('uuid')->setNullable();
		$billAddress->addText('street', 'Ulice');
		$billAddress->addText('city', 'Město');
		$billAddress->addText('zipcode', 'PSČ');
		
		
		$form->addGroup('Doručovací adresa');
		$otherAddress = $form->addCheckbox('otherAddress', 'Doručovací adresa je jiná než fakturační')->setDefaultValue($autoship && $autoship->purchase->deliveryAddress);
		$deliveryAddress = $form->addContainer('deliveryAddress');
		$deliveryAddress->addHidden('uuid')->setNullable();
		$deliveryAddress->addText('name', ' Jméno a příjmení / název firmy');
		$deliveryAddress->addText('companyName', ' Název firmy');
		$deliveryAddress->addText('street', 'Ulice');
		$deliveryAddress->addText('city', 'Město');
		$deliveryAddress->addText('zipcode', 'PSČ');
		
		/** @var \Nette\Forms\Controls\BaseControl $input */
		foreach ($deliveryAddress->getComponents() as $input) {
			$otherAddress->addCondition($form::EQUAL, true)->toggle($input->getHtmlId() . '-toogle');
		}
		
		$form->addSubmits(!$autoship);
		
		$form->onSuccess[] = function (AdminForm $form): void {
			$values = $form->getValues('array');
			
			if (!$values['uuid']) {
				$values['uuid'] = DIConnection::generateUuid();
			}
			
			$values['purchase']['billAddress'] = Arrays::pick($values, 'billAddress');
			$values['purchase']['deliveryAddress'] = Arrays::pick($values, 'deliveryAddress');
			
			if ($this->getParameter('autoship')) {
				/** @var \Eshop\DB\Autoship $autoship */
				$autoship = $this->getParameter('autoship');
				
				if (!$values['otherAddress']) {
					if ($autoship->purchase->deliveryAddress) {
						$autoship->purchase->deliveryAddress->delete();
					}
					
					$values['purchase']['deliveryAddress'] = null;
				} elseif (!$autoship->purchase->deliveryAddress && \is_array($values['purchase']['deliveryAddress'])) {
					$address = $this->addressRepository->createOne($values['purchase']['deliveryAddress']);
					$autoship->purchase->update(['deliveryAddress' => $address]);
				}
			}
			
			$autoship = $this->autoshipRepository->syncOne($values, null, true);
			
			$this->flashMessage('Uloženo', 'success');
			$form->processRedirect('detail', 'default', [$autoship]);
		};
		
		return $form;
	}
	
	public function renderDefault(): void
	{
		$this->template->headerLabel = 'Autoshipy';
		$this->template->headerTree = [
			['Autoshipy'],
		];
		//$this->template->displayButtons = [$this->createNewItemButton('new')];
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
	
	public function actionDetail(Autoship $autoship): void
	{
		/** @var \Forms\Form $form */
		$form = $this->getComponent('newForm');
		
		
		$form->setDefaults($autoship->toArray(['purchase']) + $autoship->purchase->toArray(['deliveryAddress', 'billAddress']));
	}
}
