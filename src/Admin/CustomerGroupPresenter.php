<?php

declare(strict_types=1);

namespace Eshop\Admin;

use Admin\BackendPresenter;
use App\Admin\Controls\AdminForm;
use App\Admin\PresenterTrait;
use Eshop\DB\PricelistRepository;
use Eshop\Shopper;
use Eshop\DB\CustomerGroup;
use Eshop\DB\CustomerGroupRepository;
use Eshop\DB\CustomerRepository;
use Forms\Form;

class CustomerGroupPresenter extends BackendPresenter
{
	/** @inject */
	public CustomerRepository $customerRepo;

	/** @inject */
	public CustomerGroupRepository $userGroupRepo;

	/** @inject */
	public PricelistRepository $pricelistRepo;

	public function createComponentGrid()
	{
		$grid = $this->gridFactory->create($this->userGroupRepo->many(), 20, 'name', 'ASC', true);
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
		
		$grid->addColumn('Výchozí pro registraci', function (CustomerGroup $group) {
			return $group->defaultAfterRegistration ? 'Ano' : 'Ne';
		}, '%s', null, ['class' => 'fit']);

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

		$input = $form->addText('name', 'Název')->setRequired();
		
		if ($group && $group->isSystemic()) {
			$input->setHtmlAttribute('readonly', 'readonly');
		}
		
		if (!($group && $group->getPK() == CustomerGroupRepository::UNREGISTERED_PK)) {
			$form->addCheckbox('defaultAfterRegistration', 'Výchozí po registraci');
		}

		$form->addSelect('defaultCatalogPermission', 'Katalogové oprávnění', Shopper::PERMISSIONS);
		$form->addDataMultiSelect('defaultPricelists', 'Ceníky', $this->pricelistRepo->getArrayForSelect())
			->setHtmlAttribute('placeholder', 'Vyberte položky...');

		$form->addSubmits(!$group);

		$form->onSuccess[] = function (AdminForm $form) use ($group) {
			$values= $form->getValues('array');
			
			if ($values['defaultAfterRegistration'] ?? null) {
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
		$this->template->headerLabel = 'Skupiny uživatelů';
		$this->template->headerTree = [
			['Skupiny uživatelů'],
		];
		$this->template->displayButtons = [$this->createNewItemButton('new')];
		$this->template->displayControls = [$this->getComponent('grid')];
	}

	public function renderNew()
	{
		$this->template->headerLabel = 'Nový';
		$this->template->headerTree = [
			['Skupiny uživatelů', 'default'],
			['Nový'],
		];
		$this->template->displayButtons = [$this->createBackButton('default')];
		$this->template->displayControls = [$this->getComponent('newForm')];
	}

	public function renderDetail()
	{
		$this->template->headerLabel = 'Detail';
		$this->template->headerTree = [
			['Skupiny uživatelů', 'default'],
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
