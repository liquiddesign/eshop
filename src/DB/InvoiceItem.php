<?php

declare(strict_types=1);

namespace Eshop\DB;

use StORM\RelationCollection;

/**
 * Položka faktury
 * @table
 */
class InvoiceItem extends \StORM\Entity
{
	/**
	 * Název položky
	 * @column
	 */
	public ?string $name;

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
	 * Cena s DPH
	 * @column
	 */
	public ?float $price;

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
	 * Množství
	 * @column
	 */
	public int $amount;

	/**
	 * Proporční množství
	 * @column
	 */
	public ?int $realAmount;

	/**
	 * Sleva zákazníka
	 * @column
	 */
	public ?float $customerDiscountLevel;

	/**
	 * Upsell pro položku
	 * @constraint{"onUpdate":"CASCADE","onDelete":"CASCADE"}
	 * @relation
	 */
	public ?InvoiceItem $upsell;

	/**
	 * @constraint{"onUpdate":"CASCADE","onDelete":"SET NULL"}
	 * @relation
	 */
	public ?Product $product;

	/**
	 * @constraint{"onUpdate":"CASCADE","onDelete":"CASCADE"}
	 * @relation
	 */
	public Invoice $invoice;

	/**
	 * Related invoice items
	 * @relation
	 * @var \StORM\RelationCollection<\Eshop\DB\RelatedInvoiceItem>
	 */
	public RelationCollection $relatedInvoiceItems;

	public function getPriceSum(): float
	{
		return $this->price * $this->amount;
	}

	public function getPriceVatSum(): float
	{
		return $this->priceVat * $this->amount;
	}

	public function getFullCode(): ?string
	{
		return $this->product ? $this->product->getFullCode() : ($this->productSubCode ? $this->productCode . '.' . $this->productSubCode : $this->productCode);
	}

	public function getDiscountLevel(): ?float
	{
		if (!$beforePrice = $this->priceBefore) {
			return $this->customerDiscountLevel;
		}

		return 100 - ($this->price / $beforePrice * 100);
	}

	public function getDiscountLevelVat(): ?float
	{
		if (!$beforePrice = $this->priceVatBefore) {
			return $this->customerDiscountLevel;
		}

		return 100 - ($this->priceVat / $beforePrice * 100);
	}
}
