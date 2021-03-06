<?php

declare(strict_types=1);

namespace Eshop\Controls;

use Eshop\CheckoutManager;
use Eshop\DB\Product;
use Eshop\Shopper;
use Nette;

class BuyForm extends \Nette\Application\UI\Form
{
	private const DEFAULT_MAX_BUY_COUNT = 999999999;
	
	public function __construct(Product $product, Shopper $shopper, CheckoutManager $checkoutManager)
	{
		parent::__construct();
		
		$maxCount = $product->maxBuyCount ?? self::DEFAULT_MAX_BUY_COUNT;
		
		$countInput = $this->addInteger('amount', 'Počet zboží:')
			->setDefaultValue($product->defaultBuyCount)
			->addRule($this::MIN, null , $product->minBuyCount)
			->setRequired();
		
		if ($maxCount !== null) {
			$countInput->addRule($this::MAX, null , $maxCount);
		}
		
		if ($product->buyStep !== null) {
			$countInput->addRule([$this, 'validateNumber'], 'Není to násobek', $product->buyStep);
		}
		
		$this->addHidden('itemId', $product->getPK());
		$this->addHidden('variant');
		$this->addSubmit('submit', 'Přidat do košíku')->setDisabled($shopper->getCatalogPermission() !== 'full');
		
		if ($shopper->getCatalogPermission() === 'full' && $product) {
			$this->onSuccess[] = function ($form, $values) use ($product, $checkoutManager) {
				$checkoutManager->addItemToCart($product, $values->variant ?: null, intval($values->amount));
			};
		}
	}
	
	public function validateNumber(Nette\Forms\IControl $control, int $cislo){
		return $control->getValue() % $cislo === 0;
	}
}