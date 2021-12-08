<?php

declare(strict_types=1);

namespace Eshop\Controls;

use Eshop\CheckoutManager;
use Eshop\DB\CustomerRepository;
use Eshop\Shopper;
use Nette\Application\UI\Form;
use Nette\Localization\Translator;
use Nette\Security\Passwords;
use Security\DB\AccountRepository;

class AddressesForm extends Form
{
	/**
	 * @var array<callable(self, array|object): void|callable(array|object): void>
	 */
	public $onValidate = [];

	/**
	 * @var array<callable(self, array|object): void|callable(array|object): void>
	 */
	public $onSuccess = [];

	private CheckoutManager $checkoutManager;
	
	private AccountRepository $accountRepository;
	
	private Passwords $passwords;

	private CustomerRepository $customerRepository;
	
	public function __construct(
		Shopper $shopper,
		CheckoutManager $checkoutManager,
		AccountRepository $accountRepository,
		Translator $translator,
		Passwords $passwords,
		CustomerRepository $customerRepository
	) {
		parent::__construct();
		
		$this->checkoutManager = $checkoutManager;
		$this->accountRepository = $accountRepository;
		
		$this->addText('email', 'AddressesForm.email')->setRequired()->addRule($this::EMAIL);
		$this->addText('ccEmails', 'AddressesForm.ccEmails');
		$this->addText('fullname', 'AddressesForm.fullname')->setRequired()->setMaxLength(32);
		$this->addText('phone', 'AddressesForm.phone')->addRule(self::PATTERN, $translator->translate('AddressesForm.phonePattern', 'Pouze čísla a znak "+" na začátku!'), '^\+?[0-9]+$');

		
		// address bill
		$billAddressBox = $this->addContainer('billAddress');
		$billAddressBox->addText('street', 'AddressesForm.bill_street')->setRequired();
		$billAddressBox->addText('city', 'AddressesForm.bill_city')->setRequired();
		$billAddressBox->addText('zipcode', 'AddressesForm.bill_zipcode')->addRule(self::PATTERN, $translator->translate('AddressesForm.onlyNumbers', 'Pouze čísla!'), '^[0-9]+$');
		$billAddressBox->addText('state', 'AddressesForm.bill_state');
		
		$otherAddress = $this->addCheckbox('otherAddress', 'AddressesForm.otherAddress')->setDefaultValue((bool)$this->checkoutManager->getPurchase()->deliveryAddress);
		$isCompany = $this->addCheckbox('isCompany', 'AddressesForm.isCompany')->setDefaultValue($shopper->getCustomer() && $shopper->getCustomer()->isCompany());
		$createAccount = $this->addCheckbox('createAccount', 'AddressesForm.createAccount');
		$this->addPassword('password', 'AddressesForm.password')
			->addConditionOn($createAccount, $this::EQUAL, true)
			->setRequired();
		$this->addPassword('passwordAgain', 'AddressesForm.passwordAgain')
			->addConditionOn($createAccount, $this::EQUAL, true)
			->addRule($this::EQUAL, 'Hesla se neshodují', $this['password'])
			->setRequired();
		$this->addCheckbox('sendNewsletters', 'AddressesForm.sendNewsletters');
		
		// address delivery
		$deliveryAddressBox = $this->addContainer('deliveryAddress');
		$deliveryAddressBox->addText('name', 'AddressesForm.delivery_name')->addConditionOn($otherAddress, $this::EQUAL, true)->setRequired();
		$deliveryAddressBox->addText('street', 'AddressesForm.delivery_street')->addConditionOn($otherAddress, $this::EQUAL, true)->setRequired();
		$deliveryAddressBox->addText('city', 'AddressesForm.delivery_city')->addConditionOn($otherAddress, $this::EQUAL, true)->setRequired();
		$deliveryAddressBox->addText('zipcode', 'AddressesForm.delivery_zipcode')->addConditionOn($otherAddress, $this::EQUAL, true)->setRequired()
			->addRule(self::PATTERN, $translator->translate('AddressesForm.onlyNumbers', 'Pouze čísla!'), '^[0-9]+$');
		$deliveryAddressBox->addText('state', 'AddressesForm.delivery_state');
		
		// company
		$this->addText('ic', 'AddressesForm.ic')->addConditionOn($isCompany, $this::EQUAL, true)->setRequired();
		$this->addText('dic', 'AddressesForm.dic');
		
		$this->addText('bankAccount', 'AddressesForm.bankAccount');
		$this->addText('bankAccountCode', 'AddressesForm.bankAccountCode');
		$this->addText('bankSpecificSymbol', 'AddressesForm.bankSpecificSymbol');
		
		$customer = $shopper->getCustomer();
		
		if ($customer && !$checkoutManager->getPurchase()->email) {
			$this->setDefaults($customer->toArray(['billAddress', 'deliveryAddress']));
			
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
		$this->passwords = $passwords;
		$this->customerRepository = $customerRepository;
	}
	
	public function validateForm(AddressesForm $form): void
	{
		if (!$form->isValid()) {
			return;
		}

		$values = $form->getValues('array');
		
		if (!$values['createAccount'] || !$this->accountRepository->one(['login' => $values['email']]) || !$this->customerRepository->one(['email' => $values['email']])) {
			return;
		}
		
		$form->addError('Účet s tímto e-mailem již existuje');
	}
	
	public function success(AddressesForm $form): void
	{
		$values = $form->getValues('array');
		
		$values['password'] = $values['createAccount'] && $values['password'] ? $this->passwords->hash($values['password']) : null;
		
		if (!$values['otherAddress']) {
			$values['deliveryAddress'] = null;
		}
		
		$this->checkoutManager->syncPurchase($values);
	}
}
