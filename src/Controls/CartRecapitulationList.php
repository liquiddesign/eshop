<?php

declare(strict_types=1);

namespace Eshop\Controls;

use Eshop\ShopperUser;
use Grid\Datalist;
use Nette\Utils\Arrays;
use StORM\ICollection;

/**
 * Class Products
 * @package Eshop\Controls
 */
class CartRecapitulationList extends Datalist
{
	/** @var array<callable(self): void> */
	public array $onRender = [];

	public function __construct(public readonly ShopperUser $shopperUser, ?ICollection $items = null)
	{
		parent::__construct($items ?? $this->shopperUser->getCheckoutManager()->getItems());
	}

	public function render(): void
	{
		$this->template->deliveryAndPaymentPrice = $this->shopperUser->getCheckoutManager()->getDeliveryPrice() + $this->shopperUser->getCheckoutManager()->getPaymentPrice();
		$this->template->deliveryAndPaymentPriceVat = $this->shopperUser->getCheckoutManager()->getDeliveryPriceVat() + $this->shopperUser->getCheckoutManager()->getPaymentPriceVat();
		$this->template->cartCurrency = $this->shopperUser->getCheckoutManager()->getCartCurrencyCode();
		$this->template->cartItems = $this->shopperUser->getCheckoutManager()->getItems();
		$this->template->discountCoupon = $this->shopperUser->getCheckoutManager()->getDiscountCoupon();
		$this->template->discountPrice = $this->shopperUser->getCheckoutManager()->getDiscountPrice();
		$this->template->discountPriceVat = $this->shopperUser->getCheckoutManager()->getDiscountPriceVat();
		$this->template->deliveryType = $this->shopperUser->getCheckoutManager()->getPurchase() ? $this->shopperUser->getCheckoutManager()->getPurchase()->deliveryType : null;
		$this->template->paymentType = $this->shopperUser->getCheckoutManager()->getPurchase() ? $this->shopperUser->getCheckoutManager()->getPurchase()->paymentType : null;
		$this->template->cartCheckoutPrice = $this->shopperUser->getCheckoutManager()->getCheckoutPrice();
		$this->template->cartCheckoutPriceVat = $this->shopperUser->getCheckoutManager()->getCheckoutPriceVat();

		Arrays::invoke($this->onRender, $this);

		/** @var \Nette\Bridges\ApplicationLatte\Template $template */
		$template = $this->template;

		$template->render($this->template->getFile() ?: __DIR__ . '/cartRecapitulationList.latte');
	}
}
