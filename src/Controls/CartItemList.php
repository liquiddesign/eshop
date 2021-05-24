<?php

declare(strict_types=1);

namespace Eshop\Controls;

use Eshop\CheckoutManager;
use Eshop\DB\Product;
use Eshop\DB\ProductRepository;
use Eshop\Shopper;
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
class CartItemList extends Datalist
{
	public CheckoutManager $checkoutManager;

	private CartItemRepository $cartItemsRepository;

	public Shopper $shopper;

	private ProductRepository $productRepository;

	public function __construct(CartItemRepository $cartItemsRepository, CheckoutManager $checkoutManager, Shopper $shopper, ProductRepository $productRepository)
	{
		$this->checkoutManager = $checkoutManager;
		$this->cartItemsRepository = $cartItemsRepository;
		$this->shopper = $shopper;
		$this->productRepository = $productRepository;

		parent::__construct($this->checkoutManager->getItems());
	}

	public function handleDeleteItem(string $itemId): void
	{
		$this->checkoutManager->deleteItem(new CartItem(['uuid' => $itemId], $this->cartItemsRepository));
	}

	public function handleDeleteAll(): void
	{
		$this->checkoutManager->deleteCart();
	}

	public function handleRemoveDiscountCoupon(string $couponId): void
	{
		$this->checkoutManager->setDiscountCoupon(null);
	}

	public function createComponentChangeAmountForm(): Multiplier
	{
		$checkoutManager = $this->checkoutManager;
		$cartItemRepository = $this->cartItemsRepository;
		$shopper = $this->shopper;

		return new Multiplier(function ($itemId) use ($checkoutManager, $cartItemRepository, $shopper) {
			/** @var \Eshop\DB\CartItem $cartItem */
			$cartItem = $cartItemRepository->one($itemId);
			$product = $cartItem->getProduct();


			$form = new Form();

			//			$maxCount = $product->maxBuyCount ?? $shopper->getMaxBuyCount();
			$amountInput = $form->addInteger('amount');
			//
			//			if ($maxCount !== null) {
			//				$amountInput->addRule($form::MAX, 'Překročeno povolené množství', $product->maxBuyCount ?? $shopper->getMaxBuyCount());
			//			}
			//
			//			if ($product->buyStep !== null) {
			//				$amountInput->addRule([$this, 'validateNumber'], 'Není to násobek', $product->buyStep);
			//			}

			$form->onSuccess[] = function ($form, $values) use ($cartItem, $product, $checkoutManager): void {
				$amount = \intval($values->amount);

				if ($amount <= 0) {
					$amount = 1;
				}

				$checkoutManager->changeItemAmount($product, $cartItem->variant, $amount, false);
			};

			return $form;
		});
	}

	public function handleChangeAmount($cartItem, $amount)
	{
		/** @var \Eshop\DB\CartItem $cartItem */
		$cartItem = $this->cartItemsRepository->one($cartItem, true);

		$amount = \intval($amount);

		if ($amount <= 0) {
			$amount = 1;
		}

		$this->checkoutManager->changeItemAmount($cartItem->getProduct(), $cartItem->variant, $amount, false);
	}

	public function handleChangeUpsell($cartItem, $upsell)
	{
		/** @var \Eshop\DB\CartItem $cartItem */
		$cartItem = $this->cartItemsRepository->one($cartItem, true);

		$upsell = $this->productRepository->getUpsellsForCartItem($cartItem)[$upsell];

		if ($this->isUpsellActive($cartItem->getPK(), $upsell->getPK())) {
			$this->checkoutManager->deleteItem($this->checkoutManager->getItems()->where('this.fk_upsell', $cartItem->getPK())->where('product.uuid', $upsell->getPK())->first());
		} else {
			$this->checkoutManager->addItemToCart($upsell, null, 1, false, false, false)->update(['upsell' => $cartItem->getPK()]);
		}

		$this->redirect('this');
	}

	public function isUpsellActive($cartItem, $upsell): bool
	{
		return $this->cartItemsRepository->isUpsellActive($cartItem, $upsell);
	}

	public function validateNumber(IControl $control, int $number): bool
	{
		return $control->getValue() % $number === 0;
	}

	public function render(): void
	{
		$this->template->cartCurrency = $this->checkoutManager->getCartCurrencyCode();
		$this->template->cartItems = $this->checkoutManager->getItems();
		$this->template->discountCoupon = $this->checkoutManager->getDiscountCoupon();
		$this->template->discountPrice = $this->checkoutManager->getDiscountPrice();
		$this->template->discountPriceVat = $this->checkoutManager->getDiscountPriceVat();
		$this->template->upsells = $this->productRepository->getUpsellsForCartItems($this->getItemsOnPage());

		$this->template->render($this->template->getFile() ?: __DIR__ . '/cartItemList.latte');
	}
}
