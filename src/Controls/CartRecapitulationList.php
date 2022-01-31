<?php

declare(strict_types=1);

namespace Eshop\Controls;

use Eshop\CheckoutManager;
use Grid\Datalist;
use StORM\ICollection;

/**
 * Class Products
 * @package Eshop\Controls
 */
class CartRecapitulationList extends Datalist
{
	public CheckoutManager $checkoutManager;

	public function __construct(CheckoutManager $checkoutManager, ?ICollection $items = null)
	{
		$this->checkoutManager = $checkoutManager;

		parent::__construct($items ?? $this->checkoutManager->getItems());
	}

	public function render(): void
	{
		$this->template->deliveryAndPaymentPrice = $this->checkoutManager->getDeliveryPrice() + $this->checkoutManager->getPaymentPrice();
		$this->template->deliveryAndPaymentPriceVat = $this->checkoutManager->getDeliveryPriceVat() + $this->checkoutManager->getPaymentPriceVat();
		$this->template->cartCurrency = $this->checkoutManager->getCartCurrencyCode();
		$this->template->cartItems = $this->checkoutManager->getItems();
		$this->template->discountCoupon = $this->checkoutManager->getDiscountCoupon();
		$this->template->discountPrice = $this->checkoutManager->getDiscountPrice();
		$this->template->discountPriceVat = $this->checkoutManager->getDiscountPriceVat();
		$this->template->deliveryType = $this->checkoutManager->getPurchase() ? $this->checkoutManager->getPurchase()->deliveryType : null;
		$this->template->paymentType = $this->checkoutManager->getPurchase() ? $this->checkoutManager->getPurchase()->paymentType : null;
		$this->template->cartCheckoutPrice = $this->checkoutManager->getCheckoutPrice();
		$this->template->cartCheckoutPriceVat = $this->checkoutManager->getCheckoutPriceVat();

		/** @var \Nette\Bridges\ApplicationLatte\Template $template */
		$template = $this->template;

		$template->render($this->template->getFile() ?: __DIR__ . '/cartRecapitulationList.latte');
	}
}
