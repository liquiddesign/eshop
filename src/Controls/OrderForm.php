<?php

declare(strict_types=1);

namespace Eshop\Controls;

use Eshop\CheckoutManager;

class OrderForm extends \Nette\Application\UI\Form
{
	public CheckoutManager $checkoutManager;
	
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
		$this->checkoutManager->createOrder();
	}
}
