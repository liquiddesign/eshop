<?php

declare(strict_types=1);

namespace Eshop\Controls;

use Eshop\DB\ProductRepository;
use Eshop\ShopperUser;
use Nette\Application\UI\Control;

class CartChecker extends Control
{
	/** @var array<callable(static): void> Occurs when component is attached to presenter */
	public array $onAnchor = [];

	public function __construct(private readonly ProductRepository $productRepository, private readonly ShopperUser $shopperUser)
	{
	}
	
	public function handleConfirmChanges(?string $cartItemId): void
	{
		foreach ($this->shopperUser->getCheckoutManager()->getIncorrectCartItems() as $cartItem) {
			if ($cartItemId && $cartItem['object']->getPK() !== $cartItemId) {
				continue;
			}
			
			if ($cartItem['reason'] === 'incorrect-amount' || $cartItem['reason'] === 'product-round') {
				$product = $this->productRepository->getProduct($cartItem['object']->product->getPK());
				$this->shopperUser->getCheckoutManager()->updateItemInCart($cartItem['object'], $product, null, $cartItem['correctValue'], false, false);
				
				continue;
			}
			
			if ($cartItem['reason'] === 'incorrect-price') {
				$product = $this->productRepository->getProduct($cartItem['object']->product->getPK());
				$this->shopperUser->getCheckoutManager()->updateItemInCart($cartItem['object'], $product, null, $cartItem['object']->amount, false, false);
				
				continue;
			}
			
			if ($cartItem['reason'] !== 'unavailable') {
				continue;
			}

			$this->shopperUser->getCheckoutManager()->deleteItem($cartItem['object']);
		}

		if (!$this->shopperUser->getCheckoutManager()->checkDiscountCoupon()) {
			$this->shopperUser->getCheckoutManager()->setDiscountCoupon(null);
		}
		
		$this->redirect('this');
	}
	
	public function handleRejectChanges(?string $cartItemId): void
	{
		foreach ($this->shopperUser->getCheckoutManager()->getIncorrectCartItems() as $cartItem) {
			if ($cartItemId && $cartItem['object']->getPK() !== $cartItemId) {
				continue;
			}
			
			$this->shopperUser->getCheckoutManager()->deleteItem($cartItem['object']);
		}
		
		$this->redirect('this');
	}
	
	public function render(): void
	{
		$this->template->incorrectCartItems = $this->shopperUser->getCheckoutManager()->getIncorrectCartItems();
		$this->template->discountCoupon = $this->shopperUser->getCheckoutManager()->getDiscountCoupon();
		$this->template->discountCouponValid = $this->shopperUser->getCheckoutManager()->checkDiscountCoupon();

		/** @var \Nette\Bridges\ApplicationLatte\Template $template */
		$template = $this->template;

		$template->render($this->template->getFile() ?: __DIR__ . '/cartChecker.latte');
	}
}
