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
	 * @var callable[]&callable(\Eshop\Controls\ProfileForm, string): void;
	 */
	public $onEmailChange;

	private Nette\Mail\Mailer $mailer;

	private TemplateRepository $templateRepository;

	private Nette\Security\User $user;

	private Shopper $shopper;

	public function __construct(Shopper $shopper, Nette\Mail\Mailer $mailer, TemplateRepository $templateRepository, Nette\Security\User $user, Nette\Localization\Translator $translator)
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
			->addRule(static::PATTERN, $translator->translate('AddressesForm.phonePattern', 'Pouze čísla a znak "+" na začátku!'), '^\+?[0-9]+$');
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
			->addRule(static::PATTERN, $translator->translate('AddressesForm.onlyNumbers', 'Pouze čísla!'), '^[0-9]+$');

		$this->addGroup('Doručovací adresa');
		$deliveryAddressBox = $this->addContainer('deliveryAddress');
		$deliveryAddressBox->addText('name', 'deliveryAddress.street')->setRequired();
		$deliveryAddressBox->addText('street', 'deliveryAddress.street')->setRequired();
		$deliveryAddressBox->addText('city', 'deliveryAddress.city')->setRequired();
		$deliveryAddressBox->addText('zipcode', 'deliveryAddress.zipcode')->setRequired()
			->addRule(static::PATTERN, $translator->translate('AddressesForm.onlyNumbers', 'Pouze čísla!'), '^[0-9]+$');

		$this->addGroup('Potvrzení');
		$this->addSubmit('submit', 'profileForm.submit');

		$this->onSuccess[] = [$this, 'success'];
	}

	protected function beforeRender()
	{
		parent::beforeRender();
		$this->setDefaults($this->shopper->getCustomer()->jsonSerialize());
	}

	public function success(ProfileForm $form)
	{
		$values = (array) $form->getValues();
		$customer = $this->shopper->getCustomer();
		$email = $values['email'];

		$customer->syncRelated('billAddress', Nette\Utils\Arrays::pick($values, 'billAddress'));
		$customer->syncRelated('deliveryAddress', Nette\Utils\Arrays::pick($values, 'deliveryAddress'));
		$customer->update($values);

		$emailChanged = $customer->email !== $email;

		if ($emailChanged) {
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

			return;
		}
	}
}
