<?php

declare(strict_types=1);

namespace Eshop\Controls;

use Eshop\BuyException;
use Eshop\CheckoutManager;

/**
 * @method onBuyError(int $code)
 */
class OrderForm extends \Nette\Application\UI\Form
{
	public CheckoutManager $checkoutManager;

	/**
	 * @var callable[]
	 */
	public array $onBuyError = [];

	public function __construct(CheckoutManager $checkoutManager)
	{
		parent::__construct();
		
		$this->checkoutManager = $checkoutManager;
		
//		$this->addCheckbox('aggreementTermsConditions')->setRequired(true);
//		$this->addCheckbox('aggreementPersonalData')->setRequired(true);
		$this->addSubmit('submit');
		$this->onSuccess[] = [$this, 'success'];
		$this->onValidate[] = [$this, 'validateOrder'];
	}
	
	public function validateOrder(): void
	{
		if (!$this->checkoutManager->checkOrder()) {
			$this->addError('Objednávku nelze odeslat');
		}
	}

	public function success(): void
	{
		try {
			$this->checkoutManager->createOrder();
		} catch (BuyException $exception) {
			$this->onBuyError($exception->getCode());
		}
	}
}
