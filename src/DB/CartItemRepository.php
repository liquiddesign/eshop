<?php

declare(strict_types=1);

namespace Eshop\DB;

use Eshop\BuyException;
use StORM\Collection;
use StORM\Entity;

/**
 * @extends \StORM\Repository<\Eshop\DB\CartItem>
 */
class CartItemRepository extends \StORM\Repository
{
	public function getSumProperty(array $cartIds, ?string $property): float
	{
		return (float) $this->many()->where('fk_cart', $cartIds)->sum($property === 'amount' ? 'this.amount' : "this.$property * this.amount");
	}
	
	public function getSumItems(Cart $cart): int
	{
		return $this->many()->where('fk_cart', $cart)->count();
	}
	
	public function getItem(Cart $cart, Product $product, ?Variant $variant = null): ?CartItem
	{
		return $this->many()
			->where('fk_cart', $cart)
			->where('fk_product', $product)
			->where('fk_variant', [$variant])
			->first();
	}
	
	public function updateItemAmount(Cart $cart, ?Variant $variant, Product $product, int $amount): int
	{
		return $this->many()
			->where('fk_cart', $cart)
			->where('fk_product', $product)
			->where('fk_variant', [$variant])
			->update(['amount' => $amount, 'price' => $product->getPrice($amount), 'priceVat' => $product->getPriceVat($amount)]);
	}
	
	public function updateNote(Cart $cart, Product $product, ?Variant $variant, ?string $note): int
	{
		return $this->many()
			->where('fk_cart', $cart)
			->where('fk_product', $product)
			->where('fk_variant', [$variant])
			->update(['note' => $note]);
	}
	
	/**
	 * @param array $cartIds
	 * @return \StORM\Collection|\Eshop\DB\CartItem[]
	 */
	public function getItems(array $cartIds): Collection
	{
		return $this->many()->where('fk_cart', $cartIds);
	}
	
	public function deleteItem(Cart $cart, CartItem $item): int
	{
		return $this->many()->where('fk_cart', $cart)->where('this.uuid', $item)->delete();
	}
	
	public function syncItem(Cart $cart, ?CartItem $item, Product $product, ?Variant $variant, int $amount): CartItem
	{
		/** @var \Eshop\DB\VatRateRepository $vatRepo */
		$vatRepo = $this->getConnection()->findRepository(VatRate::class);
		/** @var \Eshop\DB\VatRate $vat */
		$vat = $vatRepo->one($product->vatRate);
		
		$vatPct = $vat ? $vat->rate : 0;
		
		return $this->syncOne([
			'uuid' => $item,
			'productName' => $product->toArray()['name'],
			'productCode' => $product->code,
			'productSubCode' => $product->subCode,
			'productWeight' => $product->weight,
			'variantName' => $variant ? $variant->toArray()['name'] : [],
			'amount' => $amount,
			'price' => $product->getPrice($amount),
			'priceVat' => $product->getPriceVat($amount),
			'vatPct' => (float) $vatPct,
			'product' => $product->getPK(),
			'pricelist' => $product->getValue('pricelist'),
			'variant' => $variant ? $variant->getPK() : null,
			'cart' => $cart->getPK(),
		]);
	}
	
	/**
	 * Vrací další násobek počtu kusů
	 * @param int $amount
	 * @param int $multiple
	 * @return int
	 */
	public function roundUpToNextMultiple(int $amount, int $multiple): int
	{
		return \intVal(\round(($amount + $multiple / 2) / $multiple) * $multiple);
	}
	
	/**
	 * Vrací počet kusů zaokrohlený na balení/karton/paletu
	 * @param int $amount
	 * @param float $prAmount
	 * @param int $multiple
	 * @return int
	 */
	public function roundUpToProductRoundAmount(int $amount, float $prAmount, int $multiple): int
	{
		$nextMultiple = (\ceil($amount) % $multiple === 0) ? \ceil($amount) : \round(($amount + $multiple / 2) / $multiple) * $multiple;
		
		if ($prAmount >= $nextMultiple) {
			$amount = $nextMultiple;
		}
		
		return \intval($amount);
	}
}
