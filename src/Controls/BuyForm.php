<?php

declare(strict_types=1);

namespace Eshop\Controls;

use Eshop\CheckoutManager;
use Eshop\DB\Product;
use Eshop\Shopper;
use Forms\Form;
use Nette;

/**
 * @method onItemAddedToCart(\Eshop\DB\CartItem $cartItem, array $values)
 */
class BuyForm extends Form
{
	/**
	 * @var callable[]
	 */
	public array $onItemAddedToCart = [];

	public ?bool $replaceMode = false;
	
	public function __construct(Product $product, Shopper $shopper, CheckoutManager $checkoutManager)
	{
		parent::__construct();
		
		$defaultBuyCount = $product->defaultBuyCount;
		$minCount = $product->minBuyCount ?? CheckoutManager::DEFAULT_MIN_BUY_COUNT;
		$maxCount = $product->maxBuyCount ?? CheckoutManager::DEFAULT_MAX_BUY_COUNT;
		
		$countInput = $this->addInteger('amount', 'Počet zboží:')
			->setDefaultValue($product->defaultBuyCount)
			->setHtmlAttribute('step', $product->buyStep)
			->addRule($this::MIN, 'Zadejte prosím min. objednávku: %d ' . $product->unit, $product->minBuyCount)
			->addRule([$this, 'validateNumber'], 'Zadejte prosím násobek min. objednávky: %d ' . $product->unit, [$product->buyStep, $minCount, $maxCount, $defaultBuyCount])
			->setRequired();
		
		if ($maxCount !== null) {
			$countInput->addRule($this::MAX, 'Zadejte prosím maximální odběr %d ' . $product->unit, $maxCount);
		}

		$this->addHidden('itemId', $product->getPK());
		$this->addHidden('variant');
		$this->addSubmit('submit', 'Přidat do košíku')->setDisabled(!$shopper->getBuyPermission());

		if (!$shopper->getBuyPermission()) {
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
