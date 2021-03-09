<?php

declare(strict_types=1);

namespace Eshop\Controls;

use Eshop\CheckoutManager;
use Eshop\Shopper;
use Eshop\DB\Cart;
use Eshop\DB\CartItem;
use Eshop\DB\CartItemRepository;
use Grid\Datalist;
use Nette\Application\UI\Form;
use Nette\Application\UI\Multiplier;
use Nette\Forms\IControl;

/**
 * Class Products
 * @package Eshop\Controls
 */
class CartRecapitulationList extends Datalist
{
	public CheckoutManager $checkoutManager;
	
	private CartItemRepository $cartItemsRepository;
	
	private Shopper $shopper;
	
	public function __construct(CartItemRepository $cartItemsRepository, CheckoutManager $checkoutManager, Shopper $shopper)
	{
		$this->checkoutManager = $checkoutManager;
		$this->cartItemsRepository = $cartItemsRepository;
		$this->shopper = $shopper;
		
		parent::__construct($this->checkoutManager->getItems());
	}

	public function render(): void
	{
		$this->template->cartCurrency = $this->checkoutManager->getCartCurrencyCode();
		$this->template->cartItems = $this->checkoutManager->getItems();
		$this->template->discountCoupon = $this->checkoutManager->getDiscountCoupon();
		$this->template->discountPrice = $this->checkoutManager->getDiscountPrice();
		$this->template->discountPriceVat = $this->checkoutManager->getDiscountPriceVat();
		$this->template->cartCheckoutPrice = $this->checkoutManager->getCartCheckoutPrice();
		$this->template->cartCheckoutPriceVat = $this->checkoutManager->getCartCheckoutPriceVat();
		
		$this->template->render($this->template->getFile() ?: __DIR__ . '/cartRecapitulationList.latte');
	}
}