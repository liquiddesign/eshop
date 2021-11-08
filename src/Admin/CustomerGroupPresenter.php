<?php

declare(strict_types=1);

namespace Eshop\Admin;

use Admin\BackendPresenter;
use Admin\Controls\AdminForm;
use Eshop\DB\CustomerGroup;
use Eshop\DB\CustomerGroupRepository;
use Eshop\DB\CustomerRepository;
use Eshop\DB\PricelistRepository;
use Eshop\Shopper;
use Forms\Form;

class CustomerGroupPresenter extends BackendPresenter
{
	protected const CONFIGURATION = [
		'unregistred' => true,
		'defaultAfterRegistration' => true,
		'prices' => true,
	];

	/** @inject */
	public CustomerRepository $customerRepo;

	/** @inject */
	public CustomerGroupRepository $userGroupRepo;

	/** @inject */
	public PricelistRepository $pricelistRepo;

	/** @inject */
	public Shopper $shopper;

	public function createComponentGrid()
	{
		$collection = self::CONFIGURATION['unregistred'] ? $this->userGroupRepo->many() : $this->userGroupRepo->many()->where('uuid != :s', ['s' => CustomerGroupRepository::UNREGISTERED_PK]);

		$grid = $this->gridFactory->create($collection, 20, 'name', 'ASC', true);
		$grid->addColumnSelector();

		$grid->addColumnText('Název', 'name', '%s', 'name');

		$grid->addColumn('Ceníky', function (CustomerGroup $group) {
			$resultString = '';

			foreach ($group->defaultPricelists as $pricelist) {
				$link = ':Eshop:Admin:Pricelists:priceListDetail';

				if (!$this->admin->isAllowed($link)) {
					$resultString .= $pricelist->name . ', ';
				} else {
					$resultString .= '<a href=' . $this->link($link, [$pricelist, 'backlink' => $this->storeRequest()]) . '>' . $pricelist->name . '</a>, ';
				}
			}

			return \substr($resultString, 0, -2);
		});

		$grid->addColumn('Katalogové oprávnění', function (CustomerGroup $group) {
			return Shopper::PERMISSIONS[$group->defaultCatalogPermission];
		}, '%s', null, ['class' => 'fit']);

		$grid->addColumn('Povolený nákup', function (CustomerGroup $group) {
			return $group->defaultBuyAllowed ? 'Ano' : 'Ne';
		}, '%s', null, ['class' => 'fit']);

		if (self::CONFIGURATION['defaultAfterRegistration']) {
			$grid->addColumn('Výchozí po registraci', function (CustomerGroup $group) {
				return $group->defaultAfterRegistration ? 'Ano' : 'Ne';
			}, '%s', null, ['class' => 'fit']);
		}

//		if (isset(static::CONFIGURATION['prices']) && static::CONFIGURATION['prices']) {
//			if ($this->shopper->getShowWithoutVat()) {
//				$grid->addColumnInputCheckbox('Zobrazit cenu bez daně', 'defaultPricesWithoutVat', '', '', 'defaultPricesWithoutVat');
//			}
//
//			if ($this->shopper->getShowVat()) {
//				$grid->addColumnInputCheckbox('Zobrazit cenu s daní', 'defaultPricesWithVat', '', '', 'defaultPricesWithVat');
//			}
//		}

		$grid->addColumnLinkDetail('Detail');
		$grid->addColumnActionDeleteSystemic();


		$grid->addButtonSaveAll();
		$grid->addButtonDeleteSelected(null, false, function (CustomerGroup $customerGroup) {
			return !$customerGroup->isSystemic();
		});

		$grid->addFilterTextInput('search', ['name'], null, 'Název');
		$grid->addFilterButtons();

		$grid->onRenderRow[] = function (\Nette\Utils\Html $row, CustomerGroup $object): void {
			if ($object->getPK() === CustomerGroupRepository::UNREGISTERED_PK) {
				$row->appendAttribute('style', 'background-color: lavender;');
			}
		};

		return $grid;
	}

	public function createComponentNewForm(): Form
	{
		$form = $this->formFactory->create();

		/** @var \Eshop\DB\CustomerGroup $group */
		$group = $this->getParameter('group');

		$form->addText('name', 'Název')->setRequired();

		$form->addSelect('defaultCatalogPermission', 'Katalogové oprávnění', Shopper::PERMISSIONS)->addCondition($form::EQUAL, 'price')
			->toggle('frm-newForm-defaultPricesWithoutVat-toogle')
			->toggle('frm-newForm-defaultPricesWithVat-toogle');

		if (isset(self::CONFIGURATION['prices']) && self::CONFIGURATION['prices']) {
			if ($this->shopper->getShowWithoutVat()) {
				$form->addCheckbox('defaultPricesWithoutVat', 'Zobrazit ceny bez daně');
			}

			if ($this->shopper->getShowVat()) {
				$form->addCheckbox('defaultPricesWithVat', 'Zobrazit ceny s daní');
			}

			if ($this->shopper->getShowWithoutVat() && $this->shopper->getShowVat()) {
				$form->addSelect('defaultPriorityPrice', 'Prioritní cena', [
					'withoutVat' => 'Bez daně',
					'withVat' => 'S daní',
				])->addConditionOn($form['defaultCatalogPermission'], $form::EQUAL, 'price')
					->addConditionOn($form['defaultPricesWithoutVat'], $form::EQUAL, true)
					->addConditionOn($form['defaultPricesWithVat'], $form::EQUAL, true)
					->toggle('frm-newForm-defaultPriorityPrice-toogle');
			}
		}

		$form->addCheckbox('defaultBuyAllowed', 'Povolený nákup');
		$form->addCheckbox('defaultViewAllOrders', 'Účet vidí všechny objednávky zákazníka');
		$form->addDataMultiSelect('defaultPricelists', 'Ceníky', $this->pricelistRepo->getArrayForSelect())
			->setHtmlAttribute('placeholder', 'Vyberte položky...');

		if (self::CONFIGURATION['defaultAfterRegistration']) {
			$form->addCheckbox('defaultAfterRegistration', 'Výchozí po registraci');
		}

		$form->addCheckbox('autoActiveCustomers', 'Zákazníci budou automaticky aktivní po registraci');

		$form->addSubmits(!$group);

		$form->onSuccess[] = function (AdminForm $form) use ($group): void {
			$values = $form->getValues('array');

			if (isset($values['defaultAfterRegistration']) && $values['defaultAfterRegistration']) {
				$this->userGroupRepo->many()->update(['defaultAfterRegistration' => false]);
			}

			$group = $this->userGroupRepo->syncOne($values, null, true);

			$this->flashMessage('Uloženo', 'success');
			$form->processRedirect('detail', 'default', [$group]);
		};

		return $form;
	}

	public function renderDefault(): void
	{
		$this->template->headerLabel = 'Skupiny zákazníků';
		$this->template->headerTree = [
			['Skupiny zákazníků'],
		];
		$this->template->displayButtons = [$this->createNewItemButton('new')];
		$this->template->displayControls = [$this->getComponent('grid')];
	}

	public function renderNew(): void
	{
		$this->template->headerLabel = 'Nový';
		$this->template->headerTree = [
			['Skupiny zákazníků', 'default'],
			['Nový'],
		];
		$this->template->displayButtons = [$this->createBackButton('default')];
		$this->template->displayControls = [$this->getComponent('newForm')];
	}

	public function renderDetail(): void
	{
		$this->template->headerLabel = 'Detail';
		$this->template->headerTree = [
			['Skupiny zákazníků', 'default'],
			['Detail'],
		];
		$this->template->displayButtons = [$this->createBackButton('default')];
		$this->template->displayControls = [$this->getComponent('newForm')];
	}

	public function actionDetail(CustomerGroup $group): void
	{
		/** @var \Forms\Form $form */
		$form = $this->getComponent('newForm');
		$values = $group->toArray();
		$values['defaultPricelists'] = \array_keys($group->defaultPricelists->toArray());
		$form->setDefaults($values);
	}
}
