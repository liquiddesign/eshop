<?php

declare(strict_types=1);

namespace Eshop\Controls;

use Eshop\CheckoutManager;
use Eshop\DB\CartItemRepository;
use Eshop\DB\Order;
use Eshop\DB\OrderRepository;
use Eshop\DB\PackageItemRepository;
use Eshop\DB\PackageRepository;
use Eshop\DB\ProductRepository;
use Eshop\Shopper;
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
	public CheckoutManager $checkoutManager;

	public Shopper $shopper;

	private CartItemRepository $cartItemsRepository;

	private ProductRepository $productRepository;

	private OrderRepository $orderRepository;

	private PackageRepository $packageRepository;

	private PackageItemRepository $packageItemRepository;

	private Order $selectedOrder;

	public function __construct(
		CartItemRepository $cartItemsRepository,
		CheckoutManager $checkoutManager,
		Shopper $shopper,
		ProductRepository $productRepository,
		Order $order,
		OrderRepository $orderRepository,
		PackageItemRepository $packageItemRepository,
		PackageRepository $packageRepository
	) {
		$this->checkoutManager = $checkoutManager;
		$this->cartItemsRepository = $cartItemsRepository;
		$this->shopper = $shopper;
		$this->productRepository = $productRepository;
		$this->selectedOrder = $order;
		$this->orderRepository = $orderRepository;
		$this->packageItemRepository = $packageItemRepository;
		$this->packageRepository = $packageRepository;

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
		$checkoutManager = $this->checkoutManager;
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

				$checkoutManager->changeItemAmount($product, $cartItem->variant, $amount, false, $cartItem->cart);

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

		$this->checkoutManager->changeItemAmount($cartItem->getProduct(), $cartItem->variant, $amount, false, $cartItem->cart);

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

		$upsell = $this->productRepository->getUpsellsForCartItem($cartItem)[$upsell];

		if ($this->isUpsellActive($cartItem->getPK(), $upsell->getPK())) {
			/** @var \Eshop\DB\CartItem $cartItem */
			$cartItem = $this->checkoutManager->getItems()->where('this.fk_upsell', $cartItem->getPK())->where('product.uuid', $upsell->getPK())->first();

			$this->checkoutManager->deleteItem($cartItem);
		} else {
			$this->checkoutManager->addItemToCart($upsell, null, 1, false, false, false, $cartItem->cart)->update(['upsell' => $cartItem->getPK()]);
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
		$this->template->upsells = $this->productRepository->getUpsellsForCartItems($this->getItemsOnPage());

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

		$cartItem = $this->checkoutManager->addItemToCart($product, null, 1, false, true, true, $cart);

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
