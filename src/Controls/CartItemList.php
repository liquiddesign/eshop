<?php

declare(strict_types=1);

namespace Eshop\Controls;

use Eshop\CheckoutManager;
use Eshop\DB\CartItem;
use Eshop\DB\CartItemRepository;
use Eshop\DB\ProductRepository;
use Eshop\Shopper;
use Grid\Datalist;
use Nette\Application\UI\Form;
use Nette\Application\UI\Multiplier;
use Nette\Forms\IControl;

/**
 * Class Products
 * @method onDeleteAll()
 * @package Eshop\Controls
 */
class CartItemList extends Datalist
{
	public CheckoutManager $checkoutManager;

	public Shopper $shopper;

	/**
	 * @var callable[]&callable(): void; Occurs after cart item delete
	 */
	public $onDeleteAll;

	private CartItemRepository $cartItemsRepository;

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

		$this->onDeleteAll();
	}

	public function handleRemoveDiscountCoupon(string $couponId): void
	{
		unset($couponId);

		$this->checkoutManager->setDiscountCoupon(null);
	}

	public function createComponentChangeAmountForm(): Multiplier
	{
		$checkoutManager = $this->checkoutManager;
		$cartItemRepository = $this->cartItemsRepository;

		return new Multiplier(function ($itemId) use ($checkoutManager, $cartItemRepository) {
			/** @var \Eshop\DB\CartItem $cartItem */
			$cartItem = $cartItemRepository->one($itemId);
			$product = $cartItem->getProduct();


			$form = new Form();

			//			$maxCount = $product->maxBuyCount ?? $shopper->getMaxBuyCount();
			$form->addInteger('amount');
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

	public function handleChangeAmount($cartItem, $amount): void
	{
		/** @var \Eshop\DB\CartItem $cartItem */
		$cartItem = $this->cartItemsRepository->one($cartItem, true);

		$amount = \intval($amount);

		if ($amount <= 0) {
			$amount = 1;
		}

		$this->checkoutManager->changeItemAmount($cartItem->getProduct(), $cartItem->variant, $amount, false);
	}

	public function handleChangeUpsell($cartItem, $upsell): void
	{
		/** @var \Eshop\DB\CartItem $cartItem */
		$cartItem = $this->cartItemsRepository->one($cartItem, true);

		$upsell = $this->productRepository->getCartItemRelations($cartItem)[$upsell];

		if ($this->isUpsellActive($cartItem->getPK(), $upsell->getPK())) {
			/** @var \Eshop\DB\CartItem $cartItem */
			$cartItem = $this->checkoutManager->getItems()->where('this.fk_upsell', $cartItem->getPK())->where('product.uuid', $upsell->getPK())->first();

			$this->checkoutManager->deleteItem($cartItem);
		} else {
			$this->checkoutManager->addItemToCart($upsell, null, $upsell->getValue('amount') ?? 1, false, false, true)->update(['upsell' => $cartItem->getPK()]);
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

		/** @var \Eshop\DB\CartItem[] $cartItems */
		$cartItems = $this->getItemsOnPage();
		$this->template->upsells = $this->productRepository->getCartItemsRelations($cartItems);

		/** @var \Nette\Bridges\ApplicationLatte\Template $template */
		$template = $this->template;

		$template->render($this->template->getFile() ?: __DIR__ . '/cartItemList.latte');
	}
}
