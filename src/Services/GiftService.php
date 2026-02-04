<?php

declare(strict_types=1);

namespace Eshop\Services;

use Base\Bridges\AutoWireService;
use Eshop\DB\Cart;
use Eshop\DB\CartItem;
use Eshop\DB\CartItemRepository;
use Eshop\DB\GiftRuleProductRepository;
use Eshop\DB\GiftRuleRepository;
use Eshop\DB\Product;
use Eshop\DB\ProductRepository;

class GiftService implements AutoWireService
{
	public const GIFT_TYPE_GIFT = 'gift';
	public const GIFT_TYPE_DISCOUNT = 'gift_discount';
	public const GIFT_PRODUCT_PREFIX = 'DÁREK: ';
	public const GIFT_DISCOUNT_PRODUCT_UUID = 'gift-discount-product';
	public const GIFT_PRICE = 1.0;

	public function __construct(
		private readonly GiftRuleRepository $giftRuleRepository,
		private readonly GiftRuleProductRepository $giftRuleProductRepository,
		private readonly CartItemRepository $cartItemRepository,
		private readonly ProductRepository $productRepository,
	) {
	}

	/**
	 * Získá dostupné dárky pro danou hodnotu košíku
	 * @return array<\Eshop\DB\Product>
	 */
	public function getAvailableGifts(Cart $cart): array
	{
		$orderPrice = $this->getCartTotalPrice($cart);
		$currency = $cart->currency;

		$rule = $this->giftRuleRepository->getActiveRuleForPrice($orderPrice, $currency);

		if ($rule === null) {
			return [];
		}

		$gifts = [];

		foreach ($this->giftRuleProductRepository->getProductsForRuleWithProducts($rule) as $ruleProduct) {
			$gifts[] = $ruleProduct->product;
		}

		return $gifts;
	}

	/**
	 * Získá aktuální pravidlo pro košík
	 */
	public function getActiveRuleForCart(Cart $cart): \Eshop\DB\GiftRule|null
	{
		$orderPrice = $this->getCartTotalPrice($cart);
		$shop = $cart->shop;
		$customerGroup = $cart->customer?->group;

		return $this->giftRuleRepository->getActiveRuleForPrice(
			$orderPrice,
			$cart->currency,
			$shop,
			$customerGroup,
		);
	}

	/**
	 * Získá celkovou cenu košíku (bez položek dárků)
	 */
	public function getCartTotalPrice(Cart $cart): float
	{
		$total = 0.0;

		foreach ($cart->items as $item) {
			// Nepočítáme položky dárků do celkové ceny
			if ($item->giftType !== null) {
				continue;
			}

			$total += $item->getPriceSum();
		}

		return $total;
	}

	/**
	 * Přidá dárek do košíku s cenou 1 Kč a kompenzační slevou -1 Kč
	 */
	public function addGiftToCart(Cart $cart, Product $product): void
	{
		// Nejprve odstraníme stávající dárek, pokud existuje
		$this->removeGiftFromCart($cart);

		// Připravíme názvy s prefixem pro všechny mutace
		$productNames = $product->toArray()['name'];
		$productNames = \array_filter($productNames, function (?string $name): bool {return $name !== null;});

		$prefixedNames = \array_map(
			fn(string $name): string => self::GIFT_PRODUCT_PREFIX . $name,
			$productNames,
		);

		// Vytvoříme položku dárku s cenou 1 Kč (DPH 0%)
		$giftItem = $this->cartItemRepository->createOne([
			'productName' => $prefixedNames,
			'productCode' => $product->code,
			'productSubCode' => $product->subCode,
			'productWeight' => $product->weight,
			'productEan' => $product->ean,
			'amount' => 1,
			'price' => self::GIFT_PRICE,
			'priceVat' => self::GIFT_PRICE,
			'vatPct' => 0.0,
			'product' => $product->getPK(),
			'cart' => $cart->getPK(),
			'giftType' => self::GIFT_TYPE_GIFT,
		]);

		// Vytvoříme kompenzační slevovou položku -1 Kč (DPH 0%)
		// Použijeme skutečný produkt "Sleva na dárek" z databáze
		$discountProduct = $this->productRepository->one(self::GIFT_DISCOUNT_PRODUCT_UUID);

		$discountProductNames = $discountProduct !== null
			? $discountProduct->toArray()['name']
			: ['cs' => 'Sleva na dárek', 'en' => 'Gift discount'];

		$discountItem = $this->cartItemRepository->createOne([
			'productName' => $discountProductNames,
			'productCode' => $discountProduct?->code ?? 'GIFT-DISCOUNT',
			'amount' => 1,
			'price' => -self::GIFT_PRICE,
			'priceVat' => -self::GIFT_PRICE,
			'vatPct' => 0.0,
			'product' => $discountProduct?->getPK(),
			'cart' => $cart->getPK(),
			'linkedGiftItem' => $giftItem->getPK(),
			'giftType' => self::GIFT_TYPE_DISCOUNT,
		]);

		// Propojíme dárek se slevou
		$this->cartItemRepository->many()
			->where('this.uuid', $giftItem->getPK())
			->update(['fk_linkedGiftItem' => $discountItem->getPK()]);
	}

	/**
	 * Odebere dárek z košíku včetně kompenzační slevy
	 */
	public function removeGiftFromCart(Cart $cart): void
	{
		$this->cartItemRepository->many()
			->where('this.fk_cart', $cart->getPK())
			->where('this.giftType IS NOT NULL')
			->delete();
	}

	/**
	 * Získá aktuálně vybraný dárek v košíku
	 */
	public function getSelectedGift(Cart $cart): CartItem|null
	{
		return $this->cartItemRepository->many()
			->where('this.fk_cart', $cart->getPK())
			->where('this.giftType', self::GIFT_TYPE_GIFT)
			->first();
	}

	/**
	 * Validuje zda je vybraný dárek stále platný pro aktuální hodnotu košíku
	 */
	public function validateSelectedGift(Cart $cart): bool
	{
		$selectedGift = $this->getSelectedGift($cart);

		if ($selectedGift === null) {
			// Není vybrán žádný dárek, vše OK
			return true;
		}

		$availableGifts = $this->getAvailableGifts($cart);

		if ($availableGifts === []) {
			// Hodnota košíku klesla pod minimum - dárek už neplatí
			return false;
		}

		// Zkontrolujeme, zda je vybraný produkt stále mezi dostupnými
		$selectedProductPk = $selectedGift->getValue('product');

		foreach ($availableGifts as $gift) {
			if ($gift->getPK() === $selectedProductPk) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Pokud je vybraný dárek neplatný, odstraní ho z košíku
	 */
	public function revalidateGift(Cart $cart): bool
	{
		if (!$this->validateSelectedGift($cart)) {
			$this->removeGiftFromCart($cart);

			return false;
		}

		return true;
	}

	/**
	 * Zkontroluje, zda zákazník může vybírat dárky
	 */
	public function canSelectGift(Cart $cart): bool
	{
		$customer = $cart->customer;

		if ($customer === null) {
			return false;
		}

		return $customer->allowOrderGift;
	}
}
