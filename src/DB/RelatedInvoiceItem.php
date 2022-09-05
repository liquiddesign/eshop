<?php

declare(strict_types=1);

namespace Eshop\DB;

/**
 * Položka faktury
 * @table
 */
class RelatedInvoiceItem extends \StORM\Entity
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
	 * @constraint{"onUpdate":"SET NULL","onDelete":"SET NULL"}
	 * @relation
	 */
	public ?Product $product;

	/**
	 * @constraint{"onUpdate":"CASCADE","onDelete":"CASCADE"}
	 * @relation
	 */
	public InvoiceItem $invoiceItem;

	public function getPriceSum(): float
	{
		return $this->price * $this->amount;
	}

	public function getPriceVatSum(): float
	{
		return $this->priceVat * $this->amount;
	}

	public function getPriceBeforeSum(): ?float
	{
		return $this->priceBefore ? $this->priceBefore * $this->amount : null;
	}

	public function getPriceVatBeforeSum(): ?float
	{
		return $this->priceVatBefore ? $this->priceVatBefore * $this->amount : null;
	}

	public function getDiscountPriceVatSum(): float
	{
		return ($priceVatBefore = $this->getPriceVatBeforeSum()) ? $priceVatBefore - $this->getPriceVatSum() : 0;
	}

	public function getFullCode(): ?string
	{
		return $this->product ? $this->product->getFullCode() : ($this->productSubCode ? $this->productCode . '.' . $this->productSubCode : $this->productCode);
	}

	public function getDiscountLevel(): ?float
	{
		if (!$beforePrice = $this->priceBefore) {
			return 0;
		}

		return 100 - ($this->price / $beforePrice * 100);
	}

	public function getDiscountLevelVat(): ?float
	{
		if (!$beforePrice = $this->priceVatBefore) {
			return 0;
		}

		return 100 - ($this->priceVat / $beforePrice * 100);
	}
}
