<?php

declare(strict_types=1);

namespace Eshop\Admin;

use Admin\BackendPresenter;
use Admin\DB\AdministratorRepository;
use Admin\Controls\AdminForm;
use Security\DB\AccountRepository;

class ProfilePresenter extends BackendPresenter
{
	/** @inject */
	public AccountRepository $accountRepo;

	/** @inject */
	public AdministratorRepository $adminRepo;

	public function createComponentAccountForm()
	{
		$form = $this->formFactory->create();

		$profile = $form->addContainer('profile');
		$profile->addText('fullName', 'Jméno');

		$account = $form->addContainer('account');
		$account->addText('login', 'Login')->setDisabled();
		$account->addText('role', 'Role')->setDisabled();
		$account->addPassword('oldPassword', 'Staré heslo')
			->addRule(\App\Admin\Controls\CustomValidators::PROFILE_OLD_PASSWORD, 'Staré heslo není správné!', $this->admin->getIdentity()->account);
		$account->addPassword('newPassword', 'Nové heslo');
		$account->addPassword('newPasswordCheck', 'Kontrola nového hesla')
			->addRule($form::EQUAL, 'Hesla nejsou shodná!', $form['account']['newPassword']);

		$form->addSubmit('submit', 'Uložit');

		return $form;
	}

	public function renderDefault()
	{
		$this->template->headerLabel = 'Profil';
		$this->template->headerTree = [
			['Profil'],
		];
		$this->template->displayButtons = [];
		$this->template->displayControls = [
			$this->getComponent('accountForm'),
		];
	}

	public function actionDefault()
	{
		/** @var \Forms\Form $form */
		$form = $this->getComponent('accountForm');

		/** @var \Admin\DB\Administrator $account */
		$administrator = $this->admin->getIdentity();

		$form->setDefaults([
			'account' => $administrator->account->toArray(),
			'profile' => $administrator->toArray()
		]);

		$form->onSuccess[] = function (AdminForm $form) use ($administrator) {
			$values = $form->getValues();

			if ($values['account']->newPassword && $values['account']->oldPassword) {
				$administrator->account->changePassword($values['account']->newPassword);
			}

			$administrator->update($values['profile']);

			$this->flashMessage('Uloženo', 'success');
			$this->redirect('this');
		};
	}
}