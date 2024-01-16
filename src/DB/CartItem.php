<?php

declare(strict_types=1);

namespace Eshop\DB;

use DVDoug\BoxPacker;
use StORM\ICollection;
use StORM\RelationCollection;

/**
 * Položka košíku
 * @table
 * @index{"name":"cartitem_item","unique":true,"columns":["fk_product","fk_variant","fk_cart"]}
 * @method \StORM\RelationCollection<\Eshop\DB\RelatedCartItem> getRelatedCartItems()
 */
class CartItem extends \StORM\Entity implements BoxPacker\Item
{
	/**
	 * Název produktu
	 * @column{"mutations":true}
	 */
	public ?string $productName;
	
	/**
	 * Kód produktu
	 * @column
	 */
	public ?string $productCode;
	
	/**
	 * Podkód produktu
	 * @column
	 */
	public ?string $productSubCode;
	
	/**
	 * Váha produktu
	 * @column
	 */
	public ?float $productWeight;
	
	/**
	 * Rozměr produktu
	 * @deprecated Use width, length and depth instead
	 * @column
	 */
	public ?float $productDimension;
	
	/**
	 * Šířka produktu
	 * @column
	 */
	public ?int $productWidth;
	
	/**
	 * Délka produktu
	 * @column
	 */
	public ?int $productLength;
	
	/**
	 * Hloubka produktu
	 * @column
	 */
	public ?int $productDepth;
	
	/**
	 * Produkt naplacato?
	 * @column
	 */
	public ?bool $productKeepFlat;
	
	/**
	 * Název varianty
	 * @column{"mutations":true}
	 */
	public ?string $variantName;
	
	/**
	 * Množství
	 * Pokud je amount > 1, potom priceBefore a priceVatBefore je neplatný!
	 * @column
	 */
	public int $amount;
	
	/**
	 * Dodané množství
	 * @column
	 */
	public ?int $realAmount;
	
	/**
	 * Cena
	 * @column
	 */
	public float $price;
	
	/**
	 * Cena s DPH
	 * @column
	 */
	public ?float $priceVat;
	
	/**
	 * Cena před (pokud je akční)
	 * @column
	 */
	public ?float $priceBefore;
	
	/**
	 * Cena před (pokud je akční) s DPH
	 * @column
	 */
	public ?float $priceVatBefore;
	
	/**
	 * DPH
	 * @column
	 */
	public ?float $vatPct;
	
	/**
	 * Cena
	 * @column
	 */
	public ?int $pts;
	
	/**
	 * Poznámka
	 * @column
	 */
	public ?string $note;
	
	/**
	 * Produkt
	 * @constraint{"onUpdate":"CASCADE","onDelete":"SET NULL"}
	 * @relation
	 */
	public ?Product $product;
	
	/**
	 * Ceník
	 * @constraint{"onUpdate":"SET NULL","onDelete":"SET NULL"}
	 * @relation
	 */
	public ?Pricelist $pricelist;
	
	/**
	 * Varianta
	 * @constraint{"onUpdate":"SET NULL","onDelete":"SET NULL"}
	 * @relation
	 */
	public ?Variant $variant;
	
	/**
	 * Košík
	 * @constraint{"onUpdate":"CASCADE","onDelete":"CASCADE"}
	 * @relation
	 */
	public Cart $cart;
	
	/**
	 * Upsell pro položku
	 * @constraint{"onUpdate":"CASCADE","onDelete":"CASCADE"}
	 * @relation
	 */
	public ?CartItem $upsell;
	
	/**
	 * Related cart items
	 * @relation
	 * @var \StORM\RelationCollection<\Eshop\DB\RelatedCartItem>|\Eshop\DB\RelatedCartItem[]
	 */
	public RelationCollection $relatedCartItems;

	/**
	 * Related cart items
	 * @relation{"targetKey":"fk_upsell"}
	 * @var \StORM\RelationCollection<\Eshop\DB\CartItem>
	 */
	public RelationCollection $upsells;
	
	public function getProduct(): ?Product
	{
		if ($this->product) {
			$this->product->setValue('price', $this->price);
			$this->product->setValue('priceVat', $this->priceVat);
			$this->product->setValue('pricelist', $this->pricelist);
			
			return $this->product;
		}
		
		return null;
	}
	
	public function getPriceSum(): float
	{
		return $this->price * $this->amount;
	}
	
	public function getPriceVatSum(): float
	{
		return $this->priceVat * $this->amount;
	}
	
	public function getPriceBefore(): ?float
	{
		return (float) $this->getValue('priceBefore') > 0 ? (float)$this->getValue('priceBefore') * $this->amount : null;
	}
	
	public function getPriceVatBefore(): ?float
	{
		return (float) $this->getValue('priceVatBefore') > 0 ? (float)$this->getValue('priceVatBefore') * $this->amount : null;
	}
	
	public function getDiscountPercent(): ?float
	{
		if (!$beforePrice = $this->priceBefore) {
			return $this->cart->purchase->customerDiscountLevel;
		}
		
		return 100 - ($this->price / $beforePrice * 100);
	}
	
	public function getDiscountPercentVat(): ?float
	{
		if (!$beforePrice = $this->priceVatBefore) {
			return $this->cart->purchase->customerDiscountLevel;
		}
		
		return 100 - ($this->priceVat / $beforePrice * 100);
	}
	
	public function isAvailable(): bool
	{
		return $this->product && !$this->product->unavailable;
	}
	
	public function getFullCode(): ?string
	{
		//@TODO code-subcode delimeter (tečka) by mel jit nastavit
		return $this->productSubCode ? $this->productCode . '.' . $this->productSubCode : $this->productCode;
	}
	
	public function getDeliveries(): ICollection
	{
		return $this->getConnection()->findRepository(Delivery::class)->many()
			->join(['package' => 'eshop_packageitem'], 'this.uuid=package.fk_delivery')
			->where('package.fk_cartItem', $this->getPK());
	}
	
	/**
	 * Item PK
	 */
	public function getDescription(): string
	{
		return $this->getPK();
	}
	
	/**
	 * Item width in mm.
	 */
	public function getWidth(): int
	{
		return (int) $this->productWidth;
	}
	
	/**
	 * Item length in mm.
	 */
	public function getLength(): int
	{
		return (int) $this->productLength;
	}
	
	/**
	 * Item depth in mm.
	 */
	public function getDepth(): int
	{
		return (int) $this->productLength;
	}
	
	/**
	 * Item weight in g.
	 */
	public function getWeight(): int
	{
		return (int) \round($this->productWeight * 1000);
	}
	
	/**
	 * Does this item need to be kept flat / packed "this way up"?
	 */
	public function getKeepFlat(): bool
	{
		return $this->productKeepFlat ?? false;
	}
}
