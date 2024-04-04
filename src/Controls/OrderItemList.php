<?php

declare(strict_types=1);

namespace Eshop\Controls;

use Eshop\Common\CheckInvalidAmount;
use Eshop\DB\CartItemRepository;
use Eshop\DB\Order;
use Eshop\DB\OrderRepository;
use Eshop\DB\PackageItemRepository;
use Eshop\DB\PackageRepository;
use Eshop\DB\ProductRepository;
use Eshop\DB\WatcherRepository;
use Eshop\ShopperUser;
use Grid\Datalist;
use Nette\Application\UI\Form;
use Nette\Application\UI\Multiplier;
use Nette\Forms\IControl;

/**
 * Class Products
 * @package Eshop\Controls
 */
class OrderItemList extends Datalist
{
	private Order $selectedOrder;

	public function __construct(
		private readonly CartItemRepository $cartItemsRepository,
		public readonly ShopperUser $shopperUser,
		private readonly ProductRepository $productRepository,
		Order $order,
		private readonly OrderRepository $orderRepository,
		private readonly PackageItemRepository $packageItemRepository,
		private readonly PackageRepository $packageRepository,
		private readonly WatcherRepository $watcherRepository
	) {
		$this->selectedOrder = $order;

		parent::__construct($order->purchase->getItems());
	}

	public function handleDeleteItem(string $itemId): void
	{
		/** @var \Eshop\DB\CartItem $cartItem */
		$cartItem = $this->cartItemsRepository->one($itemId);

		$this->cartItemsRepository->deleteItem($cartItem->cart, $cartItem);

		$this->redirect('this');
	}

	public function handleDeleteAll(): void
	{
		$this->selectedOrder->purchase->carts->delete();

		$this->redirect('this');
	}

	public function handleRemoveDiscountCoupon(): void
	{
		$this->selectedOrder->purchase->coupon = null;

		$this->redirect('this');
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

				if ($cartItem->cart->purchase) {
					$packageItem = $this->orderRepository->getFirstPackageItemByCartItem($cartItem);

					if ($packageItem === null) {
						$package = $this->packageRepository->createOne([
							'order' => $this->selectedOrder->getPK(),
							'delivery' => $this->selectedOrder->getLastDelivery(),
						]);

						$this->packageItemRepository->createOne([
							'amount' => $cartItem->amount,
							'package' => $package->getPK(),
							'cartItem' => $cartItem->getPK(),
						]);
					} else {
						$packageItem->update(['amount' => $packageItem->amount + $amount - $cartItem->amount]);
					}
				}

				$this->redirect('this');
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

		$this->shopperUser->getCheckoutManager()->changeCartItemAmount($cartItem->getProduct(), $cartItem, $amount, false);

		if ($cartItem->cart->purchase) {
			$packageItem = $this->orderRepository->getFirstPackageItemByCartItem($cartItem);

			if ($packageItem === null) {
				$package = $this->packageRepository->createOne([
					'order' => $this->selectedOrder->getPK(),
					'delivery' => $this->selectedOrder->getLastDelivery(),
				]);

				$this->packageItemRepository->createOne([
					'amount' => $cartItem->amount,
					'package' => $package->getPK(),
					'cartItem' => $cartItem->getPK(),
				]);
			} else {
				$packageItem->update(['amount' => $packageItem->amount + $amount - $cartItem->amount]);
			}
		}

		$this->redirect('this');
	}

	public function handleChangeUpsell($cartItem, $upsell): void
	{
		/** @var \Eshop\DB\CartItem $cartItem */
		$cartItem = $this->cartItemsRepository->one($cartItem, true);

		$upsell = $this->productRepository->getCartItemRelations($cartItem)[$upsell];

		if ($this->isUpsellActive($cartItem->getPK(), $upsell->getPK())) {
			/** @var \Eshop\DB\CartItem $cartItem */
			$cartItem = $this->shopperUser->getCheckoutManager()->getItems()->where('this.fk_upsell', $cartItem->getPK())->where('product.uuid', $upsell->getPK())->first();

			$this->shopperUser->getCheckoutManager()->deleteItem($cartItem);
		} else {
			$this->shopperUser->getCheckoutManager()->addItemToCart(
				$upsell,
				null,
				$upsell->getValue('amount') ?? 1,
				false,
				CheckInvalidAmount::NO_CHECK,
				false,
				$cartItem->cart,
			)->update(['upsell' => $cartItem->getPK()]);
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
		$this->template->cartCurrency = $this->selectedOrder->purchase->currency;
		$this->template->cartItems = $this->selectedOrder->purchase->getItems();
		$this->template->discountCoupon = $this->selectedOrder->getDiscountCoupon();
		$this->template->discountPrice = $this->selectedOrder->getDiscountPrice();
		$this->template->discountPriceVat = $this->selectedOrder->getDiscountPriceVat();
		$this->template->upsells = $this->productRepository->getCartItemsRelations($this->getItemsOnPage());
		$this->template->watchers = ($customer = $this->shopperUser->getCustomer()) ? $this->watcherRepository->getWatchersByCustomer($customer)->setIndex('fk_product')->toArray() : [];

		/** @var \Nette\Bridges\ApplicationLatte\Template $template */
		$template = $this->template;

		$template->render($this->template->getFile() ?: __DIR__ . '/cartItemList.latte');
	}

	public function handleAddItem(string $product): void
	{
		/** @var \Eshop\DB\Product $product */
		$product = $this->productRepository->getProducts()->where('this.uuid', $product)->first();

		/** @var \Eshop\DB\Cart $cart */
		$cart = $this->selectedOrder->purchase->carts->first();

		$cartItem = $this->shopperUser->getCheckoutManager()->addItemToCart($product, null, 1, false, CheckInvalidAmount::CHECK_THROW, true, $cart);

		if ($cartItem->cart->purchase) {
			$package = $this->orderRepository->getFirstPackageByCartItem($cartItem) ??
				$this->packageRepository->createOne([
					'order' => $this->selectedOrder->getPK(),
					'delivery' => $this->selectedOrder->getLastDelivery(),
				]);

			$this->packageItemRepository->createOne([
				'amount' => $cartItem->amount,
				'package' => $package->getPK(),
				'cartItem' => $cartItem->getPK(),
			]);
		}

		$this->redirect('this');
	}

	public function handleDeleteOrder(): void
	{
		$this->selectedOrder->delete();

		$this->redirect('this');
	}
}
