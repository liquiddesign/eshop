<?php

declare(strict_types=1);

namespace Eshop\Controls;

use Eshop\BuyException;
use Eshop\ShopperUser;
use Nette\Application\UI\Form;
use Tracy\Debugger;
use Tracy\ILogger;

/**
 * @method onBuyError(int $code, \Eshop\BuyException $e)
 * @method afterBuyError(int $code, \Eshop\BuyException $e)
 * @method afterOrderCreated(\Eshop\DB\Order $order)
 */
class OrderForm extends \Nette\Application\UI\Form
{
	/**
	 * @var array<callable>
	 */
	public array $onBuyError = [];

	/**
	 * @var null|callable(\Eshop\DB\Order $order): void
	 */
	public $afterOrderCreated = null;

	/**
	 * @var null|callable(int $code, \Eshop\BuyException $e): void
	 */
	public $afterBuyError = null;

	public function __construct(protected readonly ShopperUser $shopperUser)
	{
		parent::__construct();

		$this->addTextArea('deliveryNote')->setNullable();
		$this->addSubmit('submit');
		$this->addSubmit('offerSubmit');
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
			Debugger::log($e, ILogger::EXCEPTION);

			return;
		}

		/** @var \Nette\Forms\Controls\SubmitButton $submitter */
		$submitter = $form->isSubmitted();

		try {
			$order = $this->shopperUser->getCheckoutManager()->createOrder(createOffer: $submitter->getName() === 'offerSubmit');
		} catch (BuyException $exception) {
			$this->onBuyError($exception->getCode(), $exception);

			if ($this->afterBuyError) {
				\call_user_func($this->afterBuyError, $exception->getCode(), $exception);
			}

			return;
		}

		if ($this->afterOrderCreated) {
			\call_user_func($this->afterOrderCreated, $order);
		}

		return;
	}
}
