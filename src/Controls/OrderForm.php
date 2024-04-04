<?php

declare(strict_types=1);

namespace Eshop\Controls;

use Eshop\BuyException;
use Eshop\ShopperUser;
use Nette\Application\UI\Form;
use Tracy\Debugger;
use Tracy\ILogger;

/**
 * @method onBuyError(int $code)
 */
class OrderForm extends \Nette\Application\UI\Form
{
	/**
	 * @var array<callable>
	 */
	public array $onBuyError = [];

	public function __construct(protected readonly ShopperUser $shopperUser)
	{
		parent::__construct();

		$this->addTextArea('deliveryNote');
		$this->addSubmit('submit');
		$this->onSuccess[] = [$this, 'success'];
		$this->onValidate[] = [$this, 'validateOrder'];
	}
	
	public function validateOrder(): void
	{
		if (!$this->shopperUser->getCheckoutManager()->checkOrder()) {
			$this->addError('Objednávku nelze odeslat');
		}
	}

	public function success(Form $form): void
	{
		try {
			$this->shopperUser->getCheckoutManager()->syncPurchase($form->getValues());
		} catch (\Throwable $e) {
			Debugger::log('Cant sync purchase!', ILogger::WARNING);
		}

		try {
			$this->shopperUser->getCheckoutManager()->createOrder();
		} catch (BuyException $exception) {
			$this->onBuyError($exception->getCode());
		}
	}
}
