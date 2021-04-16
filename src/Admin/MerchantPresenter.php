<?php

declare(strict_types=1);

namespace Eshop\Admin;

use Admin\BackendPresenter;
use Admin\Controls\AdminForm;
use Admin\Controls\AdminFormFactory;
use Admin\Admin\Controls\AccountFormFactory;
use Eshop\DB\CustomerGroupRepository;
use Eshop\DB\Merchant;
use Eshop\DB\MerchantRepository;
use Forms\Form;
use Grid\Datagrid;
use Messages\DB\TemplateRepository;
use Nette\Mail\Mailer;
use Security\DB\Account;
use Security\DB\AccountRepository;

class MerchantPresenter extends BackendPresenter
{
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
	public Mailer $mailer;
	
	public function createComponentGrid()
	{
		$grid = $this->gridFactory->create($this->merchantRepository->many(), 20, 'code', 'ASC', true);
		$grid->addColumnSelector();
		
		$grid->addColumnText('Kód', 'code', '%s', 'code', ['class' => 'fit']);
		$grid->addColumnText('Jméno a příjmení', 'fullname', '%s', 'fullname');
		$grid->addColumnText('Email', 'email', '<a href="mailto:%1$s"><i class="far fa-envelope"></i> %1$s</a>')->onRenderCell[] = [$grid, 'decoratorEmpty'];
		$grid->addColumnText('Skupina', 'customerGroup.name', '%s', 'customerGroup.name');
		
		$btnSecondary = 'btn btn-sm btn-outline-primary';
		$grid->addColumn('', function (Merchant $object, Datagrid $datagrid) use ($btnSecondary) {
			return $object->accounts->first() != null ?
				"<a class='$btnSecondary' href='" . $datagrid->getPresenter()->link('editAccount', $object) . "'>Detail&nbsp;účtu</a>" :
				"<a class='$btnSecondary' href='" . $datagrid->getPresenter()->link('newAccount', $object) . "'>Vytvořit&nbsp;účet</a>";
		}, '%s', null, ['class' => 'minimal']);
		
		$grid->addColumn('Login', function (Merchant $object, Datagrid $grid) use ($btnSecondary) {
			$link = $object->getAccount() ? $grid->getPresenter()->link('loginMerchant!', [$object->accounts->first()->login]) : '#';
			
			return "<a class='" . ($object->accounts->first() ? '' : 'disabled') . " $btnSecondary' target='_blank' href='$link'><i class='fa fa-sign-in-alt'></i></a>";
		}, '%s', null, ['class' => 'minimal']);
		
		$grid->addColumnLinkDetail('Detail');
		$grid->addColumnActionDelete([$this->accountFormFactory, 'deleteAccountHolder']);
		
		$grid->addButtonDeleteSelected([$this->accountFormFactory, 'deleteAccountHolder']);
		
		$grid->addFilterTextInput('search', ['code', 'fullName', 'email'], null, 'Jméno, kód, email');
		$grid->addFilterButtons();
		
		return $grid;
	}
	
	public function createComponentForm(): Form
	{
		$form = $this->formFactory->create();
		
		$form->addText('code', 'Kód');
		$form->addText('fullname', 'Jméno a příjmení')->setRequired();
		$form->addEmail('email', 'Email')->setRequired();
		
		if (!$this->getParameter('merchant')) {
			$this->accountFormFactory->addContainer($form);
		}
		
		$form->addDataSelect('customerGroup', 'Skupina zákazníků', $this->customerGroupRepository->getArrayForSelect())->setPrompt('Žádná');
		$form->addCheckbox('extendedPermission', 'Rozšířená správa zákazníků')->setHtmlAttribute('data-info', 'Povoluje schvalovat zákazníky a nastavovat katalogové oprávnění.<br>Platí pouze v rámci přiřazené skupiny a pro přímo přiřazené zákazníky.');
		$form->addCheckbox('customerEmailNotification', 'Posílat emailem informace o objednávkách přiřazených zákazníků.');
		
		$form->addSubmits(!$this->getParameter('merchant'));
		
		$form->onSuccess[] = function (AdminForm $form) {
			$values = $form->getValues('array');
			
			if (isset($form['account'])) {
				$form['account']['email']->setValue($values['email']);
				unset($values['account']);
			}
			
			$merchant = $this->merchantRepository->syncOne($values, null, true);
			
			if (isset($form['account'])) {
				$this->accountFormFactory->onCreateAccount[] = function ($account) use ($merchant) {
					$merchant->accounts->relate([$account->getPK()]);
				};
				$this->accountFormFactory->success($form, 'merchant.register.successAdmin');
			}
			
			$this->flashMessage('Uloženo', 'success');
			$form->processRedirect('detail', 'default', [$merchant]);
		};
		
		return $form;
	}
	
	public function handleLoginMerchant(string $login)
	{
		$this->user->login($login, '', [Merchant::class], true);
		
		$this->presenter->redirect(':Web:Index:default');
	}
	
	public function renderDefault()
	{
		$this->template->headerLabel = 'Obchodníci';
		$this->template->headerTree = [
			['Obchodníci', 'default'],
		];
		$this->template->displayButtons = [$this->createNewItemButton('new')];
		$this->template->displayControls = [$this->getComponent('grid')];
	}
	
	public function actionNew()
	{
		$form = $this->getComponent('form');
		$form['account']['password']->setRequired();
		$form['account']['passwordCheck']->setRequired();
	}
	
	public function renderNew()
	{
		$this->template->headerLabel = 'Nová položka';
		$this->template->headerTree = [
			['Obchodníci', 'default'],
			['Nová položka'],
		];
		$this->template->displayButtons = [$this->createBackButton('default')];
		$this->template->displayControls = [$this->getComponent('form')];
	}
	
	public function renderDetail(Merchant $merchant)
	{
		$this->template->headerLabel = 'Detail obchodníka - ' . $merchant->fullname;
		$this->template->headerTree = [
			['Obchodníci', 'default'],
			['Detail obchodníka'],
		];
		$this->template->displayButtons = [$this->createBackButton('default')];
		$this->template->displayControls = [$this->getComponent('form')];
	}
	
	public function actionDetail(Merchant $merchant)
	{
		/** @var Form $form */
		$form = $this->getComponent('form');
		
		$form->setDefaults($merchant->toArray());
	}
	
	public function createComponentAccountForm()
	{
		$merchant = $this->getParameter('merchant');
		
		return $this->accountFormFactory->create((bool)$merchant->accounts->first());
	}
	
	public function actionEditAccount(Merchant $merchant)
	{
		/** @var Form $form */
		$form = $this->getComponent('accountForm');
		$form['account']['email']->setDefaultValue($merchant->email);
		
		if ($account = $merchant->accounts->clear()->first()) {
			$form['account']->setDefaults($account->toArray());
		}
		
		$this->accountFormFactory->onUpdateAccount[] = function () {
			$this->flashMessage('Účet byl upraven', 'success');
			$this->redirect('default');
		};
		
		$this->accountFormFactory->onDeleteAccount[] = function () {
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
	
	public function actionNewAccount(Merchant $merchant)
	{
		$form = $this->getComponent('accountForm');
		$form['account']['password']->setRequired();
		$form['account']['passwordCheck']->setRequired();
		unset($form['delete']);
		
		$this->accountFormFactory->onCreateAccount[] = function (Account $account) use ($merchant) {
			$merchant->accounts->relate([$account->getPK()]);
		};
	}
	
	public function renderNewAccount(Merchant $merchant): void
	{
		$this->template->headerLabel = 'Nový účet';
		$this->template->headerTree = [
			['Obchodníci', 'default'],
			['Nový účet obchodníka'],
		];
		$this->template->displayButtons = [$this->createBackButton('default')];
		$this->template->displayControls = [$this->getComponent('accountForm')];
	}
}
