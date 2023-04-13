<?php

namespace Eshop;

use Eshop\Common\CheckInvalidAmount;
use Eshop\DB\CartItem;
use Eshop\DB\Product;
use Eshop\DB\Variant;

class CartManager
{
	public function __construct(private readonly ShopperUser $shopperUser,)
	{
	}

	/**
	 * @param \Eshop\DB\Product $product Must have set prices
	 * @param \Eshop\DB\Variant|null $variant
	 * @param int $amount
	 * @param ?bool $replaceMode true - replace | false - add or update | null - only add
	 * @param \Eshop\Common\CheckInvalidAmount $checkInvalidAmount
	 * @param ?bool $checkCanBuy
	 * @param \Eshop\DB\CartItem|null $upsell
	 * @throws \Eshop\BuyException
	 */
	public function addItemToCart(
		Product $product,
		?Variant $variant = null,
		int $amount = 1,
		?bool $replaceMode = false,
		CheckInvalidAmount $checkInvalidAmount = CheckInvalidAmount::CHECK_THROW,
		?bool $checkCanBuy = true,
		?CartItem $upsell = null
	): CartItem {
		if (!$this->checkCurrency($product)) {
			throw new BuyException('Invalid currency', BuyException::INVALID_CURRENCY);
		}

		if ($checkCanBuy !== false && !$this->shopperUser->getBuyPermission()) {
			throw new BuyException('Permission denied', BuyException::PERMISSION_DENIED);
		}

		$disabled = false;

		if ($checkCanBuy !== false && !$this->canBuyProduct($product)) {
			if ($checkCanBuy === true) {
				throw new BuyException('Product is not for sell', BuyException::NOT_FOR_SELL);
			}

			$disabled = true;
		}

		if (\is_null($checkInvalidAmount) || \is_bool($checkInvalidAmount)) {
			if ($checkInvalidAmount !== false && !$this->checkAmount($product, $amount)) {
				if ($checkInvalidAmount === true) {
					throw new BuyException("Invalid amount: $amount", BuyException::INVALID_AMOUNT);
				}

				$disabled = true;
			}
		} elseif (\is_int($checkInvalidAmount)) {
			if ($checkInvalidAmount === CheckInvalidAmount::SET_DEFAULT_AMOUNT) {
				$amount = $this->checkAmount($product, $amount) ? $amount : $product->defaultBuyCount;
			}
		}

		if ($replaceMode !== null && $item = $this->itemRepository->getItem($cart ?? $this->getCart(), $product, $variant)) {
			$this->changeItemAmount($product, $variant, $replaceMode ? $amount : $item->amount + $amount, $checkInvalidAmount, $cart);

			if ($this->onCartItemCreate) {
				$this->onCartItemCreate($item);
			}

			return $item;
		}

		$cartItem = $this->itemRepository->syncItem($cart ?? $this->getCart(), null, $product, $variant, $amount, $disabled);

		if ($upsell) {
			$cartItem->update(['upsell' => $upsell->getPK(),]);
		}

		if ($currency = $this->getCartCurrency()) {
			$taxes = $this->taxRepository->getTaxesForProduct($product, $currency);

			foreach ($taxes as $tax) {
				$tax = $tax->toArray();
				$this->cartItemTaxRepository->createOne([
					'name' => $tax['name'],
					'price' => $tax['price'],
					'cartItem' => $cartItem->getPK(),
				], null);
			}
		}

		$this->refreshSumProperties();

		if ($this->onCartItemCreate) {
			$this->onCartItemCreate($cartItem);
		}

		return $cartItem;
	}

	private function checkCurrency(Product $product): bool
	{
		return $product->getValue('currencyCode') === $this->getCart()->currency->code;
	}
}
