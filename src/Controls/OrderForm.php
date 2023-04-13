<?php

declare(strict_types=1);

namespace Eshop\Controls;

use Eshop\BuyException;
use Eshop\CheckoutManager;
use Nette\Application\UI\Form;
use Tracy\Debugger;
use Tracy\ILogger;

/**
 * @method onBuyError(int $code)
 */
class OrderForm extends \Nette\Application\UI\Form
{
	public CheckoutManager $checkoutManager;

	/**
	 * @var array<callable>
	 */
	public array $onBuyError = [];

	public function __construct(CheckoutManager $checkoutManager)
	{
		parent::__construct();
		
		$this->checkoutManager = $checkoutManager;

		$this->addTextArea('deliveryNote');
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

	public function success(Form $form): void
	{
		try {
			$this->checkoutManager->syncPurchase($form->getValues());
		} catch (\Throwable $e) {
			Debugger::log('Cant sync purchase!', ILogger::WARNING);
		}

		try {
			$this->checkoutManager->createOrder();
		} catch (BuyException $exception) {
			$this->onBuyError($exception->getCode());
		}
	}
}
