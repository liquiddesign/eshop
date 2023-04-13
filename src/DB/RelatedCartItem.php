<?php

declare(strict_types=1);

namespace Eshop\DB;

/**
 * Položka setu košíku
 * @table
 */
class RelatedCartItem extends \StORM\Entity
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
	 * @column
	 */
	public ?float $productDimension;
	
	/**
	 * Název varianty
	 * @column{"mutations":true}
	 */
	public ?string $variantName;
	
	/**
	 * Množství
	 * @column
	 */
	public int $amount;
	
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
	 * Related type name
	 * @column
	 */
	public string $relatedTypeName;

	/**
	 * Related type code
	 * @column
	 */
	public string $relatedTypeCode;

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
	 * Main item
	 * @constraint{"onUpdate":"CASCADE","onDelete":"CASCADE"}
	 * @relation
	 */
	public CartItem $cartItem;

	/**
	 * Related type
	 * @constraint{"onUpdate":"SET NULL","onDelete":"SET NULL"}
	 * @relation
	 */
	public ?RelatedType $relatedType;
	
	public function getProduct(): ?Product
	{
		if ($this->product) {
			$this->product->setValue('price', $this->price);
			$this->product->setValue('priceVat', $this->priceVat);
			
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

	public function getPriceSumBefore(): ?float
	{
		return (float) $this->getValue('priceBefore') > 0 ? (float) $this->getValue('priceBefore') * $this->amount : null;
	}

	public function getPriceVatSumBefore(): ?float
	{
		return (float) $this->getValue('priceVatBefore') > 0 ? (float) $this->getValue('priceVatBefore') * $this->amount : null;
	}
	
	public function getFullCode(): ?string
	{
		//@TODO code-subcode delimeter (tečka) by mel jit nastavit
		return $this->productSubCode ? $this->productCode . '.' . $this->productSubCode : $this->productCode;
	}
}
