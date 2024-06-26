<?php

declare(strict_types=1);

namespace Eshop\Controls;

use Eshop\CheckoutManager;
use Eshop\DB\Product;
use Eshop\ShopperUser;
use Forms\Form;
use Nette;

/**
 * @method onItemAddedToCart(\Eshop\DB\CartItem $cartItem, array $values)
 */
class BuyForm extends Form
{
	/**
	 * @var array<callable>
	 */
	public array $onItemAddedToCart = [];

	public ?bool $replaceMode = false;

	public function __construct(Product $product, ShopperUser $shopperUser)
	{
		parent::__construct();

		$checkoutManager = $shopperUser->getCheckoutManager();

		$defaultBuyCount = $product->defaultBuyCount;
		$minCount = $product->minBuyCount ?? CheckoutManager::DEFAULT_MIN_BUY_COUNT;
		$maxCount = $product->maxBuyCount ?? CheckoutManager::DEFAULT_MAX_BUY_COUNT;

		$countInput = $this->addInteger('amount', 'Počet zboží:')
			->setDefaultValue($product->defaultBuyCount)
			->addRule($this::MIN, null, $product->minBuyCount)
			->setRequired();

		if ($maxCount !== null) {
			$countInput->addRule($this::MAX, null, $maxCount);
		}

		if ($product->buyStep !== null) {
			$countInput->addRule([$this, 'validateNumber'], 'Není to násobek', [$product->buyStep, $minCount, $maxCount, $defaultBuyCount]);
			$countInput->setHtmlAttribute('step', $product->buyStep);
		}

		$this->addHidden('itemId', $product->getPK());
		$this->addHidden('variant');
		$this->addSubmit('submit', 'Přidat do košíku')->setDisabled(!$shopperUser->getBuyPermission());

		if (!$shopperUser->getBuyPermission()) {
			return;
		}

		$this->onSuccess[] = function ($form, $values) use ($product, $checkoutManager): void {
			$cartItem = $checkoutManager->addItemToCart($product, $values->variant ?: null, \intval($values->amount), $this->replaceMode);

			$this->onItemAddedToCart($cartItem, (array) $values);
		};
	}

	public function validateNumber(Nette\Forms\Control $control, $args): bool
	{
		[$buyStep, $minCount, $maxCount, $defaultBuyCount] = $args;

		$value = $control->getValue();

		if ($minCount && $value < $minCount) {
			return false;
		}

		if ($maxCount && $value > $maxCount) {
			return false;
		}

		return ($control->getValue() - $defaultBuyCount) % $buyStep === 0;
	}
}
