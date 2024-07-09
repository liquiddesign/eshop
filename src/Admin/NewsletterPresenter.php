<?php
declare(strict_types=1);

namespace Eshop\Admin;

use Admin\Controls\AdminForm;
use Admin\Controls\AdminGrid;
use Eshop\BackendPresenter;
use Eshop\DB\NewsletterUser;
use Eshop\DB\NewsletterUserGroup;
use Eshop\DB\NewsletterUserGroupRepository;
use Eshop\DB\NewsletterUserRepository;
use StORM\Collection;
use StORM\DIConnection;

class NewsletterPresenter extends BackendPresenter
{
	/** @persistent */
	public ?string $tab = null;

	/**
	 * @var array<string>
	 */
	public array $tabs = [];

	#[\Nette\DI\Attributes\Inject]
	public NewsletterUserGroupRepository $newsletterUserGroupRepository;

	#[\Nette\DI\Attributes\Inject]
	public NewsletterUserRepository $newsletterUserRepository;

	public function createComponentUserGrid(): AdminGrid
	{
		$grid = $this->gridFactory->create($this->newsletterUserRepository->many()
			->join(['nxn' => 'eshop_newsletteruser_nxn_eshop_newsletterusergroup'], 'this.uuid = nxn.fk_newsletteruser'), 20, 'this.email', 'ASC', true);
		$grid->addColumnSelector();

		$grid->addColumnText('Vytvořeno', 'createdTs|date', '%s', 'createdTs', ['class' => 'fit']);
		$grid->addColumnText('E-mail', 'email', '%s', 'email');
		$grid->addColumn('Zákazník', function (NewsletterUser $newsletterUser, AdminGrid $datagrid): ?string {
			if (!$newsletterUser->customerAccount) {
				return null;
			}

			$link = $this->admin->isAllowed(':Eshop:Admin:Customer:editAccount') ?
				$datagrid->getPresenter()->link(':Eshop:Admin:Customer:editAccount', [$newsletterUser->customerAccount, 'backLink' => $this->storeRequest()]) : '#';

			return '<a href="' . $link . "\"><i class='fa fa-external-link-alt fa-sm'></i>&nbsp;" . $newsletterUser->customerAccount->login . '</a>';
		});
		$grid->addColumn('Skupiny', function (NewsletterUser $newsletterUser): string {
			return \implode(', ', $newsletterUser->groups->toArrayOf('name'));
		});

		$grid->addColumnLinkDetail('userDetail');
		$grid->addColumnActionDelete();
		$grid->addButtonDeleteSelected();

		$grid->addFilterTextInput('search', ['this.email'], null, 'E-mail');
		$this->gridFactory->addShopsFilterSelect($grid);

		$grid->addFilterDataSelect(function (Collection $source, $value): void {
			$source->where('nxn.fk_newsletterusergroup', $value);
		}, '', 'group', null, $this->newsletterUserGroupRepository->getArrayForSelect())->setPrompt('- Skupina -');

//		$grid->addFilterDataSelect('group', 'nxn.fk_newsletterusergroup = :t', null, '- Typ -', null, $this->newsletterUserGroupRepository->getArrayForSelect(), 't');
		$grid->addFilterButtons();

		return $grid;
	}

	public function createComponentGroupGrid(): AdminGrid
	{
		$grid = $this->gridFactory->create($this->newsletterUserGroupRepository->many(), 20, 'this.email', 'ASC', true);
		$grid->addColumnSelector();

		$grid->addColumnText('Název', 'name', '%s', 'name');

		$grid->addColumnLinkDetail('groupDetail');
		$grid->addColumnActionDelete();
		$grid->addButtonDeleteSelected();

		$grid->addFilterTextInput('search', ['this.name'], null, 'Název');
		$grid->addFilterButtons();

		return $grid;
	}

	public function createComponentUserForm(): AdminForm
	{
		/** @var \Eshop\DB\NewsletterUser|null $newsletterUser */
		$newsletterUser = $this->getParameter('newsletterUser');

		$form = $this->formFactory->create();
		$form->addText('email', 'E-mail')->addRule($form::EMAIL)->setRequired();
		$form->addMultiSelect2('groups', 'Skupiny', $this->newsletterUserGroupRepository->getArrayForSelect());
		$form->addText('customerAccount', 'Zákaznický účet')->setDisabled();

		$form->addSubmits(!$newsletterUser);

		$form->onSuccess[] = function (AdminForm $form): void {
			$values = $form->getValues('array');

			if (!$values['uuid']) {
				$values['uuid'] = DIConnection::generateUuid();
			}

			$object = $this->newsletterUserRepository->syncOne($values, null, true);

			$this->flashMessage('Uloženo', 'success');
			$form->processRedirect('userDetail', 'default', [$object]);
		};

		return $form;
	}

	public function createComponentGroupForm(): AdminForm
	{
		/** @var \Eshop\DB\NewsletterUserGroup|null $newsletterUserGroup */
		$newsletterUserGroup = $this->getParameter('newsletterUserGroup');

		$form = $this->formFactory->create();
		$form->addText('name', 'Název');

		$form->addSubmits(!$newsletterUserGroup);

		$form->onSuccess[] = function (AdminForm $form): void {
			$values = $form->getValues('array');

			if (!$values['uuid']) {
				$values['uuid'] = DIConnection::generateUuid();
			}

			$object = $this->newsletterUserGroupRepository->syncOne($values, null, true);

			$this->flashMessage('Uloženo', 'success');
			$form->processRedirect('groupDetail', 'default', [$object]);
		};

		return $form;
	}

	public function renderDefault(): void
	{
		$this->template->headerLabel = 'Newsletter';

		$this->template->headerTree = [
			['Newsletter'],
		];

		$this->template->tabs = $this->tabs;

		$this->template->displayButtons = [$this->tab === 'groups' ? $this->createNewItemButton('groupNew') : $this->createNewItemButton('userNew')];
		$this->template->displayControls = [$this->tab === 'groups' ? $this->getComponent('groupGrid') : $this->getComponent('userGrid')];
	}

	public function renderUserNew(): void
	{
		$this->template->headerLabel = 'Nová položka';
		$this->template->headerTree = [
			['Newsletter', 'default'],
			['Nová položka'],
		];

		$this->template->displayButtons = [$this->createBackButton('default')];
		$this->template->displayControls = [$this->getComponent('userForm')];
	}

	public function actionUserDetail(NewsletterUser $newsletterUser): void
	{
		/** @var \Forms\Form $form */
		$form = $this->getComponent('userForm');

		$values = $newsletterUser->toArray(['groups']);

		if ($values['customerAccount']) {
			$values['customerAccount'] = $newsletterUser->customerAccount->login;
		}

		$form->setDefaults($values);
	}

	public function renderUserDetail(NewsletterUser $newsletterUser): void
	{
		unset($newsletterUser);

		$this->template->headerLabel = 'Detail';
		$this->template->headerTree = [
			['Newsletter', 'default'],
			['Detail'],
		];
		$this->template->displayButtons = [$this->createBackButton('default')];
		$this->template->displayControls = [$this->getComponent('userForm')];
	}

	public function renderGroupNew(): void
	{
		$this->template->headerLabel = 'Nová položka';
		$this->template->headerTree = [
			['Newsletter', 'default'],
			['Nová položka'],
		];

		$this->template->displayButtons = [$this->createBackButton('default')];
		$this->template->displayControls = [$this->getComponent('groupForm')];
	}

	public function actionGroupDetail(NewsletterUserGroup $newsletterUserGroup): void
	{
		/** @var \Forms\Form $form */
		$form = $this->getComponent('groupForm');

		$form->setDefaults($newsletterUserGroup->toArray());
	}

	public function renderGroupDetail(): void
	{
		$this->template->headerLabel = 'Detail';
		$this->template->headerTree = [
			['Newsletter', 'default'],
			['Detail'],
		];
		$this->template->displayButtons = [$this->createBackButton('default')];
		$this->template->displayControls = [$this->getComponent('groupForm')];
	}

	protected function startup(): void
	{
		parent::startup();

		$this->tabs['users'] = 'Uživatelé';
		$this->tabs['groups'] = 'Skupiny';

		if ($this->tab) {
			return;
		}

		$this->tab = \key($this->tabs);
	}
}
