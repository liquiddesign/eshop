<?php

declare(strict_types=1);

namespace Eshop\Controls;

use Eshop\DB\CustomerRepository;
use Eshop\ShopperUser;
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

	public function __construct(
		private readonly ShopperUser $shopperUser,
		private readonly AccountRepository $accountRepository,
		Translator $translator,
		private readonly Passwords $passwords,
		private readonly CustomerRepository $customerRepository
	) {
		parent::__construct();

		$customer = $shopperUser->getCustomer();
		$selectedCustomer = $this->shopperUser->getSessionSelectedCustomer();

		$customer = $selectedCustomer ?? $customer;

		$this->addText('email', 'AddressesForm.email')->setRequired()->addRule($this::EMAIL);
		$this->addText('ccEmails', 'AddressesForm.ccEmails');
		$this->addText('fullname', 'AddressesForm.fullname')->setRequired()->setMaxLength(32);
		$this->addText('phone', 'AddressesForm.phone')->addRule(self::PATTERN, $translator->translate('AddressesForm.phonePattern', 'Pouze čísla a znak "+" na začátku!'), '^\+?[0-9]+$');

		
		// address bill
		$billAddressBox = $this->addContainer('billAddress');
		$billAddressBox->addText('street', 'AddressesForm.bill_street')->setRequired();
		$billAddressBox->addText('city', 'AddressesForm.bill_city')->setRequired();
		$billAddressBox->addText('zipcode', 'AddressesForm.bill_zipcode')->setRequired()
			->addRule(self::PATTERN, $translator->translate('AddressesForm.onlyNumbers', 'Pouze čísla!'), '^[0-9]+$');
		$billAddressBox->addText('state', 'AddressesForm.bill_state');
		
		$otherAddress = $this->addCheckbox('otherAddress', 'AddressesForm.otherAddress')->setDefaultValue((bool) $this->shopperUser->getCheckoutManager()->getPurchase()->deliveryAddress);
		$isCompany = $this->addCheckbox('isCompany', 'AddressesForm.isCompany')->setDefaultValue($customer?->isCompany());
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
		$deliveryAddressBox->addText('companyName', 'AddressesForm.delivery_companyName')->setNullable();
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

		$this->addHidden('parentCustomer', $customer && $selectedCustomer && $customer->getPK() !== $selectedCustomer->getPK() ? $customer->getPK() : null);
		
		if ($customer && !$this->shopperUser->getCheckoutManager()->getPurchase()->email) {
			$customerArray = $customer->toArray(['billAddress', 'deliveryAddress']);
			$customerArray['fullname'] = $customer->getName();

			$this->setDefaults($customerArray);
			
			if ($customer->billAddress) {
				$billAddressBox->setDefaults($customer->billAddress->jsonSerialize());
			}
			
			if ($customer->deliveryAddress) {
				$deliveryAddressBox->setDefaults($customer->deliveryAddress->jsonSerialize());
			}
		}
		
		$purchase = $this->shopperUser->getCheckoutManager()->getPurchase();
		
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
		if (!$form->isValid()) {
			return;
		}

		$values = $form->getValues('array');
		
		if (!$values['createAccount'] || (
				(
					(
						!$this->accountRepository->one(['login' => $values['email']]) &&
						!$this->customerRepository->one(['email' => $values['email']])
					) ||
					$this->shopperUser->isAlwaysCreateCustomerOnOrderCreated()
				) &&
				(
					!$this->accountRepository->one(['login' => $values['email']]) ||
					!$this->shopperUser->isAlwaysCreateCustomerOnOrderCreated()
				)
			)
		) {
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
		
		$this->shopperUser->getCheckoutManager()->syncPurchase($values);
	}
}
