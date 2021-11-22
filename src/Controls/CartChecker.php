<?php

declare(strict_types=1);

namespace Eshop\Controls;

use Eshop\CheckoutManager;
use Eshop\DB\ProductRepository;
use Nette\Application\UI\Control;

class CartChecker extends Control
{
	private CheckoutManager $checkoutManager;
	
	private ProductRepository $productRepository;
	
	public function __construct(CheckoutManager $checkoutManager, ProductRepository $productRepository)
	{
		$this->checkoutManager = $checkoutManager;
		$this->productRepository = $productRepository;
	}
	
	public function handleConfirmChanges(?string $cartItemId): void
	{
		foreach ($this->checkoutManager->getIncorrectCartItems() as $cartItem) {
			if ($cartItemId && $cartItem['object']->getPK() !== $cartItemId) {
				continue;
			}
			
			if ($cartItem['reason'] === 'incorrect-amount' || $cartItem['reason'] === 'product-round') {
				$product = $this->productRepository->getProduct($cartItem['object']->product->getPK());
				$this->checkoutManager->updateItemInCart($cartItem['object'], $product, null, $cartItem['correctValue'], false, false);
				
				continue;
			}
			
			if ($cartItem['reason'] === 'incorrect-price') {
				$product = $this->productRepository->getProduct($cartItem['object']->product->getPK());
				$this->checkoutManager->updateItemInCart($cartItem['object'], $product, null, $cartItem['object']->amount, false, false);
				
				continue;
			}
			
			if ($cartItem['reason'] !== 'unavailable') {
				continue;
			}

			$this->checkoutManager->deleteItem($cartItem['object']);
		}
		
		$this->redirect('this');
	}
	
	public function handleRejectChanges(?string $cartItemId): void
	{
		foreach ($this->checkoutManager->getIncorrectCartItems() as $cartItem) {
			if ($cartItemId && $cartItem['object']->getPK() !== $cartItemId) {
				continue;
			}
			
			$this->checkoutManager->deleteItem($cartItem['object']);
		}
		
		$this->redirect('this');
	}
	
	public function render(): void
	{
		$this->template->incorrectCartItems = $this->checkoutManager->getIncorrectCartItems();
		$this->template->discountCoupon = $this->checkoutManager->getDiscountCoupon();
		$this->template->discountCouponValid = $this->checkoutManager->checkDiscountCoupon();

		/** @var \Nette\Bridges\ApplicationLatte\Template $template */
		$template = $this->template;

		$template->render($this->template->getFile() ?: __DIR__ . '/cartChecker.latte');
	}
}
