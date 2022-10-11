<?php

declare(strict_types=1);

namespace Eshop\Admin;

use Admin\BackendPresenter;
use Admin\Controls\AdminForm;
use Admin\Controls\AdminGrid;
use Eshop\DB\CustomerRepository;
use Eshop\DB\CustomerRole;
use Eshop\DB\CustomerRoleRepository;
use Forms\Form;

class CustomerRolePresenter extends BackendPresenter
{
	/** @inject */
	public CustomerRepository $customerRepo;

	/** @inject */
	public CustomerRoleRepository $customerRoleRepo;


	public function createComponentGrid(): AdminGrid
	{
		$collection = $this->customerRoleRepo->many();

		$grid = $this->gridFactory->create($collection, 20, 'priority', 'ASC', true);
		$grid->addColumnSelector();

		$grid->addColumnText('Název', 'name', '%s', 'name');

		$grid->addColumnInputInteger('Priorita', 'priority', '', '', 'priority', [], true);


		$grid->addColumnLinkDetail('Detail');
		$grid->addColumnActionDeleteSystemic();


		$grid->addButtonSaveAll();
		$grid->addButtonDeleteSelected(null, false, function (CustomerRole $customerRole) {
			return !$customerRole->isSystemic();
		});

		$grid->addFilterTextInput('search', ['name'], null, 'Název');
		$grid->addFilterButtons();

		/*$grid->onRenderRow[] = function (\Nette\Utils\Html $row, $object): void {
			 @var \Eshop\DB\CustomerRole $object
			if ($object->getPK() === CustomerGroupRepository::UNREGISTERED_PK) {
				$row->appendAttribute('style', 'background-color: lavender;');
			}
		};*/

		return $grid;
	}

	public function createComponentNewForm(): Form
	{
		$form = $this->formFactory->create();

		/** @var \Eshop\DB\CustomerRole|null $role */
		$role = $this->getParameter('role');

		$form->addText('name', 'Název')->setRequired();

		$form->addSubmits(!$role);

		$form->onSuccess[] = function (AdminForm $form) use ($role): void {
			$values = $form->getValues('array');

			$role = $this->customerRoleRepo->syncOne($values, null, true);

			$this->flashMessage('Uloženo', 'success');
			$form->processRedirect('detail', 'default', [$role]);
		};

		return $form;
	}

	public function renderDefault(): void
	{
		$this->template->headerLabel = 'Role zákazníků';
		$this->template->headerTree = [
			['Role zákazníků'],
		];
		$this->template->displayButtons = [$this->createNewItemButton('new')];
		$this->template->displayControls = [$this->getComponent('grid')];
	}

	public function renderNew(): void
	{
		$this->template->headerLabel = 'Nový';
		$this->template->headerTree = [
			['Role zákazníků', 'default'],
			['Nový'],
		];
		$this->template->displayButtons = [$this->createBackButton('default')];
		$this->template->displayControls = [$this->getComponent('newForm')];
	}

	public function renderDetail(): void
	{
		$this->template->headerLabel = 'Detail';
		$this->template->headerTree = [
			['Role zákazníků', 'default'],
			['Detail'],
		];
		$this->template->displayButtons = [$this->createBackButton('default')];
		$this->template->displayControls = [$this->getComponent('newForm')];
	}

	public function actionDetail(CustomerRole $role): void
	{
		/** @var \Forms\Form $form */
		$form = $this->getComponent('newForm');
		$values = $role->toArray();
		$form->setDefaults($values);
	}
}
