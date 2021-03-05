<?php

declare(strict_types=1);

namespace Eshop\Admin\Controls;

use App\Admin\Controls\AdminForm;
use App\Admin\Controls\AdminFormFactory;
use Messages\DB\TemplateRepository;
use Nette\DI\Container;
use Nette\Forms\Controls\Button;
use Nette\Mail\Mailer;
use Nette\SmartObject;
use Security\Authenticator;
use Security\DB\Account;
use Security\DB\AccountRepository;
use Security\DB\IUser;
use Security\DB\RoleRepository;
use StORM\Entity;

/**
 * @method onCreateAccount(\Security\DB\Account $account)
 * @method onDeleteAccount()
 */
class AccountFormFactory
{
	use SmartObject;
	
	/**
	 * @var callable[]&callable(\Security\DB\Account): void
	 */
	public $onCreateAccount;
	
	/**
	 * @var callable[]&callable(): void
	 */
	public $onDeleteAccount;
	
	private AccountRepository $accountRepository;
	
	private Mailer $mailer;
	
	private TemplateRepository $templateRepository;
	
	private AdminFormFactory $adminFormFactory;
	
	private RoleRepository $roleRepository;
	
	public function __construct(Container $container, AccountRepository $accountRepository, TemplateRepository $templateRepository, RoleRepository $roleRepository, Mailer $mailer)
	{
		$this->adminFormFactory = $container->getService(AdminFormFactory::SERVICE_NAME);
		$this->accountRepository = $accountRepository;
		$this->templateRepository = $templateRepository;
		$this->roleRepository = $roleRepository;
		$this->mailer = $mailer;
	}
	
	public function addContainer(AdminForm $form, bool $addRoles = false)
	{
		$roles = $this->roleRepository->many()->where('this.uuid != :role', ['role' => 'servis'])->toArrayOf('name');
		
		$accountContainer = $form->addContainer('account');
		$accountContainer->addHidden('uuid')->setNullable();
		$accountContainer->addText('login', 'Login')
			->setRequired()
			->addRule([$this, 'validateLogin'], 'Login již existuje', [$this->accountRepository, $form['account']['uuid']]);
		
		if ($addRoles) {
			$accountContainer->addSelect('role', 'Role', $roles)->setPrompt('Žádná');
		}
		
		$accountContainer->addPassword('password', 'Heslo');
		$accountContainer->addPassword('passwordCheck', 'Kontrola hesla')
			->addRule($form::EQUAL, 'Hesla nejsou shodná!', $form['account']['password']);
		$accountContainer->addCheckbox('active', 'Aktivní')->setDefaultValue(true);
		$accountContainer->addHidden('email');
		
		$accountContainer->addCheckbox('sendEmail', 'Odeslat e-mail o vytvoření');
	}
	
	public function create()
	{
		$form = $this->adminFormFactory->create();
		
		$this->addContainer($form);
		
		$form->addSubmits();
		
		$submit = $form->addSubmit('delete');
		$class = 'btn btn-outline-danger btn-sm ml-0 mt-1 mb-1 mr-1';
		$submit->setHtmlAttribute('class', $class)->getControlPrototype()->setName('button')->setHtml('<i class="far fa-trash-alt"></i>');
		$submit->onClick[] = function (Button $button) {
			$values = $button->getForm()->getValues('array')['account'];
			$this->accountRepository->many()->where('uuid', $values['uuid'])->delete();
			$this->onDeleteAccount();
		};
		
		$form->onSuccess[] = [$this, 'success'];
		
		
		return $form;
	}
	
	public function success(AdminForm $form, string $emailTemplate = 'lostPassword.changed', array $emailParams = []): void
	{
		$values = $form->getValues('array')['account'];
		
		if ($values['password']) {
			$password = $values['password'];
			$values['password'] =  Authenticator::setCredentialTreatment($values['password']);
		} else {
			unset($values['password']);
		}
		
		if ($values['sendEmail'] ?? null) {
			try {
				$message = $this->templateRepository->createMessage($emailTemplate, ['password' => $password, 'email' => $values['email']] + $emailParams, $values['email']);
				$this->mailer->send($message);
			} catch (\Exception $e) {
				$form->getPresenter()->flashMessage('Varování: Nelze odeslat email! Účet byl přesto upraven.', 'warning');
			}
		}
		
		if (!$values['uuid']) {
			/** @var Account $account */
			$account = $this->accountRepository->createOne($values, true);
			$this->onCreateAccount($account);
		} else {
			$account = $this->accountRepository->one($values['uuid'], true);
			$account->update($values);
		}
	}
	
	public static function validateLogin(\Nette\Forms\Controls\TextInput $input, array $args): bool
	{
		/** @var \Security\DB\AccountRepository $repository */
		$repository = $args[0];
		$collection = $repository->many()->where('login', (string)$input->getValue());
		
		
		if (isset($args[1]) && $args[1]) {
			$collection->whereNot('this.uuid', $args[1]);
		}
		
		return $collection->isEmpty();
	}
	
	public function deleteAccountHolder(IUser $holder)
	{
		if ($holder->getAccount()) {
			$account = $holder->getAccount();
		}
		
		$holder->delete();
		
		if (isset($account)) {
			$account->delete();
		}
	}
}