<?php

declare(strict_types=1);

namespace Eshop\Controls;

use Eshop\DB\CustomerRepository;
use Eshop\ShopperUser;
use Nette;

/**
 * Class ProfileForm
 * @method onEmailChange(\Eshop\Controls\ProfileForm $form, string $email)
 */
class ProfileForm extends \Nette\Application\UI\Form
{
	/**
	 * Occurs when the form is submitted and successfully validated
	 * @var array<callable(self, array|object): void|callable(array|object): void>
	 */
	public array $onSuccess = [];

	/**
	 * @var array<callable(\Eshop\Controls\ProfileForm, string): void>
	 */
	public array $onEmailChange = [];

	public function __construct(
		private readonly ShopperUser $shopperUser,
		Nette\Localization\Translator $translator,
		CustomerRepository $customerRepository,
	) {
		parent::__construct();

		if (!$shopperUser->getCustomer()) {
			throw new \InvalidArgumentException('Customer not found');
		}

		$this->addText('fullname', 'profileForm.fullname');
		$this->addText('email', 'profileForm.email')->setNullable()->addCondition($this::Filled)->addRule($this::EMAIL);
		// @TODO: validace na regexp
		$this->addText('ccEmails', 'profileForm.ccEmail');
		$this->addText('phone', 'profileForm.phone')
			->addRule(self::PATTERN, $translator->translate('AddressesForm.phonePattern', 'Pouze čísla a znak "+" na začátku!'), '^\+?[0-9]+$');
		$this->addText('company', 'profileForm.company');
		$this->addText('ic', 'profileForm.ic')->addRule($this::MAX_LENGTH, 'Maximální délka je 8 číslic.', 8);
		$this->addText('dic', 'profileForm.dic')->addRule($this::MAX_LENGTH, 'Maximální délka je 10 znaků.', 10);
		$this->addText('bankAccount', 'AddressesForm.bankAccount');
		$this->addText('bankAccountCode', 'AddressesForm.bankAccountCode');
		$this->addText('bankSpecificSymbol', 'AddressesForm.bankSpecificSymbol');

		$this->addGroup('Fakturační adresa');
		$billAddressBox = $this->addContainer('billAddress');
		$billAddressBox->addText('name', 'billAddress.name');
		$billAddressBox->addText('companyName', 'billAddress.companyName');
		$billAddressBox->addText('street', 'billAddress.street')->setRequired();
		$billAddressBox->addText('city', 'billAddress.city')->setRequired();
		$billAddressBox->addText('zipcode', 'billAddress.zipcode')->setRequired()
			->addRule(self::PATTERN, $translator->translate('AddressesForm.onlyNumbers', 'Pouze čísla!'), '^[0-9]+$');

		$this->addGroup('Doručovací adresa');
		$deliveryAddressBox = $this->addContainer('deliveryAddress');
		$deliveryAddressBox->addText('name', 'deliveryAddress.name')->setNullable();
		$deliveryAddressBox->addText('companyName', 'deliveryAddress.companyName')->setNullable();
		$deliveryAddressBox->addText('street', 'deliveryAddress.street');
		$deliveryAddressBox->addText('city', 'deliveryAddress.city');
		$deliveryAddressBox->addText('zipcode', 'deliveryAddress.zipcode')->setNullable()->addCondition($this::Filled)
			->addRule(self::PATTERN, $translator->translate('AddressesForm.onlyNumbers', 'Pouze čísla!'), '^[0-9]+$');

		$this->addGroup('Potvrzení');
		$this->addSubmit('submit', 'profileForm.submit');

		$this->onValidate[] = function (Nette\Application\UI\Form $form) use ($customerRepository, $translator): void {
			if (!$form->isValid()) {
				return;
			}

			$values = $form->getValues();

			$customer = $this->shopperUser->getCustomer();
			$existingCustomer = $customerRepository->many()->where('this.email', $values['email'])->first();

			if (!$existingCustomer || $existingCustomer->getPK() === $customer->getPK()) {
				return;
			}

			/** @var \Nette\Forms\Controls\TextInput $emailInput */
			$emailInput = $form['email'];

			$emailInput->addError($translator->translate('AddressesForm.duplicateEmail', 'Zákazník s tímto e-mailem již existuje!'));
		};

		$this->onSuccess[] = [$this, 'success'];
	}

	public function success(ProfileForm $form): void
	{
		$values = (array) $form->getValues();
		$customer = $this->shopperUser->getCustomer();
		$email = $values['email'];

		$emailChanged = $customer->email !== $email;

		$billAddress = Nette\Utils\Arrays::pick($values, 'billAddress');
		$customer->syncRelated('billAddress', $billAddress);

		/** @var array<string, string> $deliveryAddress */
		$deliveryAddress = Nette\Utils\Arrays::pick($values, 'deliveryAddress');
		$customer->syncRelated('deliveryAddress', $deliveryAddress);

		$customer->update($values);

		if (!$emailChanged) {
			return;
		}

		$this->onEmailChange($this, $email);
	}

	protected function beforeRender(): void
	{
		parent::beforeRender();

		$this->setDefaults($this->shopperUser->getCustomer()->toArray(['billAddress', 'deliveryAddress']));
	}
}
