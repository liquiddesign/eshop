<?php

declare(strict_types=1);

namespace Eshop\Controls;

use Carbon\Carbon;
use Eshop\CheckoutManager;
use Eshop\Shopper;

class NoteForm extends \Nette\Application\UI\Form
{
	/**
	 * Occurs when the form is submitted and successfully validated
	 * @var array<callable(self, array|object): void|callable(array|object): void>
	 */
	public $onSuccess = [];

	private CheckoutManager $checkoutManager;

	private Shopper $shopper;

	public function __construct(CheckoutManager $checkoutManager, Shopper $shopper)
	{
		parent::__construct();

		$this->checkoutManager = $checkoutManager;
		$this->shopper = $shopper;

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

		$account = $this->shopper->getCustomer() && $this->shopper->getCustomer()->getAccount() ? $this->shopper->getCustomer()->getAccount() : null;

		unset($values['couponActive']);

		$values['customer'] = $this->shopper->getCustomer() ? $this->shopper->getCustomer()->getPK() : null;
		$values['account'] = $account ? $account->getPK() : null;
		$values['accountFullname'] = $account ? $account->fullname : null;
		$values['accountEmail'] = $account && \filter_var($account->login, \FILTER_VALIDATE_EMAIL) !== false ? $account->login : null;
		$values['currency'] = $this->shopper->getCurrency();

		$this->checkoutManager->syncPurchase($values);
	}
}
