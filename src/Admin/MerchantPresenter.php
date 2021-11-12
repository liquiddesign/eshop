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
use Forms\Form;
use Grid\Datagrid;
use Messages\DB\TemplateRepository;
use Nette\Mail\Mailer;
use Nette\Security\Passwords;
use Security\DB\Account;
use Security\DB\AccountRepository;

class MerchantPresenter extends BackendPresenter
{
	protected const CONFIGURATIONS = [
		'customers' => true,
		'showUnregisteredGroup' => true,
	];

	/** @inject */
	public AccountFormFactory $accountFormFactory;

	/** @inject */
	public MerchantRepository $merchantRepository;

	/** @inject */
	public AccountRepository $accountRepository;

	/** @inject */
	public TemplateRepository $templateRepository;

	/** @inject */
	public CustomerGroupRepository $customerGroupRepository;

	/** @inject */
	public CustomerRepository $customerRepository;

	/** @inject */
	public PricelistRepository $pricelistRepository;

	/** @inject */
	public Mailer $mailer;
	
	/** @inject */
	public Passwords $passwords;

	public function createComponentGrid(): AdminGrid
	{
		$grid = $this->gridFactory->create($this->merchantRepository->many(), 20, 'code', 'ASC', true);
		$grid->addColumnSelector();

		$grid->addColumnText('Kód', 'code', '%s', 'code', ['class' => 'fit']);
		$grid->addColumnText('Jméno a příjmení', 'fullname', '%s', 'fullname');
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
			$link = $object->accounts->clear()->first() ? $grid->getPresenter()->link(
				'loginMerchant!',
				[$object->accounts->clear()->first()->login],
			) : '#';

			return "<a class='" . ($object->accounts->clear()->first() ? '' : 'disabled') . " $btnSecondary' target='_blank' href='$link'><i class='fa fa-sign-in-alt'></i></a>";
		}, '%s', null, ['class' => 'minimal']);

		$grid->addColumnLinkDetail('Detail');
		$grid->addColumnActionDelete([$this->accountFormFactory, 'deleteAccountHolder']);

		$grid->addButtonDeleteSelected([$this->accountFormFactory, 'deleteAccountHolder']);

		$grid->addFilterTextInput('search', ['code', 'fullName', 'email'], null, 'Jméno, kód, e-mail');
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

		if ($this::CONFIGURATIONS['customers']) {
			$form->addDataMultiSelect('customers', 'Zákazníci', $this->customerRepository->getArrayForSelect());
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
			$values = $form->getValues('array');

			if (isset($form['account'])) {
				$form['account']['email']->setValue($values['email']);
				unset($values['account']);
			}

			/** @var \Eshop\DB\Merchant $merchant */
			$merchant = $this->merchantRepository->syncOne($values, null, true);

			if (isset($form['account'])) {
				$valuesAccount = $form->getValues('array')['account'];

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
		$this->user->login($login, '', [Merchant::class], true);

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
		}

		$form->setDefaults($merchant->toArray($relations));
	}

	public function createComponentAccountForm(): AdminForm
	{
		$merchant = $this->getParameter('merchant');

		return $this->accountFormFactory->create((bool)$merchant->accounts->first());
	}

	public function actionEditAccount(Merchant $merchant): void
	{
		/** @var \Forms\Form $form */
		$form = $this->getComponent('accountForm');
		$form['account']['email']->setDefaultValue($merchant->email);

		if ($account = $merchant->accounts->clear()->first()) {
			$form['account']->setDefaults($account->toArray());
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
