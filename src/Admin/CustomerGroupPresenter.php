<?php

declare(strict_types=1);

namespace Eshop\Admin;

use Admin\BackendPresenter;
use Admin\Controls\AdminForm;
use Eshop\DB\PricelistRepository;
use Eshop\Shopper;
use Eshop\DB\CustomerGroup;
use Eshop\DB\CustomerGroupRepository;
use Eshop\DB\CustomerRepository;
use Forms\Form;

class CustomerGroupPresenter extends BackendPresenter
{
	protected const CONFIGURATION = [
		'unregistred' => true,
		'defaultAfterRegistration' => true
	];

	/** @inject */
	public CustomerRepository $customerRepo;

	/** @inject */
	public CustomerGroupRepository $userGroupRepo;

	/** @inject */
	public PricelistRepository $pricelistRepo;

	public function createComponentGrid()
	{
		if (static::CONFIGURATION['unregistred']) {
			$collection = $this->userGroupRepo->many();
		} else {
			$collection = $this->userGroupRepo->many()->where('uuid != :s', ['s' => CustomerGroupRepository::UNREGISTERED_PK]);
		}

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

		if (static::CONFIGURATION['defaultAfterRegistration']) {
			$grid->addColumn('Výchozí po registraci', function (CustomerGroup $group) {
				return $group->defaultAfterRegistration ? 'Ano' : 'Ne';
			}, '%s', null, ['class' => 'fit']);
		}

		$grid->addColumnLinkDetail('Detail');
		$grid->addColumnActionDeleteSystemic();

		$grid->addButtonDeleteSelected(null, false, function (CustomerGroup $customerGroup) {
			return !$customerGroup->isSystemic();
		});

		$grid->addFilterTextInput('search', ['name'], null, 'Název');
		$grid->addFilterButtons();

		$grid->onRenderRow[] = function (\Nette\Utils\Html $row, CustomerGroup $object) {
			if ($object->getPK() === CustomerGroupRepository::UNREGISTERED_PK) {
				$row->appendAttribute('style', 'background-color: lavender;');
			}
		};

		return $grid;
	}

	public function createComponentNewForm(): Form
	{
		$form = $this->formFactory->create();

		/** @var CustomerGroup $group */
		$group = $this->getParameter('group');

		$form->addText('name', 'Název')->setRequired();

		$form->addSelect('defaultCatalogPermission', 'Katalogové oprávnění', Shopper::PERMISSIONS);
		$form->addCheckbox('defaultBuyAllowed', 'Povolený nákup');
		$form->addCheckbox('defaultViewAllOrders', 'Účet vidí všechny objednávky zákazníka');
		$form->addDataMultiSelect('defaultPricelists', 'Ceníky', $this->pricelistRepo->getArrayForSelect())
			->setHtmlAttribute('placeholder', 'Vyberte položky...');

		if (static::CONFIGURATION['defaultAfterRegistration']) {
			$form->addCheckbox('defaultAfterRegistration', 'Výchozí po registraci');
		}

		$form->addCheckbox('autoActiveCustomers', 'Zákazníci budou automaticky aktivní po registraci');

		$form->addSubmits(!$group);

		$form->onSuccess[] = function (AdminForm $form) use ($group) {
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

	public function renderDefault()
	{
		$this->template->headerLabel = 'Skupiny zákazníků';
		$this->template->headerTree = [
			['Skupiny zákazníků'],
		];
		$this->template->displayButtons = [$this->createNewItemButton('new')];
		$this->template->displayControls = [$this->getComponent('grid')];
	}

	public function renderNew()
	{
		$this->template->headerLabel = 'Nový';
		$this->template->headerTree = [
			['Skupiny zákazníků', 'default'],
			['Nový'],
		];
		$this->template->displayButtons = [$this->createBackButton('default')];
		$this->template->displayControls = [$this->getComponent('newForm')];
	}

	public function renderDetail()
	{
		$this->template->headerLabel = 'Detail';
		$this->template->headerTree = [
			['Skupiny zákazníků', 'default'],
			['Detail'],
		];
		$this->template->displayButtons = [$this->createBackButton('default')];
		$this->template->displayControls = [$this->getComponent('newForm')];
	}

	public function actionDetail(CustomerGroup $group)
	{
		/** @var Form $form */
		$form = $this->getComponent('newForm');
		$values = $group->toArray();
		$values['defaultPricelists'] = \array_keys($group->defaultPricelists->toArray());
		$form->setDefaults($values);
	}
}
