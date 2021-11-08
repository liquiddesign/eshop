<?php

declare(strict_types=1);

namespace Eshop\DB;

/**
 * Slevova dopravu
 * @table
 */
class DeliveryDiscount extends \StORM\Entity
{
	/**
	 * Sleva na dopravu v měně
	 * @column
	 */
	public ?float $discountValue;
	
	/**
	 * Sleva na dopravu v měně s DPH
	 * @column
	 */
	public ?float $discountValueVat;
	
	/**
	 * Sleva na dopravu
	 * @column
	 */
	public ?float $discountPct;
	
	/**
	 * Od jaké ceny košíku je sleva
	 * @column
	 */
	public float $discountPriceFrom = 0.0;
	
	/**
	 * Měna
	 * @relation
	 * @constraint
	 */
	public Currency $currency;
	
	/**
	 * Akce
	 * @relation
	 * @constraint{"onUpdate":"CASCADE","onDelete":"CASCADE"}
	 */
	public Discount $discount;
}
