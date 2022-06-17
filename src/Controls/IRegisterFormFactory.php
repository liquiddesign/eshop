<?php

namespace Eshop\Controls;

use Eshop\Shopper;
use Forms\Bridges\FormsSecurity\RegistrationForm;
use Nette\Localization\Translator;

class IRegisterFormFactory
{
	private \Forms\Bridges\FormsSecurity\IRegistrationFormFactory $IRegistrationFormFactory;

	private Translator $translator;

	private Shopper $shopper;
	
	public function __construct(\Forms\Bridges\FormsSecurity\IRegistrationFormFactory $IRegistrationFormFactory, Translator $translator, Shopper $shopper)
	{
		$this->IRegistrationFormFactory = $IRegistrationFormFactory;
		$this->translator = $translator;
		$this->shopper = $shopper;
	}
	
	public function create(): RegistrationForm
	{
		$registerConfig = $this->shopper->getRegistrationConfiguration();

		$form = $this->IRegistrationFormFactory->create($registerConfig['confirmation'], $registerConfig['emailAuthorization']);

		/** @var \Nette\Forms\Controls\TextInput $login */
		$login = $form['login'];

		$login->setHtmlType('email')
			->addRule($form::EMAIL)
			->setRequired($this->translator->translate('registrationForm.enterEmail', 'Zadejte prosím e-mailovou adresu'));
		$accountType = $form->addRadioList('accountType');
		$form->addText('fullname')
			->addConditionOn($accountType, $form::EQUAL, 'personal')
			->setRequired($this->translator->translate('registrationForm.enterFullname', 'Zadejte prosím jméno a příjmení'))
			->endCondition();
		$form->addText('phone');
		$form->addText('company')
			->addConditionOn($accountType, $form::EQUAL, 'company')
			->setRequired($this->translator->translate('registrationForm.enterCompany', 'Zadejte prosím název firmy'))
			->endCondition();
		$form->addText('ic')
			->addConditionOn($accountType, $form::EQUAL, 'company')
			->setRequired($this->translator->translate('registrationForm.enterIC', 'Zadejte prosím IČ firmy'))
			->endCondition();
		$form->addText('dic');

		/** @var \Nette\Forms\Controls\TextInput $password */
		$password = $form['password'];
		/** @var \Nette\Forms\Controls\TextInput $passwordCheck */
		$passwordCheck = $form['passwordCheck'];

		$password->setRequired($this->translator->translate('registrationForm.enterPwd', 'Zadejte prosím heslo'));
		$passwordCheck->setRequired($this->translator->translate('registrationForm.enterPwdCheck', 'Zadejte prosím heslo pro kontrolu'));
		
		return $form;
	}
}
