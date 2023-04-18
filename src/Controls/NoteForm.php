<?php

declare(strict_types=1);

namespace Eshop\Controls;

use Carbon\Carbon;
use Eshop\ShopperUser;

class NoteForm extends \Nette\Application\UI\Form
{
	/**
	 * Occurs when the form is submitted and successfully validated
	 * @var array<callable(self, array|object): void|callable(array|object): void>
	 */
	public $onSuccess = [];

	public function __construct(private readonly ShopperUser $shopperUser)
	{
		parent::__construct();

		$this->addTextArea('note');
		$this->addText('internalOrderCode');
		$this->addText('desiredShippingDate')
			->setHtmlType('date')
			->setHtmlAttribute('min', (new Carbon())->addDay()->format('Y-m-d'));

		$this->addSubmit('submit');
		$this->onSuccess[] = [$this, 'success'];
	}

	public function success(NoteForm $form): void
	{
		unset($form);

		$values = $this->getValues();
		$values['desiredShippingDate'] = $values['desiredShippingDate'] ?: null;

		$account = $this->shopperUser->getCustomer() && $this->shopperUser->getCustomer()->getAccount() ? $this->shopperUser->getCustomer()->getAccount() : null;

		unset($values['couponActive']);

		$values['customer'] = $this->shopperUser->getCustomer() ? $this->shopperUser->getCustomer()->getPK() : null;
		$values['account'] = $account?->getPK();
		$values['accountFullname'] = $account?->fullname;
		$values['accountEmail'] = $account && \filter_var($account->login, \FILTER_VALIDATE_EMAIL) !== false ? $account->login : null;
		$values['currency'] = $this->shopperUser->getCurrency();

		$this->checkoutManager->syncPurchase($values);
	}
}
