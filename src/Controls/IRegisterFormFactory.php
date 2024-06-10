<?php

namespace Eshop\Controls;

use Eshop\ShopperUser;
use Forms\Bridges\FormsSecurity\RegistrationForm;
use Nette\Localization\Translator;

class IRegisterFormFactory
{
	public function __construct(
		protected readonly \Forms\Bridges\FormsSecurity\IRegistrationFormFactory $IRegistrationFormFactory,
		protected readonly Translator $translator,
		protected readonly ShopperUser $shopperUser
	) {
	}
	
	public function create(): RegistrationForm
	{
		$registerConfig = $this->shopperUser->getRegistrationConfiguration();

		$form = $this->IRegistrationFormFactory->create($registerConfig['confirmation'], $registerConfig['emailAuthorization']);

		/** @var \Nette\Forms\Controls\TextInput $login */
		$login = $form['login'];

		$login->setHtmlType('email')
			->addRule($form::Email)
			->setRequired($this->translator->translate('registrationForm.enterEmail', 'Zadejte prosím e-mailovou adresu'));
		$accountType = $form->addRadioList('accountType', items: [
			'personal' => 'personal',
			'company' => 'company',
		]);
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
