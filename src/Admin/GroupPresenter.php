<?php

declare(strict_types=1);

namespace Eshop\Admin;

use Admin\BackendPresenter;
use Admin\Controls\AdminForm;
use Admin\Controls\AdminGrid;
use Eshop\DB\CustomerGroup;
use Eshop\DB\CustomerGroupRepository;
use Eshop\DB\CustomerRepository;
use Eshop\DB\PricelistRepository;
use Eshop\DB\VisibilityListRepository;
use Eshop\ShopperUser;
use Forms\Form;
use Nette\DI\Attributes\Inject;
use Nette\Utils\Strings;

class GroupPresenter extends BackendPresenter
{
	protected const CONFIGURATION = [
		'unregistred' => true,
		'defaultAfterRegistration' => true,
		'prices' => true,
	];
	
	#[\Nette\DI\Attributes\Inject]
	public CustomerRepository $customerRepo;
	
	#[\Nette\DI\Attributes\Inject]
	public CustomerGroupRepository $userGroupRepo;
	
	#[\Nette\DI\Attributes\Inject]
	public PricelistRepository $pricelistRepo;
	
	#[\Nette\DI\Attributes\Inject]
	public ShopperUser $shopperUser;

	#[Inject]
	public VisibilityListRepository $visibilityListRepository;
	
	public function createComponentGrid(): AdminGrid
	{
		$collection = $this::CONFIGURATION['unregistred'] ? $this->userGroupRepo->many() : $this->userGroupRepo->many()->where('uuid != :s', ['s' => CustomerGroupRepository::UNREGISTERED_PK]);
		
		$grid = $this->gridFactory->create($collection, 20, 'name', 'ASC', true, filterShops: false);
		$grid->addColumnSelector();
		
		$grid->addColumnText('Název', 'name', '%s', 'name');
		
		$grid->addColumn('Ceníky / Viditelníky', function (CustomerGroup $group) {
			$pricelistsResultString = '';
			
			foreach ($group->getDefaultPricelists()->orderBy(['priority' => 'ASC', 'uuid' => 'ASC'])->toArray() as $pricelist) {
				$link = ':Eshop:Admin:Pricelists:priceListDetail';
				
				if (!$this->admin->isAllowed($link)) {
					$pricelistsResultString .= $pricelist->name . ', ';
				} else {
					$pricelistsResultString .= '<a href=' . $this->link($link, [$pricelist, 'backlink' => $this->storeRequest()]) . '>' . $pricelist->name . '</a>, ';
				}
			}

			$visibilityListsResultString = '';

			foreach ($group->getDefaultVisibilityLists()->orderBy(['priority' => 'ASC', 'uuid' => 'ASC'])->toArray() as $visibilityList) {
				$link = ':Eshop:Admin:VisibilityList:listDetail';

				if (!$this->admin->isAllowed($link)) {
					$visibilityListsResultString .= $visibilityList->name . ', ';
				} else {
					$visibilityListsResultString .= '<a href=' . $this->link($link, [$visibilityList, 'backlink' => $this->storeRequest()]) . '>' . $visibilityList->name . '</a>, ';
				}
			}
			
			return [Strings::substring($pricelistsResultString, 0, -2), Strings::substring($visibilityListsResultString, 0, -2)];
		}, '%s<hr style="margin: 0">%s');
		
		$grid->addColumn('Katalogové oprávnění', function (CustomerGroup $group) {
			return ShopperUser::PERMISSIONS[$group->defaultCatalogPermission];
		}, '%s', null, ['class' => 'fit']);
		
		$grid->addColumn('Povolený nákup', function (CustomerGroup $group) {
			return $group->defaultBuyAllowed ? 'Ano' : 'Ne';
		}, '%s', null, ['class' => 'fit']);
		
		if ($this::CONFIGURATION['defaultAfterRegistration']) {
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
		$this->gridFactory->addShopsFilterSelect($grid);

		$grid->addFilterButtons();
		
		$grid->onRenderRow[] = function (\Nette\Utils\Html $row, $object): void {
			/** @var \Eshop\DB\CustomerGroup $object */
			if ($object->getPK() === CustomerGroupRepository::UNREGISTERED_PK) {
				$row->appendAttribute('style', 'background-color: lavender;');
			}
		};
		
		return $grid;
	}
	
	public function createComponentNewForm(): Form
	{
		$form = $this->formFactory->create();
		
		/** @var \Eshop\DB\CustomerGroup|null $group */
		$group = $this->getParameter('group');
		
		$form->addText('name', 'Název')->setRequired();
		
		$catalogPermInput = $form->addSelect('defaultCatalogPermission', 'Katalogové oprávnění', ShopperUser::PERMISSIONS);
		
		$catalogPermInput->addCondition($form::EQUAL, 'price')
			->toggle('frm-newForm-defaultPricesWithoutVat-toogle')
			->toggle('frm-newForm-defaultPricesWithVat-toogle');
		
		if (isset($this::CONFIGURATION['prices']) && $this::CONFIGURATION['prices']) {
			if ($this->shopperUser->getShowWithoutVat()) {
				$withoutVatInput = $form->addCheckbox('defaultPricesWithoutVat', 'Zobrazit ceny bez daně');
			}
			
			if ($this->shopperUser->getShowVat()) {
				$withVatInput = $form->addCheckbox('defaultPricesWithVat', 'Zobrazit ceny s daní');
			}
			
			if ($this->shopperUser->getShowWithoutVat() && $this->shopperUser->getShowVat()) {
				$form->addSelect('defaultPriorityPrice', 'Prioritní cena', [
					'withoutVat' => 'Bez daně',
					'withVat' => 'S daní',
				])->addConditionOn($catalogPermInput, $form::EQUAL, 'price')
					->addConditionOn($withoutVatInput, $form::EQUAL, true)
					->addConditionOn($withVatInput, $form::EQUAL, true)
					->toggle('frm-newForm-defaultPriorityPrice-toogle');
			}
		}
		
		$form->addInteger('defaultDiscountLevelPct', 'Výchozí sleva (%)')->setRequired()->setDefaultValue(0);
		$form->addInteger('defaultMaxDiscountProductPct', 'Výchozí max. sleva u prod. (%)')->setRequired()->setDefaultValue(100);
		$form->addCheckbox('defaultBuyAllowed', 'Povolený nákup');
		$form->addCheckbox('defaultViewAllOrders', 'Účet vidí všechny objednávky zákazníka');
		$form->addMultiSelect2('defaultPricelists', 'Ceníky', $this->pricelistRepo->getArrayForSelect())
			->setHtmlAttribute('placeholder', 'Vyberte položky...');
		$form->addMultiSelect2('defaultVisibilityLists', 'Seznamy viditelnosti', $this->visibilityListRepository->getArrayForSelect());
		
		if ($this::CONFIGURATION['defaultAfterRegistration']) {
			$form->addCheckbox('defaultAfterRegistration', 'Výchozí po registraci');
		}
		
		$form->addCheckbox('autoActiveCustomers', 'Zákazníci budou automaticky aktivní po registraci');

		$this->formFactory->addShopsContainerToAdminForm($form);
		$form->addSubmits(!$group);
		
		$form->onSuccess[] = function (AdminForm $form) use ($group): void {
			$values = $form->getValues('array');
			
			if (isset($values['defaultAfterRegistration']) && $values['defaultAfterRegistration']) {
				$query = $this->userGroupRepo->many();

				$this->shopsConfig->filterShopsInShopEntityCollection($query, showOnlyEntitiesWithSelectedShops: true);

				$query->update(['defaultAfterRegistration' => false]);
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
		$values['defaultVisibilityLists'] = \array_keys($group->defaultVisibilityLists->toArray());
		$form->setDefaults($values);
	}
}
