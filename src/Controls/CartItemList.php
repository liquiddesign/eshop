<?php

declare(strict_types=1);

namespace Eshop\Controls;

use Eshop\CheckoutManager;
use Eshop\DB\CartItemRepository;
use Eshop\DB\ProductRepository;
use Eshop\DB\WatcherRepository;
use Eshop\ShopperUser;
use Grid\Datalist;
use Nette\Application\UI\Form;
use Nette\Application\UI\Multiplier;
use Nette\Forms\Control;
use Nette\Utils\Arrays;

/**
 * Class Products
 * @package Eshop\Controls
 */
class CartItemList extends Datalist
{
	/**
	 * @var array<callable(): void> Occurs on delete item or coupon
	 */
	public array $onItemAmountChange = [];

	/**
	 * @var array<callable(): void> Occurs on delete item or coupon
	 */
	public array $onItemDelete = [];

	/**
	 * @var array<callable(): void> Occurs on delete all items
	 */
	public array $onDeleteAll = [];
	
	public ?string $cartId = CheckoutManager::ACTIVE_CART_ID;

	public function __construct(
		private readonly CartItemRepository $cartItemsRepository,
		public ShopperUser $shopperUser,
		private readonly ProductRepository $productRepository,
		private readonly WatcherRepository $watcherRepository
	) {
		parent::__construct($this->shopperUser->getCheckoutManager()->getItems());
	}

	public function handleDeleteItem(string $itemId): void
	{
		$this->shopperUser->getCheckoutManager()->deleteItem($this->cartItemsRepository->createEntityInstance(['uuid' => $itemId]));

		Arrays::invoke($this->onItemDelete);
	}

	public function handleDeleteAll(): void
	{
		$this->shopperUser->getCheckoutManager()->deleteCart();

		Arrays::invoke($this->onDeleteAll);
	}

	public function handleRemoveDiscountCoupon(string $couponId): void
	{
		unset($couponId);

		$this->shopperUser->getCheckoutManager()->setDiscountCoupon(null);

		Arrays::invoke($this->onItemDelete);
	}

	public function createComponentChangeAmountForm(): Multiplier
	{
		$checkoutManager = $this->shopperUser->getCheckoutManager();
		$cartItemRepository = $this->cartItemsRepository;

		return new Multiplier(function ($itemId) use ($checkoutManager, $cartItemRepository) {
			/** @var \Eshop\DB\CartItem $cartItem */
			$cartItem = $cartItemRepository->one($itemId);
			$product = $cartItem->getProduct();


			$form = new Form();

			$form->addInteger('amount');

			$form->onSuccess[] = function ($form, $values) use ($cartItem, $product, $checkoutManager): void {
				$amount = \intval($values->amount);

				if ($amount <= 0) {
					$amount = 1;
				}

				$checkoutManager->changeCartItemAmount($product, $cartItem, $amount, false);

				Arrays::invoke($this->onItemAmountChange);
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

		$this->shopperUser->getCheckoutManager()->changeCartItemAmount($cartItem->getProduct(), $cartItem, $amount);
	}
	
	public function handleChangeUpsell($cartItem, $upsell, bool $isUnique = false): void
	{
		/** @var \Eshop\DB\CartItem $cartItem */
		$cartItem = $this->cartItemsRepository->one($cartItem, true);
		
		if ($isUnique) {
			$upsellIds = \array_keys($this->productRepository->getCartItemRelations($cartItem));
			$this->cartItemsRepository->deleteUpsellByObjects($cartItem, $upsellIds);
		}
		
		$upsell = $this->productRepository->getCartItemRelations($cartItem)[$upsell];
		
		if ($this->isUpsellActive($cartItem->getPK(), $upsell->getPK())) {
			$cartItem = $this->cartItemsRepository->getUpsellByObjects($cartItem, $upsell);
			
			if ($cartItem) {
				$this->shopperUser->getCheckoutManager()->deleteItem($cartItem);
			}
		} else {
			$this->shopperUser->getCheckoutManager()->addUpsellToCart($cartItem, $upsell);
		}
		
		$this->redirect('this');
	}

	public function isUpsellActive($cartItem, $upsell): bool
	{
		return $this->cartItemsRepository->isUpsellActive($cartItem, $upsell);
	}

	public function validateNumber(Control $control, int $number): bool
	{
		return $control->getValue() % $number === 0;
	}

	public function render(): void
	{
		$this->template->cartCurrency = $this->shopperUser->getCheckoutManager()->getCartCurrencyCode($this->cartId);
		$this->template->cartItems = $this->shopperUser->getCheckoutManager()->getItems($this->cartId);
		$this->template->discountCoupon = $this->shopperUser->getCheckoutManager()->getDiscountCoupon($this->cartId);
		$this->template->discountPrice = $this->shopperUser->getCheckoutManager()->getDiscountPrice($this->cartId);
		$this->template->discountPriceVat = $this->shopperUser->getCheckoutManager()->getDiscountPriceVat($this->cartId);
		$this->template->watchers = ($customer = $this->shopperUser->getCustomer()) ? $this->watcherRepository->getWatchersByCustomer($customer)->setIndex('fk_product')->toArray() : [];

		/** @var array<\Eshop\DB\CartItem> $cartItems */
		$cartItems = $this->getItemsOnPage();
		$this->template->upsells = $this->productRepository->getCartItemsRelations($cartItems);

		/** @var \Nette\Bridges\ApplicationLatte\Template $template */
		$template = $this->template;

		$template->render($this->template->getFile() ?: __DIR__ . '/cartItemList.latte');
	}
}
