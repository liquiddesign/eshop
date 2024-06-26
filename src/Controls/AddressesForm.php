<?php

declare(strict_types=1);

namespace Eshop\Controls;

use Base\ShopsConfig;
use Eshop\DB\CustomerRepository;
use Eshop\ShopperUser;
use Nette\Application\UI\Form;
use Nette\Localization\Translator;
use Nette\Security\Passwords;
use Security\DB\AccountRepository;

class AddressesForm extends Form
{
	public function __construct(
		protected readonly ShopperUser $shopperUser,
		protected readonly AccountRepository $accountRepository,
		Translator $translator,
		protected readonly Passwords $passwords,
		protected readonly CustomerRepository $customerRepository,
		protected readonly ShopsConfig $shopsConfig,
	) {
		parent::__construct();

		$customer = $shopperUser->getCustomer();
		$selectedCustomer = $this->shopperUser->getSessionSelectedCustomer();

		$customer = $selectedCustomer ?? $customer;

		$this->addText('email', 'AddressesForm.email')
			->setRequired()
			->addRule($this::Email)
			->setHtmlAttribute('autocomplete', 'email');

		$this->addText('ccEmails', 'AddressesForm.ccEmails')->setHtmlAttribute('autocomplete', 'off');
		$this->addText('fullname', 'AddressesForm.fullname')->setRequired()->setMaxLength(32)->setHtmlAttribute('autocomplete', 'name');
		$this->addText('phone', 'AddressesForm.phone')
			->setHtmlAttribute('autocomplete', 'tel')
			->addRule(self::PATTERN, $translator->translate('AddressesForm.phonePattern', 'Pouze čísla a znak "+" na začátku!'), '^\+?[0-9]+$');

		
		// address bill
		$billAddressBox = $this->addContainer('billAddress');
		$billAddressBox->addText('street', 'AddressesForm.bill_street')->setRequired()->setHtmlAttribute('autocomplete', 'street-address');
		$billAddressBox->addText('city', 'AddressesForm.bill_city')->setRequired()->setHtmlAttribute('autocomplete', 'address-level2');
		$billAddressBox->addText('zipcode', 'AddressesForm.bill_zipcode')->setRequired()
			->setHtmlAttribute('autocomplete', 'postal-code')
			->addRule(self::PATTERN, $translator->translate('AddressesForm.onlyNumbers', 'Pouze čísla!'), '^[0-9]+$');
		$billAddressBox->addText('state', 'AddressesForm.bill_state')->setHtmlAttribute('autocomplete', 'address-level1');
		
		$otherAddress = $this->addCheckbox('otherAddress', 'AddressesForm.otherAddress')->setDefaultValue((bool) $this->shopperUser->getCheckoutManager()->getPurchase()->deliveryAddress);
		$isCompany = $this->addCheckbox('isCompany', 'AddressesForm.isCompany')->setDefaultValue($customer?->isCompany());
		$createAccount = $this->addCheckbox('createAccount', 'AddressesForm.createAccount');

		$this->addPassword('password', 'AddressesForm.password')
			->setHtmlAttribute('autocomplete', 'new-password')
			->addConditionOn($createAccount, $this::EQUAL, true)
			->setRequired();
		$this->addPassword('passwordAgain', 'AddressesForm.passwordAgain')
			->setHtmlAttribute('autocomplete', 'new-password')
			->addConditionOn($createAccount, $this::EQUAL, true)
			->addRule($this::EQUAL, 'Hesla se neshodují', $this['password'])
			->setRequired();

		$this->addCheckbox('sendNewsletters', 'AddressesForm.sendNewsletters');
		$this->addCheckbox('sendSurvey', 'AddressesForm.sendSurvey');

		// address delivery
		$deliveryAddressBox = $this->addContainer('deliveryAddress');
		$deliveryAddressBox->addText('name', 'AddressesForm.delivery_name')->addConditionOn($otherAddress, $this::EQUAL, true)->setRequired();
		$deliveryAddressBox->addText('companyName', 'AddressesForm.delivery_companyName')->setNullable();
		$deliveryAddressBox->addText('street', 'AddressesForm.delivery_street')
			->setHtmlAttribute('autocomplete', 'street-address')
			->addConditionOn($otherAddress, $this::EQUAL, true)->setRequired();
		$deliveryAddressBox->addText('city', 'AddressesForm.delivery_city')
			->setHtmlAttribute('autocomplete', 'address-level2')
			->addConditionOn($otherAddress, $this::EQUAL, true)->setRequired();
		$deliveryAddressBox->addText('zipcode', 'AddressesForm.delivery_zipcode')
			->setHtmlAttribute('autocomplete', 'postal-code')
			->addConditionOn($otherAddress, $this::EQUAL, true)->setRequired()
			->addRule(self::PATTERN, $translator->translate('AddressesForm.onlyNumbers', 'Pouze čísla!'), '^[0-9]+$');
		$deliveryAddressBox->addText('state', 'AddressesForm.delivery_state')->setHtmlAttribute('autocomplete', 'address-level1');
		
		// company
		$this->addText('ic', 'AddressesForm.ic')->addConditionOn($isCompany, $this::EQUAL, true)->setRequired();
		$this->addText('dic', 'AddressesForm.dic');
		
		$this->addText('bankAccount', 'AddressesForm.bankAccount');
		$this->addText('bankAccountCode', 'AddressesForm.bankAccountCode');
		$this->addText('bankSpecificSymbol', 'AddressesForm.bankSpecificSymbol');

		$this->addHidden('parentCustomer', $customer && $selectedCustomer && $customer->getPK() !== $selectedCustomer->getPK() ? $customer->getPK() : null)->setNullable();
		
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

		/** @var array<mixed> $values */
		$values = $form->getValues('array');

		$accountQuery = $this->accountRepository->many()->where('login', $values['email']);
		$customerQuery = $this->customerRepository->many()->where('email', $values['email']);

		$this->shopsConfig->filterShopsInShopEntityCollection($accountQuery);
		$this->shopsConfig->filterShopsInShopEntityCollection($customerQuery);

		/** @var \Security\DB\Account|null $account */
		$account = $accountQuery->first();
		/** @var \Eshop\DB\Customer|null $customer */
		$customer = $customerQuery->first();

		if ($values['createAccount'] && $account) {
			$form->addError('Účet s tímto e-mailem již existuje');

			return;
		}

		if (!$this->shopperUser->isAlwaysCreateCustomerOnOrderCreated() && ($values['createAccount'] && $customer)) {
			$form->addError('Účet s tímto e-mailem již existuje');
		}

		return;
	}
	
	public function success(AddressesForm $form): void
	{
		/** @var array<mixed> $values */
		$values = $form->getValues('array');
		
		$values['password'] = $values['createAccount'] && $values['password'] ? $this->passwords->hash($values['password']) : null;
		
		if (!$values['otherAddress']) {
			$values['deliveryAddress'] = null;
		}
		
		$this->shopperUser->getCheckoutManager()->syncPurchase($values);
	}
}
