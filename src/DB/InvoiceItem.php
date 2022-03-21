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
}
