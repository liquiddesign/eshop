<?php

declare(strict_types=1);

namespace Eshop\Controls;

use Carbon\Carbon;
use Eshop\Common\Helpers;
use Eshop\ShopperUser;

class NoteForm extends \Nette\Application\UI\Form
{
	/**
	 * Occurs when the form is submitted and successfully validated
	 * @var array<callable(self, array|object): void|callable(array|object): void>
	 */
	public array $onSuccess = [];

	public function __construct(private readonly ShopperUser $shopperUser)
	{
		parent::__construct();

		$this->addTextArea('note');
		$this->addText('internalOrderCode');
		$this->addText('desiredShippingDate')
			->setNullable()
			->setHtmlType('date')
			->setHtmlAttribute('min', (new Carbon())->addDay()->format('Y-m-d'));
		$this->addText('desiredDeliveryDate')
			->setNullable()
			->setHtmlType('date')
			->setHtmlAttribute('min', (new Carbon())->addDay()->format('Y-m-d'));

		$checkoutManager = $this->shopperUser->getCheckoutManager();
		$purchase = $checkoutManager->getPurchase();

		if ($purchase) {
			$this->setDefaults($purchase->toArray());
		}

		$this->addSubmit('submit');
		$this->onSuccess[] = [$this, 'success'];
	}

	public function success(NoteForm $form): void
	{
		unset($form);

		$values = $this->getValues();

		$account = $this->shopperUser->getCustomer() && $this->shopperUser->getCustomer()->getAccount() ? $this->shopperUser->getCustomer()->getAccount() : null;

		unset($values['couponActive']);

		$values['customer'] = $this->shopperUser->getCustomer() ? $this->shopperUser->getCustomer()->getPK() : null;
		$values['account'] = $account?->getPK();
		$values['accountFullname'] = $account?->fullname;
		$values['accountEmail'] = $account && \filter_var($account->login, \FILTER_VALIDATE_EMAIL) !== false ? $account->login : null;
		$values['currency'] = $this->shopperUser->getCurrency();
		$values['internalOrderCode'] = $values['internalOrderCode'] ? Helpers::removeEmoji($values['internalOrderCode']) : null;
		$values['note'] = $values['note'] ? Helpers::removeEmoji($values['note']) : null;

		$this->shopperUser->getCheckoutManager()->syncPurchase($values);
	}
}
