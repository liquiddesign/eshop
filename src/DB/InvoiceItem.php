<?php

declare(strict_types=1);

namespace Eshop\DB;

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
	 * Upsell pro položku
	 * @constraint{"onUpdate":"CASCADE","onDelete":"CASCADE"}
	 * @relation
	 */
	public ?InvoiceItem $upsell;
	
	/**
	 * @constraint{"onUpdate":"SET NULL","onDelete":"SET NULL"}
	 * @relation
	 */
	public ?Product $product;
	
	/**
	 * @constraint{"onUpdate":"CASCADE","onDelete":"CASCADE"}
	 * @relation
	 */
	public Invoice $invoice;

	public function getFullCode(): ?string
	{
		return $this->product ? $this->product->getFullCode() : ($this->productSubCode ? $this->productCode . '.' . $this->productSubCode : $this->productCode);
	}
}
