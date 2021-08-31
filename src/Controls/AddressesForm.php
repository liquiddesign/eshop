<?php

declare(strict_types=1);

namespace Eshop\Controls;

use Eshop\CheckoutManager;
use Eshop\Shopper;
use Nette\Application\UI\Form;
use Nette\Localization\Translator;
use Security\Authenticator;
use Security\DB\AccountRepository;

class AddressesForm extends Form
{
	private CheckoutManager $checkoutManager;
	
	private AccountRepository $accountRepository;
	
	public function __construct(Shopper $shopper, CheckoutManager $checkoutManager, AccountRepository $accountRepository, Translator $translator)
	{
		parent::__construct();
		
		$this->checkoutManager = $checkoutManager;
		$this->accountRepository = $accountRepository;
		
		$this->addText('email', 'AddressesForm.email')->setRequired()->addRule($this::EMAIL);
		$this->addText('ccEmails', 'AddressesForm.ccEmails');
		$this->addText('fullname', 'AddressesForm.fullname')->setRequired()->setMaxLength(32);
		$this->addText('phone', 'AddressesForm.phone')->addRule(static::PATTERN, $translator->translate('AddressesForm.phonePattern', 'Pouze čísla a znak "+" na začátku!'), '^\+?[0-9]+$');;
		
		// address bill
		$billAddressBox = $this->addContainer('billAddress');
		$billAddressBox->addText('street', 'AddressesForm.bill_street')->setRequired();
		$billAddressBox->addText('city', 'AddressesForm.bill_city')->setRequired();
		$billAddressBox->addText('zipcode', 'AddressesForm.bill_zipcode')->addRule(static::PATTERN, $translator->translate('AddressesForm.onlyNumbers', 'Pouze čísla!'), '^[0-9]+$');
		$billAddressBox->addText('state', 'AddressesForm.bill_state');
		
		$this->addCheckbox('otherAddress', 'AddressesForm.otherAddress')->setDefaultValue((bool)$this->checkoutManager->getPurchase()->deliveryAddress);
		$this->addCheckbox('isCompany', 'AddressesForm.isCompany')->setDefaultValue($shopper->getCustomer() && $shopper->getCustomer()->isCompany());
		$this->addCheckbox('createAccount', 'AddressesForm.createAccount');
		$this->addPassword('password', 'AddressesForm.password')
			->addConditionOn($this['createAccount'], $this::EQUAL, true)
			->setRequired();
		$this->addPassword('passwordAgain', 'AddressesForm.passwordAgain')
			->addConditionOn($this['createAccount'], $this::EQUAL, true)
			->addRule($this::EQUAL, 'Hesla se neshodují', $this['password'])
			->setRequired();
		$this->addCheckbox('sendNewsletters', 'AddressesForm.sendNewsletters');
		
		// address delivery
		$deliveryAddressBox = $this->addContainer('deliveryAddress');
		$deliveryAddressBox->addText('name', 'AddressesForm.delivery_name')->addConditionOn($this['otherAddress'], $this::EQUAL, true)->setRequired();
		$deliveryAddressBox->addText('street', 'AddressesForm.delivery_street')->addConditionOn($this['otherAddress'], $this::EQUAL, true)->setRequired();
		$deliveryAddressBox->addText('city', 'AddressesForm.delivery_city')->addConditionOn($this['otherAddress'], $this::EQUAL, true)->setRequired();
		$deliveryAddressBox->addText('zipcode', 'AddressesForm.delivery_zipcode')->addConditionOn($this['otherAddress'], $this::EQUAL, true)->setRequired()
			->addRule(static::PATTERN, $translator->translate('AddressesForm.onlyNumbers', 'Pouze čísla!'), '^[0-9]+$');
		$deliveryAddressBox->addText('state', 'AddressesForm.delivery_state');
		
		// company
		$this->addText('ic', 'AddressesForm.ic')->addConditionOn($this['isCompany'], $this::EQUAL, true)->setRequired();
		$this->addText('dic', 'AddressesForm.dic');
		
		$this->addText('bankAccount', 'AddressesForm.bankAccount');
		$this->addText('bankAccountCode', 'AddressesForm.bankAccountCode');
		$this->addText('bankSpecificSymbol', 'AddressesForm.bankSpecificSymbol');
		
		$customer = $shopper->getCustomer();
		
		if ($customer && !$checkoutManager->getPurchase()->email) {
			$this->setDefaults($customer->jsonSerialize());
			
			if ($customer->billAddress) {
				$billAddressBox->setDefaults($customer->billAddress->jsonSerialize());
			}
			
			if ($customer->deliveryAddress) {
				$deliveryAddressBox->setDefaults($customer->deliveryAddress->jsonSerialize());
			}
		}
		
		$purchase = $checkoutManager->getPurchase();
		
		if ($purchase->email) {
			$this->setDefaults($purchase);
			
			if ($purchase->billAddress) {
				$billAddressBox->setDefaults($purchase->billAddress->jsonSerialize());
			}
			
			if ($purchase->deliveryAddress) {
				$deliveryAddressBox->setDefaults($purchase->deliveryAddress->jsonSerialize());
			}
		}
	
		$this->addSubmit('submit');
		$this->onSuccess[] = [$this, 'success'];
		$this->onValidate[] = [$this, 'validateForm'];
	}
	
	public function validateForm(AddressesForm $form): void
	{
		$values = $form->getValues();
		
		if (!$values->createAccount || !$this->accountRepository->one(['login' => $values->email])) {
			return;
		}
		
		$form->addError('Účet s tímto e-mailem již existuje');
	}
	
	public function success(AddressesForm $form): void
	{
		$values = $form->getValues();
		
		$values->password = $values->createAccount && $values->password ? Authenticator::setCredentialTreatment($values->password) : null;
		
		if (!$values->otherAddress) {
			$values->deliveryAddress = null;
		}
		
		$this->checkoutManager->syncPurchase($values);
	}
}
