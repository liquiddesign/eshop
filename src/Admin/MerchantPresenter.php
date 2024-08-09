<?php

declare(strict_types=1);

namespace Eshop\Admin;

use Admin\Admin\Controls\AccountFormFactory;
use Admin\BackendPresenter;
use Admin\Controls\AdminForm;
use Admin\Controls\AdminGrid;
use Eshop\DB\CustomerGroupRepository;
use Eshop\DB\CustomerRepository;
use Eshop\DB\Merchant;
use Eshop\DB\MerchantRepository;
use Eshop\DB\PricelistRepository;
use Eshop\DB\VisibilityListRepository;
use Forms\Form;
use Grid\Datagrid;
use Messages\DB\TemplateRepository;
use Nette\DI\Attributes\Inject;
use Nette\Mail\Mailer;
use Nette\Security\Passwords;
use Security\DB\Account;
use Security\DB\AccountRepository;
use StORM\Collection;

class MerchantPresenter extends BackendPresenter
{
	protected const CONFIGURATIONS = [
		'customers' => true,
		'showUnregisteredGroup' => true,
	];

	#[Inject]
	public AccountFormFactory $accountFormFactory;

	#[Inject]
	public MerchantRepository $merchantRepository;

	/**
	 * @var \Security\DB\AccountRepository<\Security\DB\Account>
	 */
	#[Inject]
	public AccountRepository $accountRepository;

	#[Inject]
	public TemplateRepository $templateRepository;

	#[Inject]
	public CustomerGroupRepository $customerGroupRepository;

	/**
	 * @var \Eshop\DB\CustomerRepository<\Eshop\DB\Customer>
	 */
	#[Inject]
	public CustomerRepository $customerRepository;

	/**
	 * @var \Eshop\DB\PricelistRepository<\Eshop\DB\Pricelist>
	 */
	#[Inject]
	public PricelistRepository $pricelistRepository;

	#[Inject]
	public Mailer $mailer;
	
	#[Inject]
	public Passwords $passwords;

	#[Inject]
	public VisibilityListRepository $visibilityListRepository;

	public function createComponentGrid(): AdminGrid
	{
		$grid = $this->gridFactory->create($this->merchantRepository->many()
			->setGroupBy(['this.uuid'])
			->select([
				'pricelists_names' => "GROUP_CONCAT(DISTINCT pricelists.name SEPARATOR ', ')",
				'visibilityLists_names' => "GROUP_CONCAT(DISTINCT visibilityLists.name SEPARATOR ', ')",
		]), 20, 'this.code', 'ASC', true);
		$grid->addColumnSelector();

		$grid->addColumnText('Kód', 'code', '%s', 'this.code', ['class' => 'fit']);
		$grid->addColumnText('Jméno a příjmení', 'fullname', '%s', 'this.fullname');
		$grid->addColumnText('Ceníky / Viditelníky', ['pricelists_names', 'visibilityLists_names'], '%s<hr style="margin: 0">%s');
		$grid->addColumnText(
			'E-mail',
			'email',
			'<a href="mailto:%1$s"><i class="far fa-envelope"></i> %1$s</a>',
		)->onRenderCell[] = [
			$grid,
			'decoratorEmpty',
		];
		$grid->addColumnText('Skupina', 'customerGroup.name', '%s', 'customerGroup.name');

		$btnSecondary = 'btn btn-sm btn-outline-primary';
		$grid->addColumn('', function (Merchant $object, Datagrid $datagrid) use ($btnSecondary) {
			return $object->accounts->clear()->first() !== null ?
				"<a class='$btnSecondary' href='" . $datagrid->getPresenter()->link(
					'editAccount',
					$object,
				) . "'>Detail&nbsp;účtu</a>" :
				"<a class='$btnSecondary' href='" . $datagrid->getPresenter()->link(
					'newAccount',
					$object,
				) . "'>Vytvořit&nbsp;účet</a>";
		}, '%s', null, ['class' => 'minimal']);

		$grid->addColumn('Login', function (Merchant $object, Datagrid $grid) use ($btnSecondary) {
			/** @var \Security\DB\Account $account */
			$account = $object->accounts->clear()->first();

			$link = $object->accounts->clear()->first() ? $grid->getPresenter()->link(
				'loginMerchant!',
				[$account->login],
			) : '#';

			return "<a class='" . ($object->accounts->clear()->first() ? '' : 'disabled') . " $btnSecondary' target='_blank' href='$link'><i class='fa fa-sign-in-alt'></i></a>";
		}, '%s', null, ['class' => 'minimal']);

		$grid->addColumnLinkDetail('Detail');
		$grid->addColumnActionDelete([$this->accountFormFactory, 'deleteAccountHolder']);

		$grid->addButtonBulkEdit('form', ['visibilityLists']);
		$grid->addButtonDeleteSelected([$this->accountFormFactory, 'deleteAccountHolder']);

		$grid->addFilterTextInput('search', ['this.code', 'this.fullName', 'this.email'], null, 'Jméno, kód, e-mail');

		if ($items = $this->customerRepository->getArrayForSelect()) {
			$grid->addFilterDataSelect(function (Collection $source, $value): void {
				$source->where('customers.uuid', $value);
			}, '', 'customers', null, $items)->setPrompt('- Zákazník -');
		}

		$grid->addFilterButtons();

		return $grid;
	}

	public function createComponentForm(): Form
	{
		$form = $this->formFactory->create(false, false, false, false, false);

		$form->addGroup('Obchodník');
		$form->addText('code', 'Kód');
		$form->addText('fullname', 'Jméno a příjmení')->setRequired();
		$form->addEmail('email', 'E-mail')->setRequired();

		if (!$this->getParameter('merchant')) {
			$form->addGroup('Účet');
			$this->accountFormFactory->addContainer($form);
		}

		$form->addGroup('Další možnosti');

		$form->addDataSelect(
			'customerGroup',
			'Skupina zákazníků',
			$this->customerGroupRepository->getArrayForSelect(true, $this::CONFIGURATIONS['showUnregisteredGroup']),
		)->setPrompt('Žádná');
		$form->addDataMultiSelect('pricelists', 'Ceníky', $this->pricelistRepository->getArrayForSelect());
		$form->addMultiSelect2('visibilityLists', 'Seznamy viditelnosti', $this->visibilityListRepository->getArrayForSelect());

		if ($this::CONFIGURATIONS['customers']) {
			$form->addMultiSelect2('customers', 'Zákazníci', $this->customerRepository->getArrayForSelect());
		}

		$form->addCheckbox('customersPermission', 'Oprávnění: Správa zákazníků');
		$form->addCheckbox('ordersPermission', 'Oprávnění: Správa objednávek');
		$form->addCheckbox(
			'customerEmailNotification',
			'Posílat e-mailem informace o objednávkách přiřazených zákazníků.',
		);

		$form->addSubmits(!$this->getParameter('merchant'));

		$passwords = $this->passwords;
		
		$form->onSuccess[] = function (AdminForm $form) use ($passwords): void {
			/** @var array<mixed> $values */
			$values = $form->getValues('array');

			if (isset($form['account'])) {
				unset($values['account']);
			}

			/** @var \Eshop\DB\Merchant $merchant */
			$merchant = $this->merchantRepository->syncOne($values, null, true);

			/** @var array<mixed> $values */
			$values = $form->getValues('array');

			if (isset($form['account'])) {
				/** @var array<mixed> $valuesAccount */
				$valuesAccount = $values['account'];

				if ($valuesAccount['password']) {
					$valuesAccount['password'] = $passwords->hash($valuesAccount['password']);
				} else {
					unset($valuesAccount['password']);
				}

				if (!$valuesAccount['uuid']) {
					$account = $this->accountRepository->createOne($valuesAccount, true);
				} else {
					$account = $this->accountRepository->one($valuesAccount['uuid'], true);
					$account->update($valuesAccount);
				}

				$merchant->accounts->relate([$account->getPK()]);
			}

			$this->flashMessage('Uloženo', 'success');
			$form->processRedirect('detail', 'default', [$merchant]);
		};

		return $form;
	}

	public function handleLoginMerchant(string $login): void
	{
		if (!$identity = $this->merchantRepository->getByAccountLogin($login)) {
			throw new \Exception('Merchant not found');
		}

		$this->user->login($identity, null, [Merchant::class]);

		$this->presenter->redirect(':Web:Index:default');
	}

	public function renderDefault(): void
	{
		$this->template->headerLabel = 'Obchodníci';
		$this->template->headerTree = [
			['Obchodníci', 'default'],
		];
		$this->template->displayButtons = [$this->createNewItemButton('new')];
		$this->template->displayControls = [$this->getComponent('grid')];
	}

	public function actionNew(): void
	{
		/** @var \Admin\Controls\AdminForm|array<mixed> $form */
		$form = $this->getComponent('form');
		$form['account']['password']->setRequired();
		$form['account']['passwordCheck']->setRequired();
	}

	public function renderNew(): void
	{
		$this->template->headerLabel = 'Nová položka';
		$this->template->headerTree = [
			['Obchodníci', 'default'],
			['Nová položka'],
		];
		$this->template->displayButtons = [$this->createBackButton('default')];
		$this->template->displayControls = [$this->getComponent('form')];
	}

	public function renderDetail(Merchant $merchant): void
	{
		unset($merchant);

		$this->template->headerLabel = 'Detail';
		$this->template->headerTree = [
			['Obchodníci', 'default'],
			['Detail'],
		];
		$this->template->displayButtons = [$this->createBackButton('default')];
		$this->template->displayControls = [$this->getComponent('form')];
	}

	public function actionDetail(Merchant $merchant): void
	{
		/** @var \Forms\Form $form */
		$form = $this->getComponent('form');

		$relations = ['pricelists'];

		if ($this::CONFIGURATIONS['customers']) {
			$relations[] = 'customers';
			$relations[] = 'visibilityLists';
		}

		$form->setDefaults($merchant->toArray($relations));
	}

	public function createComponentAccountForm(): AdminForm
	{
		$merchant = $this->getParameter('merchant');

		return $this->accountFormFactory->create((bool) $merchant->accounts->first());
	}

	public function actionEditAccount(Merchant $merchant): void
	{
		/** @var \Admin\Controls\AdminForm|array<mixed> $form */
		$form = $this->getComponent('accountForm');
		$form['account']['email']->setDefaultValue($merchant->email);

		if ($account = $merchant->accounts->clear()->first()) {
			/** @var \Forms\Container $accountForm */
			$accountForm = $form['account'];
			$accountForm->setDefaults($account->toArray());
		}

		$this->accountFormFactory->onUpdateAccount[] = function (): void {
			$this->flashMessage('Účet byl upraven', 'success');
			$this->redirect('default');
		};

		$this->accountFormFactory->onDeleteAccount[] = function (): void {
			$this->flashMessage('Účet byl smazán', 'success');
			$this->redirect('default');
		};
	}

	public function renderEditAccount(Merchant $merchant): void
	{
		$this->template->headerLabel = 'Detail účtu - ' . $merchant->fullname;
		$this->template->headerTree = [
			['Obchodníci', 'default'],
			['Detail účtu obchodníka'],
		];
		$this->template->displayButtons = [$this->createBackButton('default')];
		$this->template->displayControls = [$this->getComponent('accountForm')];
	}

	public function actionNewAccount(Merchant $merchant): void
	{
		/** @var \Admin\Controls\AdminForm|array<mixed> $form */
		$form = $this->getComponent('accountForm');
		$form['account']['password']->setRequired();
		$form['account']['passwordCheck']->setRequired();
		unset($form['delete']);

		$this->accountFormFactory->onCreateAccount[] = function (Account $account) use ($merchant): void {
			$merchant->accounts->relate([$account->getPK()]);
		};
	}

	public function renderNewAccount(Merchant $merchant): void
	{
		unset($merchant);

		$this->template->headerLabel = 'Nový účet';
		$this->template->headerTree = [
			['Obchodníci', 'default'],
			['Nový účet obchodníka'],
		];
		$this->template->displayButtons = [$this->createBackButton('default')];
		$this->template->displayControls = [$this->getComponent('accountForm')];
	}
}
