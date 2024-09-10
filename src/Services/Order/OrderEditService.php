<?php

namespace Eshop\Services\Order;

use Base\Bridges\AutoWireService;
use Eshop\Admin\SettingsPresenter;
use Eshop\Common\CheckInvalidAmount;
use Eshop\DB\Cart;
use Eshop\DB\CartItem;
use Eshop\DB\CartItemRepository;
use Eshop\DB\CartRepository;
use Eshop\DB\Customer;
use Eshop\DB\DeliveryRepository;
use Eshop\DB\Order;
use Eshop\DB\OrderRepository;
use Eshop\DB\Package;
use Eshop\DB\PackageItem;
use Eshop\DB\PackageItemRepository;
use Eshop\DB\PackageRepository;
use Eshop\DB\Product;
use Eshop\DB\ProductRepository;
use Eshop\DB\RelatedCartItemRepository;
use Eshop\DB\RelatedPackageItemRepository;
use Eshop\DB\RelatedTypeRepository;
use Eshop\DB\VatRateRepository;
use Eshop\ShopperUser;
use Web\DB\SettingRepository;

readonly class OrderEditService implements AutoWireService
{
	public function __construct(
		protected OrderRepository $orderRepository,
		protected ShopperUser $shopperUser,
		protected ProductRepository $productRepository,
		protected PackageRepository $packageRepository,
		protected DeliveryRepository $deliveryRepository,
		protected VatRateRepository $vatRateRepository,
		protected CartRepository $cartRepository,
		protected PackageItemRepository $packageItemRepository,
		protected SettingRepository $settingRepository,
		protected RelatedCartItemRepository $relatedCartItemRepository,
		protected RelatedTypeRepository $relatedTypeRepository,
		protected RelatedPackageItemRepository $relatedPackageItemRepository,
		protected CartItemRepository $cartItemRepository
	) {
	}

	/**
	 * @param \Eshop\DB\Order $order
	 * @param \Eshop\DB\Product|string $product
	 * @param int $amount
	 * @param \Eshop\DB\Cart|string|null $cart
	 * @param \Eshop\DB\Package|string|true|null $package
	 * @param bool $force
	 * @param \Eshop\DB\Customer|null $customer
	 * @param bool|null $replaceMode
	 * @return array{\Eshop\DB\PackageItem, \Eshop\DB\CartItem}
	 * @throws \Eshop\BuyException
	 * @throws \StORM\Exception\NotFoundException
	 */
	public function addProduct(
		Order $order,
		Product|string $product,
		int $amount,
		Cart|string|null $cart = null,
		Package|string|true|null $package = null,
		bool $force = false,
		Customer|null $customer = null,
		bool|null $replaceMode = null,
	): array {
		$this->beforeProcess($order, $customer);
		$purchase = $order->purchase;

		if (!$product instanceof Product) {
			$product = $this->productRepository->one($product);
		}

		if (!$product) {
			throw new \Exception('Product not found');
		}

		if ($cart !== null && !$cart instanceof Cart) {
			$cart = $this->cartRepository->one($cart);
		}

		if ($cart === null) {
			$cart = $purchase->getCarts()->first();

			if (!$cart) {
				throw new \Exception('No cart available');
			}
		}
		
		if (\is_string($package)) {
			$package = $this->packageRepository->one($package, true);
		}

		if ($package === true) {
			$package = $order->getPackages()->first();
		}

		if ($package === null) {
			$newPackageId = $this->packageRepository->many()->where('this.fk_order', $order->getPK())->select(['packagesCount' => 'MAX(this.id) + 1'])->firstValue('packagesCount') ?? 1;

			/** @var \Eshop\DB\Delivery $delivery */
			$delivery = $this->deliveryRepository->createOne([
				'order' => $order,
				'currency' => $purchase->currency->getPK(),
				'type' => $purchase->deliveryType,
				'typeName' => $purchase->deliveryType?->toArray()['name'],
				'typeCode' => $purchase->deliveryType?->code,
				'price' => 0,
				'priceVat' => 0,
				'priceBefore' => 0,
				'priceVatBefore' => 0,
			]);

			/** @var \Eshop\DB\Package $package */
			$package = $this->packageRepository->createOne([
				'id' => $newPackageId,
				'order' => $order->getPK(),
				'delivery' => $delivery->getPK(),
			]);
		}

		/** @var \Eshop\DB\Product|null $productWithPrice */
		$productWithPrice = $this->productRepository->getProducts()->where('this.uuid', $product->getPK())->first();

		if (!$productWithPrice) {
			if (!$force) {
				throw new \Exception("Customer can't buy product '$product->name ($product->code)'!");
			}

			try {
				$product->setValue('price', $product->getValue('price') ?: 0);
			} catch (\Exception $e) {
				$product->setValue('price', 0);
			}

			try {
				$product->setValue('priceVat', $product->getValue('priceVat') ?: 0);
			} catch (\Exception $e) {
				$product->setValue('priceVat', 0);
			}

			try {
				$product->setValue('priceBefore', $product->getValue('priceBefore') ?: null);
			} catch (\Exception $e) {
				$product->setValue('priceBefore', null);
			}

			try {
				$product->setValue('priceVatBefore', $product->getValue('priceVatBefore') ?: null);
			} catch (\Exception $e) {
				$product->setValue('priceVatBefore', null);
			}
		} else {
			try {
				$productWithPrice->setValue('price', $product->getValue('price'));
			} catch (\Exception $e) {
			}

			try {
				$productWithPrice->setValue('priceVat', $product->getValue('priceVat'));
			} catch (\Exception $e) {
			}

			try {
				$productWithPrice->setValue('priceBefore', $product->getValue('priceBefore'));
			} catch (\Exception $e) {
			}

			try {
				$productWithPrice->setValue('priceVatBefore', $product->getValue('priceVatBefore'));
			} catch (\Exception $e) {
			}

			$product = $productWithPrice;
		}

		$cartItem = $this->shopperUser->getCheckoutManager()->addItemToCart(
			$product,
			null,
			$amount,
			$replaceMode,
			$force ? CheckInvalidAmount::NO_CHECK : CheckInvalidAmount::CHECK_THROW,
			!$force,
			cart: $cart
		);

		if ($replaceMode === null) {
			$packageItem = $this->packageItemRepository->createOne([
				'amount' => $cartItem->amount,
				'package' => $package->getPK(),
				'cartItem' => $cartItem,
			]);
		} else {
			if (!$packageItem = $package->getItems()->where('cartItem.fk_product', $product->getPK())->first()) {
				$packageItem = $this->packageItemRepository->createOne([
					'amount' => $cartItem->amount,
					'package' => $package->getPK(),
					'cartItem' => $cartItem,
				]);
			} else {
				$packageItem->update(['amount' => $cartItem->amount]);
			}
		}

		$setRelationType = $this->settingRepository->getValueByName(SettingsPresenter::SET_RELATION_TYPE);

		if ($setRelationType) {
			/** @var \Eshop\DB\RelatedType $setRelationType */
			$setRelationType = $this->relatedTypeRepository->one($setRelationType);
		}

		/* Get default set relation type and slave products in that relation for top-level cart item */
		if (!$setRelationType) {
			return [$packageItem, $cartItem];
		}

		/** @var array<mixed> $relatedCartItems */
		$relatedCartItems = [];

		if (!$cartItem->product) {
			return [$packageItem, $cartItem];
		}

		/* Load real products in relation with prices */
		$relatedProducts = $this->productRepository->getSlaveRelatedProducts($setRelationType, $cartItem->product)->toArray();

		if (!$relatedProducts) {
			return [$packageItem, $cartItem];
		}

		$slaveProducts = [];

		foreach ($relatedProducts as $relatedProduct) {
			$slaveProducts[] = $relatedProduct->getValue('slave');
		}

		/* Compute total price of set items */
		/** @var array<\Eshop\DB\Product> $slaveProducts */
		$slaveProducts = $this->productRepository->getProducts()->where('this.uuid', $slaveProducts)->toArray();
		$slaveProductsTotalPrice = 0;
		$slaveProductsTotalPriceVat = 0;

		foreach ($relatedProducts as $relatedProduct) {
			if (!isset($slaveProducts[$relatedProduct->getValue('slave')])) {
				continue;
			}

			$slaveProductsTotalPrice += $slaveProducts[$relatedProduct->getValue('slave')]->getPrice() * $relatedProduct->amount;
			$slaveProductsTotalPriceVat += $slaveProducts[$relatedProduct->getValue('slave')]->getPriceVat() * $relatedProduct->amount;
		}

		$setTotalPriceModifier = $slaveProductsTotalPrice > 0 ? $cartItem->price / $slaveProductsTotalPrice : 1;
		$setTotalPriceVatModifier = $slaveProductsTotalPriceVat > 0 ? $cartItem->priceVat / $slaveProductsTotalPriceVat : 1;

		foreach ($relatedProducts as $relatedProduct) {
			if (!isset($slaveProducts[$relatedProduct->getValue('slave')])) {
				if (!$force) {
					throw new \Exception('Customer can\'t buy this product!');
				}

				$product = $this->productRepository->one($relatedProduct->getValue('slave'), true);

				$product->setValue('price', 0);
				$product->setValue('priceVat', 0);
				$product->setValue('priceBefore', null);
				$product->setValue('priceVatBefore', null);
			} else {
				$product = $slaveProducts[$relatedProduct->getValue('slave')];
			}

			/** @var \Eshop\DB\VatRate|null $vat */
			$vat = $this->vatRateRepository->one($product->vatRate);
			$vatPct = $vat ? $vat->rate : 0;

			/* Create related cart items with price computed to match unit price of top-level cart item */
			$relatedCartItems[] = [
				'cartItem' => $cartItem->getPK(),
				'relatedType' => $setRelationType->getPK(),
				'product' => $product->getPK(),
				'relatedTypeCode' => $setRelationType->code,
				'relatedTypeName' => $setRelationType->name,
				'productName' => $product->toArray()['name'],
				'productCode' => $product->getFullCode(),
				'productSubCode' => $product->subCode,
				'productWeight' => $product->weight,
				'productDimension' => $product->dimension,
				'amount' => $relatedProduct->amount * $cartItem->amount,
				'price' => $product->getPrice() * $setTotalPriceModifier,
				'priceVat' => $product->getPriceVat() * $setTotalPriceVatModifier,
				'priceBefore' => $product->getPriceBefore() ?: $product->getPrice(),
				'priceVatBefore' => $product->getPriceVatBefore() ?: $product->getPriceVat(),
				'vatPct' => (float) $vatPct,
			];
		}

		/** @var array<\Eshop\DB\RelatedCartItem> $relatedCartItems */
		$relatedCartItems = $this->relatedCartItemRepository->createMany($relatedCartItems)->toArray();

		/* Create relation between related package item and related cart item */
		foreach ($relatedCartItems as $relatedCartItem) {
			$this->relatedPackageItemRepository->createOne([
				'cartItem' => $relatedCartItem->getPK(),
				'packageItem' => $packageItem->getPK(),
			]);
		}

		return [$packageItem, $cartItem];
	}

	public function changeItemAmount(PackageItem $packageItem, CartItem $cartItem, int $amount): void
	{
		$cartItemClone = clone $cartItem;

		foreach ($packageItem->relatedPackageItems as $relatedPackageItem) {
			$relatedCartItem = $relatedPackageItem->cartItem;

			$relatedCartItem->update(['amount' => $relatedCartItem->amount / $cartItem->amount * $amount]);
		}

		$packageItem->update(['amount' => $amount]);
		$cartItemClone->update(['amount' => $amount]);
	}

	public function removePackageItem(PackageItem|string $packageItem): void
	{
		if (!$packageItem instanceof PackageItem) {
			$packageItem = $this->packageItemRepository->one($packageItem, true);
		}

		$relatedCartItemsToDelete = [];

		foreach ($packageItem->relatedPackageItems as $relatedPackageItem) {
			$relatedCartItemsToDelete[] = $relatedPackageItem->getValue('cartItem');
			$relatedPackageItem->delete();
		}

		$this->relatedCartItemRepository->many()->where('this.uuid', $relatedCartItemsToDelete)->delete();

		$cartItemToDelete = $packageItem->getValue('cartItem');

		$packageItem->delete();

		$this->cartItemRepository->many()->where('this.uuid', $cartItemToDelete)->delete();
	}

	public function removeCartItem(CartItem|string $cartItem): void
	{
		if (!$cartItem instanceof CartItem) {
			$cartItem = $this->cartItemRepository->one($cartItem, true);
		}

		$cartItem->getPackageItems()->delete();
		$cartItem->delete();
	}

	protected function beforeProcess(Order $order, Customer|null $customer = null): bool
	{
		if ($customer) {
			$this->shopperUser->setCustomer($customer);
		} elseif ($order->purchase->customer) {
			$this->shopperUser->setCustomer($order->purchase->customer);
		}

		return true;
	}
}
