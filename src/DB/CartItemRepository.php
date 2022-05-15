<?php

declare(strict_types=1);

namespace Eshop\DB;

use StORM\Collection;
use StORM\DIConnection;
use StORM\SchemaManager;

/**
 * @extends \StORM\Repository<\Eshop\DB\CartItem>
 */
class CartItemRepository extends \StORM\Repository
{
	private ProductRepository $productRepository;
	
	public function __construct(DIConnection $connection, SchemaManager $schemaManager, ProductRepository $productRepository)
	{
		parent::__construct($connection, $schemaManager);
		
		$this->productRepository = $productRepository;
	}
	
	public function getSumProperty(array $cartIds, ?string $property): float
	{
		return (float) $this->many()->where('fk_cart', $cartIds)->sum($property === 'amount' ? 'this.amount' : "this.$property * this.amount");
	}
	
	public function getSumItems(Cart $cart): int
	{
		return $this->many()->where('fk_cart', $cart)->where('fk_upsell IS NULL')->count();
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
	
	public function getItems(array $cartIds): Collection
	{
		return clone $this->many()->where('fk_cart', $cartIds);
	}
	
	public function deleteItem(Cart $cart, CartItem $item): int
	{
		return $this->many()->where('fk_cart', $cart)->where('this.uuid', $item)->delete();
	}
	
	public function syncItem(Cart $cart, ?CartItem $item, Product $product, ?Variant $variant, int $amount, bool $disabled = false): CartItem
	{
		/** @var \Eshop\DB\VatRateRepository $vatRepo */
		$vatRepo = $this->getConnection()->findRepository(VatRate::class);
		/** @var \Eshop\DB\VatRate|null $vat */
		$vat = $vatRepo->one($product->vatRate);
		
		$vatPct = $vat ? $vat->rate : 0;
		
		return $this->syncOne([
			'uuid' => $item,
			'productName' => $product->toArray()['name'],
			'productCode' => $product->getFullCode(),
			'productSubCode' => $product->subCode,
			'productWeight' => $product->weight,
			'productDimension' => $product->dimension,
			'variantName' => $variant ? $variant->toArray()['name'] : [],
			'amount' => $amount,
			'price' => $product->getPrice($amount),
			'priceVat' => $product->getPriceVat($amount),
			'priceBefore' => $product->getPriceBefore(),
			'priceVatBefore' => $product->getPriceVatBefore(),
			'vatPct' => (float) $vatPct,
			'product' => !$disabled ? $product->getPK() : null,
			'pricelist' => $product->pricelist ?? null,
			'variant' => $variant ? $variant->getPK() : null,
			'cart' => $cart->getPK(),
		]);
	}
	
	/**
	 * Vrací další násobek počtu kusů
	 * @param int $amount
	 * @param int $multiple
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
	 */
	public function roundUpToProductRoundAmount(int $amount, float $prAmount, int $multiple): int
	{
		$nextMultiple = \ceil($amount) % $multiple === 0 ? \ceil($amount) : \round(($amount + $multiple / 2) / $multiple) * $multiple;
		
		if ($prAmount >= $nextMultiple) {
			$amount = $nextMultiple;
		}
		
		return \intval($amount);
	}
	
	public function isUpsellActive($cartItem, $upsell): bool
	{
		/** @var \Eshop\DB\CartItem $cartItem */
		$cartItem = $this->one($cartItem, true);
		
		/** @var \Eshop\DB\Product $upsell */
		$upsell = $this->productRepository->one($upsell);
		
		return (bool)$this->many()->where('this.fk_upsell', $cartItem->getPK())->where('product.uuid', $upsell->getPK())->count() > 0;
	}
	
	public function getUpsell($cartItem, $upsell): ?CartItem
	{
		/** @var \Eshop\DB\CartItem $cartItem */
		$cartItem = $this->one($cartItem, true);
		
		/** @var \Eshop\DB\Product $upsell */
		$upsell = $this->productRepository->one($upsell);
		
		return $this->getUpsellByObjects($cartItem, $upsell);
	}
	
	public function getUpsellByObjects(CartItem $cartItem, Product $upsell): ?CartItem
	{
		return $this->many()->where('this.fk_upsell', $cartItem->getPK())->where('product.uuid', $upsell->getPK())->first();
	}
	
	public function deleteUpsellByObjects(CartItem $cartItem, array $upsellIds): int
	{
		return $this->many()->where('this.fk_upsell', $cartItem->getPK())->where('product.uuid', $upsellIds)->delete();
	}
	
	/**
	 * @param \StORM\Collection<\Eshop\DB\CartItem> $items
	 * @param \Eshop\DB\RelatedType $relatedType
	 * @return \StORM\Collection<\Eshop\DB\CartItem>
	 */
	public function getCartItemsUpsellsByRelatedType(Collection $items, RelatedType $relatedType): Collection
	{
		return $items->where('fk_upsell IS NOT NULL')
			->where('fk_product IS NOT NULL')
			->join(['related' => 'eshop_related'], 'this.fk_product = related.fk_slave')
			->where('related.fk_type', $relatedType->getPK());
	}
}
