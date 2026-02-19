<?php

declare(strict_types=1);

namespace Eshop\Admin;

use Admin\BackendPresenter;
use Admin\Controls\AdminForm;
use Admin\Controls\AdminGrid;
use Eshop\DB\CurrencyRepository;
use Eshop\DB\CustomerGroupRepository;
use Eshop\DB\CustomerRepository;
use Eshop\DB\MinimalOrderValue;
use Eshop\DB\MinimalOrderValueRepository;
use Nette\DI\Attributes\Inject;

class MinimalOrderValuePresenter extends BackendPresenter
{
	#[Inject]
	public MinimalOrderValueRepository $minimalOrderValueRepository;

	#[Inject]
	public CustomerGroupRepository $customerGroupRepository;

	#[Inject]
	public CustomerRepository $customerRepository;

	#[Inject]
	public CurrencyRepository $currencyRepository;

	public function __construct()
	{
		parent::__construct();
	}

	public function createComponentGrid(): AdminGrid
	{
		$source = $this->minimalOrderValueRepository->many()
			->join(['customerGroup' => 'eshop_customergroup'], 'customerGroup.uuid = this.fk_customerGroup')
			->join(['customer' => 'eshop_customer'], 'customer.uuid = this.fk_customer')
			->join(['currency' => 'eshop_currency'], 'currency.uuid = this.fk_currency')
			->select([
				'customerGroupName' => 'customerGroup.name',
				'customerName' => "IF(customer.company != '' AND customer.company IS NOT NULL, customer.company, customer.fullname)",
				'currencyCode' => 'currency.code',
			]);

		$grid = $this->gridFactory->create($source, 20, 'this.price', 'ASC', true);
		$grid->addColumnSelector();

		$grid->addColumnText('Skupina', 'customerGroupName', '%s', 'customerGroupName');
		$grid->addColumnText('Zákazník', 'customerName', '%s', 'customerName');
		$grid->addColumnText('Měna', 'currencyCode', '%s', 'currencyCode', ['class' => 'fit']);
		$grid->addColumnText('Cena', 'price', '%s', 'price', ['class' => 'fit']);

		$grid->addColumnLinkDetail('detail');
		$grid->addColumnActionDelete();

		$grid->addFilterTextInput('search', ['customerGroup.name', 'customer.company', 'customer.fullname'], null, 'Skupina, zákazník');
		$grid->addFilterButtons();

		return $grid;
	}

	public function createComponentNewForm(): AdminForm
	{
		$form = $this->formFactory->create();

		$form->addDataSelect('customerGroup', 'Skupina zákazníků', $this->customerGroupRepository->getArrayForSelect())
			->setPrompt('— Žádná —');

		$form->addDataSelect('customer', 'Zákazník', $this->customerRepository->getArrayForSelect())
			->setPrompt('— Žádný —');

		$form->addDataSelect('currency', 'Měna', $this->currencyRepository->getArrayForSelect())
			->setRequired();

		$form->addText('price', 'Minimální cena bez DPH')
			->setRequired()
			->addRule($form::Float);

		$form->addSubmits(!$this->getParameter('minimalOrderValue'));

		$form->onValidate[] = function (AdminForm $form): void {
			$values = $form->getValues('array');

			if ($values['customerGroup'] || $values['customer']) {
				return;
			}

			$form->addError('Musíte vyplnit skupinu zákazníků nebo zákazníka.');
		};

		$form->onSuccess[] = function (AdminForm $form): void {
			$values = $form->getValues('array');

			$minimalOrderValue = $this->minimalOrderValueRepository->syncOne($values, null, true);

			$this->flashMessage('Uloženo', 'success');
			$form->processRedirect('detail', 'default', [$minimalOrderValue]);
		};

		return $form;
	}

	public function renderDefault(): void
	{
		$this->template->headerLabel = 'Minimální hodnoty objednávek';
		$this->template->headerTree = [
			['Minimální hodnoty objednávek', 'default'],
		];
		$this->template->displayButtons = [$this->createNewItemButton('new')];
		$this->template->displayControls = [$this->getComponent('grid')];
	}

	public function renderNew(): void
	{
		$this->template->headerLabel = 'Nová položka';
		$this->template->headerTree = [
			['Minimální hodnoty objednávek', 'default'],
			['Nová položka'],
		];
		$this->template->displayButtons = [$this->createBackButton('default')];
		$this->template->displayControls = [$this->getComponent('newForm')];
	}

	public function renderDetail(): void
	{
		$this->template->headerLabel = 'Detail';
		$this->template->headerTree = [
			['Minimální hodnoty objednávek', 'default'],
			['Detail'],
		];
		$this->template->displayButtons = [$this->createBackButton('default')];
		$this->template->displayControls = [$this->getComponent('newForm')];
	}

	public function actionDetail(MinimalOrderValue $minimalOrderValue): void
	{
		/** @var \Admin\Controls\AdminForm $form */
		$form = $this->getComponent('newForm');

		$form->setDefaults($minimalOrderValue->toArray());
	}
}
