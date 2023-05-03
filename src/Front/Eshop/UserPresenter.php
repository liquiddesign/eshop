<?php

declare(strict_types=1);

namespace Eshop\Front\Eshop;

use Eshop\Controls\IRegisterFormFactory;
use Eshop\DB\AddressRepository;
use Eshop\DB\CatalogPermissionRepository;
use Eshop\DB\Customer;
use Eshop\DB\CustomerGroupRepository;
use Eshop\DB\CustomerRepository;
use Eshop\DB\Merchant;
use Eshop\DB\MerchantRepository;
use Forms\Bridges\FormsSecurity\RegistrationForm;
use Messages\DB\TemplateRepository;
use Nette;
use Security\DB\Account;
use Security\DB\AccountRepository;

abstract class UserPresenter extends \Eshop\Front\FrontendPresenter
{
	protected const ADMIN_EMAIL = 'info@roiwell.cz';

	#[\Nette\DI\Attributes\Inject]
	public \Forms\Bridges\FormsSecurity\ILostPasswordFormFactory $lostPasswordFormFactory;
	
	#[\Nette\DI\Attributes\Inject]
	public \Forms\Bridges\FormsSecurity\ILoginFormFactory $loginFormFactory;
	
	#[\Nette\DI\Attributes\Inject]
	public TemplateRepository $templateRepository;
	
	#[\Nette\DI\Attributes\Inject]
	public CustomerRepository $customerRepository;
	
	#[\Nette\DI\Attributes\Inject]
	public AccountRepository $accountRepository;
	
	#[\Nette\DI\Attributes\Inject]
	public Nette\Mail\Mailer $mailer;
	
	#[\Nette\DI\Attributes\Inject]
	public IRegisterFormFactory $registrationFormFactory;
	
	#[\Nette\DI\Attributes\Inject]
	public AddressRepository $addressRepository;
	
	#[\Nette\DI\Attributes\Inject]
	public CustomerGroupRepository $customerGroupRepo;

	#[\Nette\DI\Attributes\Inject]
	public CatalogPermissionRepository $catalogPermissionRepository;

	#[\Nette\DI\Attributes\Inject]
	public MerchantRepository $merchantRepository;

	#[\Nette\DI\Attributes\Inject]
	public Nette\Security\Passwords $passwords;
	
	public function createComponentLostPasswordForm(): \Forms\Bridges\FormsSecurity\LostPasswordForm
	{
		$form = $this->lostPasswordFormFactory->create();
		$form->onRecover[] = function (\Forms\Bridges\FormsSecurity\LostPasswordForm $form): void {
			$values = $form->getValues('array');

			$mail = $this->templateRepository->createMessage('lostPassword', ['email' => $values['email']] +
				['link' => $form->getPresenter()->link('//:Eshop:User:setNewPassword', [$form->token])], $values['email']);

			$this->mailer->send($mail);
			
			$form->getPresenter()->flashMessage($this->translator->translate('lostPwdForm.emailSend', 'Na e-mailovou adresu jsme Vám poslali odkaz pro obnovu hesla'));
			
			$form->getPresenter()->redirect('login');
		};
		
		return $form;
	}
	
	public function actionLogin(): void
	{
		if ($this->getUser()->isLoggedIn()) {
			$this->redirect(':Web:Index:default');
		}
	}
	
	public function createComponentLoginForm(): \Forms\Bridges\FormsSecurity\LoginForm
	{
		$form = $this->loginFormFactory->create([Customer::class, Merchant::class]);
		
		$form->onLogin[] = function (\Forms\Bridges\FormsSecurity\LoginForm $form, Nette\Security\IIdentity $user): void {
			if (($user instanceof Customer || $user instanceof Merchant) && $user->getAccount() && $mutation = $this->shopperUser->getPreferredMutationByAccount($user->getAccount())) {
				$this->lang = $mutation;
			}

			if ($user instanceof Customer) {
				$form->getPresenter()->redirect(':Eshop:Order:orders');
			}
			
			if (!($user instanceof Merchant)) {
				return;
			}

			$form->getPresenter()->redirect(':Eshop:Profile:customers');
		};
		
		$form->onLoginFail[] = function (\Forms\Bridges\FormsSecurity\LoginForm $form, int $errorCode): void {
			$form->getPresenter()->flashMessage($this->translator->translate('loginForm.incorrect', 'Nesprávné přihlašovací údaje'), 'danger');
			$form->getPresenter()->redirect('this');
		};
		
		return $form;
	}
	
	public function createComponentRegisterForm(): RegistrationForm
	{
		$form = $this->registrationFormFactory->create();
		
		$form->onError[] = function (RegistrationForm $form): void {
			foreach ($form->getErrors() as $error) {
				if ($error === 'registerForm.account.alreadyExists') {
					$this->flashMessage($this->translator->translate('registerForm.emailExists', 'Účet s tímto emailem již existuje!'), 'error');
				} elseif ($error === 'registerForm.passwordCheck.notEqual') {
					$this->flashMessage($this->translator->translate('registerForm.pwdsNotMatch', 'Hesla se neshodují!'), 'error');
				}
			}
		};

		$form->onAccountCreated[] = function (RegistrationForm $form, Account $account): void {
			$values = $form->getValues('array');

			/** @var \Eshop\DB\CustomerGroup|null $defaultGroup */
			$defaultGroup = $this->customerGroupRepo->getDefaultRegistrationGroup();

			/** @var \Eshop\DB\Customer|null $customer */
			$customer = $this->customerRepository->many()->match(['email' => $values['login']])->first();

			if ($customer && !$this->catalogPermissionRepository->many()->match(['fk_customer' => $customer->getPK()])->isEmpty()) {
				$this->accountRepository->many()->where('login', $values['login'])->delete();
				$this->flashMessage($this->translator->translate('registerForm.emailExists', 'Účet s tímto emailem již existuje'), 'error');

				$this->redirect('this');
			}

			$customerValues = [
				'email' => $values['login'],
				'fullname' => $values['fullname'],
				'phone' => $values['phone'],
				'ic' => $values['ic'],
				'dic' => $values['dic'],
				'company' => $values['company'],
				'deliveryAddress' => null,
				'group' => $defaultGroup ? $defaultGroup->getPK() : null,
				'discountLevelPct' => $defaultGroup ? $defaultGroup->defaultDiscountLevelPct : 0,
			];

			if ($customer) {
				$customer->update($customerValues);

				$customer = $this->customerRepository->many()->match(['email' => $values['login']])->first();
			} else {
				$customer = $this->customerRepository->createOne($customerValues);
			}

			if (!$customer) {
				return;
			}

			$this->catalogPermissionRepository->createOne([
				'catalogPermission' => $defaultGroup ? $defaultGroup->defaultCatalogPermission : 'none',
				'buyAllowed' => $defaultGroup ? $defaultGroup->defaultBuyAllowed : true,
				'orderAllowed' => true,
				'viewAllOrders' => $defaultGroup ? $defaultGroup->defaultViewAllOrders : false,
				'showPricesWithoutVat' => $defaultGroup ? $defaultGroup->defaultPricesWithoutVat : false,
				'showPricesWithVat' => $defaultGroup ? $defaultGroup->defaultPricesWithVat : false,
				'priorityPrice' => $defaultGroup ? $defaultGroup->defaultPriorityPrice : 'withoutVat',
				'customer' => $customer->getPK(),
				'account' => $account->getPK(),
			]);

			if (\count($defaultGroup->defaultPricelists->toArray()) > 0) {
				$customer->pricelists->relate(\array_keys($defaultGroup->defaultPricelists->toArray()));
			}

			$merchants = $this->merchantRepository->getMerchantsByCustomer($customer);

			if ($customer->group) {
				$account->update([
					'active' => $customer->group->autoActiveCustomers,
				]);
			}

			$emailVariables = [
				'email' => $customer->email,
				'activation' => $customer->group && !$customer->group->autoActiveCustomers,
			];

			foreach ($merchants as $merchant) {
				if ($merchant->customerEmailNotification && $merchant->email) {
					$mail = $this->templateRepository->createMessage('register.merchantInfo', $emailVariables, $merchant->email);
					$this->mailer->send($mail);
				}
			}
		};

		$form->onComplete[] = function (RegistrationForm $form, $email, $password, $confirmation, $emailAuthorization, $token): void {
			$this->sendEmailAuthorization($form, $email, $password, $emailAuthorization, $token);

			if ($confirmation) {
				$this->sendAdminInfo($form, $email, $password);
			}

			$this->flashMessage($this->translator->translate('registerForm.completeAuth', 'Děkujeme za registraci. Po potvrzení e-mailové adresy se můžete přihlásit.'), 'success');
			$form->getPresenter()->redirect(':Eshop:User:login');
		};
		
		return $form;
	}
	
	public function sendEmailAuthorization(RegistrationForm $form, $email, $password, $emailAuthorization, $token): void
	{
		unset($form, $password);
		
		$params = [
			'email' => $email,
			'link' => $token ? $this->link('//confirmUserEmail!', $token) : '#',
		];
		
		if (!Nette\Utils\Validators::isEmail($email)) {
			return;
		}
		
		$registerConfirmation = $this->templateRepository->createMessage('register.confirmation', $params, $email);
		$registerSuccess = $this->templateRepository->createMessage('register.success', $params, $email);
		
		$mail = $emailAuthorization ? $registerConfirmation : $registerSuccess;
		$this->mailer->send($mail);
	}
	
	public function sendAdminInfo(Nette\Forms\Form $form, $email, $password, $mutation = null): void
	{
		unset($form, $password);
		
		$params = [
			'email' => $email,
		];
		
		$mail = $this->templateRepository->createMessage('register.adminInfo', $params, $this::ADMIN_EMAIL, null, null, $mutation);
		$this->mailer->send($mail);
	}
	
	public function handleConfirmUserEmail(string $token): void
	{
		/** @var \Security\DB\Account|null $account */
		$account = $this->accountRepository->one(['confirmationToken' => $token]);
		
		if (!$account) {
			return;
		}
		
		$account->update([
			'confirmationToken' => '',
			'authorized' => true,
		]);
		
		$this->flashMessage($this->translator->translate('user.emailConfirmed2', 'E-mailová adresa byla potvrzena. Nyní se můžete přihlásit.'));
		$this->redirect(':Eshop:User:login');
	}
	
	public function handleGenerateNewPassword(string $token, string $email): void
	{
		/** @var \Security\DB\Account|null $account */
		$account = $this->accountRepository->one(['confirmationToken' => $token]);
		
		if (!$account || !$account->authorized) {
			return;
		}
		
		$account->update(['confirmationToken' => '']);
		
		$newPassword = Nette\Utils\Random::generate();
		$account->update(['password' => $this->passwords->hash($newPassword)]);
		
		$email = $this->templateRepository->createMessage('lostPassword.changed', ['email' => $email, 'password' => $newPassword], $email);
		$this->mailer->send($email);
		
		$this->flashMessage($this->translator->translate('lostPasswordForm.passwordChanged', 'Heslo bylo změněno.'));
		$this->redirect(':Eshop:User:login');
	}

	public function actionConfirmEmailToken(string $token): void
	{
		/** @var \Security\DB\Account|null $account */
		$account = $this->accountRepository->one(['confirmationToken' => $token]);

		if (!$account) {
			return;
		}

		$account->update([
			'confirmationToken' => null,
			'authorized' => true,
		]);

		$params = [
			'email' => $account->login,
		];

		if (\Nette\Utils\Validators::isEmail($account->login)) {
			$mail = $this->templateRepository->createMessage('register.success', $params, $account->login);
			$this->mailer->send($mail);
		}

		$this->flashMessage($this->translator->translate('user.emailConfirmed1', 'E-mailová adresa byla potvrzena. Nyní se můžete přihlásit.'), 'success');
		$this->redirect(':Eshop:User:login');
	}
	
	public function actionSetNewPassword(string $token): void
	{
		/** @var \Security\DB\Account|null $account */
		$account = $this->accountRepository->one(['confirmationToken' => $token]);
		
		if (!$account || !$account->authorized) {
			$this->flashMessage($this->translator->translate('user.recoveryInvalid', 'Odkaz pro obnovu hesla je neplatný.'), 'danger');
			$this->redirect(':Eshop:User:login');
		}
		
		$form = new Nette\Application\UI\Form($this, 'setNewPasswordForm');
		$form->addPassword('password', $this->translator->translate('user.newPassword', 'Nové heslo'))->setRequired();
		$form->addPassword('passwordRepeat', $this->translator->translate('user.newPasswordCheck', 'Nové heslo (pro kontrolu)'))
			->addRule($form::EQUAL, $this->translator->translate('user.passwordsDoesntMatch', 'Hesla se neshodují'), $form['password']);
		$form->addSubmit('submit');
		
		$form->onSuccess[] = function (Nette\Forms\Form $form) use ($account): void {
			$values = $form->getValues('array');
			$account->update(['confirmationToken' => '']);

			$account->update(['password' => $this->passwords->hash($values['password'])]);
			
			$this->flashMessage($this->translator->translate('user.recoverySuccess', 'Heslo bylo úspěšně změněno.'), 'success');
			$this->redirect(':Eshop:User:login');
		};
	}
}
