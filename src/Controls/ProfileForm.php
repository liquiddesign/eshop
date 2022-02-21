<?php

declare(strict_types=1);

namespace Eshop\Controls;

use Eshop\Shopper;
use Messages\DB\TemplateRepository;
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
	public $onSuccess = [];

	/**
	 * @var callable[]&callable(\Eshop\Controls\ProfileForm, string): void;
	 */
	public $onEmailChange;

	private Nette\Mail\Mailer $mailer;

	private TemplateRepository $templateRepository;

	private Shopper $shopper;

	public function __construct(Shopper $shopper, Nette\Mail\Mailer $mailer, TemplateRepository $templateRepository, Nette\Localization\Translator $translator)
	{
		parent::__construct();

		$this->shopper = $shopper;
		$this->mailer = $mailer;
		$this->templateRepository = $templateRepository;

		if (!$shopper->getCustomer()) {
			throw new \InvalidArgumentException('Customer not found');
		}

		$this->addText('fullname', 'profileForm.fullname');
		$this->addText('email', 'profileForm.email')->addRule($this::EMAIL)->setRequired();
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
		$billAddressBox->addText('street', 'billAddress.street')->setRequired();
		$billAddressBox->addText('city', 'billAddress.city')->setRequired();
		$billAddressBox->addText('zipcode', 'billAddress.zipcode')->setRequired()
			->addRule(self::PATTERN, $translator->translate('AddressesForm.onlyNumbers', 'Pouze čísla!'), '^[0-9]+$');

		$this->addGroup('Doručovací adresa');
		$deliveryAddressBox = $this->addContainer('deliveryAddress');
		$deliveryAddressBox->addText('name', 'deliveryAddress.street')->setRequired();
		$deliveryAddressBox->addText('street', 'deliveryAddress.street')->setRequired();
		$deliveryAddressBox->addText('city', 'deliveryAddress.city')->setRequired();
		$deliveryAddressBox->addText('zipcode', 'deliveryAddress.zipcode')->setRequired()
			->addRule(self::PATTERN, $translator->translate('AddressesForm.onlyNumbers', 'Pouze čísla!'), '^[0-9]+$');

		$this->addGroup('Potvrzení');
		$this->addSubmit('submit', 'profileForm.submit');

		$this->onSuccess[] = [$this, 'success'];
	}

	public function success(ProfileForm $form): void
	{
		$values = (array) $form->getValues();
		$customer = $this->shopper->getCustomer();
		$email = $values['email'];

		$billAddress = Nette\Utils\Arrays::pick($values, 'billAddress');
		$customer->syncRelated('billAddress', $billAddress);

		/** @var array<string, string> $deliveryAddress */
		$deliveryAddress = Nette\Utils\Arrays::pick($values, 'deliveryAddress');
		$customer->syncRelated('deliveryAddress', $deliveryAddress);

		$customer->update($values);

		$emailChanged = $customer->email !== $email;

		if (!$emailChanged) {
			return;
		}

		$token = Nette\Utils\Random::generate(128);

		$mail = $this->templateRepository->createMessage('profile.emailChanged', ['email' => $email, 'link' => $this->getPresenter()->link('//confirmUserEmail!', $token)], $email);
		$this->mailer->send($mail);

		$customer->update([
			'confirmationToken' => $token,
		]);
		$customer->account->update([
			'authorized' => false,
		]);

		$this->onEmailChange($this, $email);
	}

	protected function beforeRender(): void
	{
		parent::beforeRender();

		$this->setDefaults($this->shopper->getCustomer()->toArray(['billAddress', 'deliveryAddress']));
	}
}
