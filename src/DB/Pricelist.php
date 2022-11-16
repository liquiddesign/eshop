<?php

declare(strict_types=1);

namespace Eshop\DB;

use Eshop\Common\DB\SystemicEntity;

/**
 * Ceník
 * @table
 */
class Pricelist extends SystemicEntity
{
	/**
	 * Kód
	 * @column
	 * @unique
	 */
	public ?string $code;
	
	/**
	 * Název
	 * @column
	 */
	public ?string $name;
	
	/**
	 * Je aktivní?
	 * @column
	 */
	public bool $isActive;
	
	/**
	 * Je nákupní?
	 * @column
	 */
	public bool $isPurchase = false;
	
	/**
	 * Povolit slevovou hladinu
	 * @column
	 */
	public bool $allowDiscountLevel = false;

	/**
	 * Platí pouze s kuponem
	 * @column
	 */
	public bool $activeOnlyWithCoupon = false;
	
	/**
	 * Priorita
	 * @column
	 */
	public int $priority = 0;

	/**
	 * Custom label
	 * @column
	 * @unique
	 */
	public ?string $customLabel;
	
	/**
	 * Měna
	 * @relation
	 * @constraint
	 */
	public Currency $currency;
	
	/**
	 * Země DPH
	 * @relation
	 * @constraint
	 */
	public Country $country;
	
	/**
	 * Akce
	 * @relation
	 * @constraint{"onUpdate":"CASCADE","onDelete":"SET NULL"}
	 */
	public ?Discount $discount;
	
	/**
	 * Dodavatel / externí ceník
	 * @relation
	 * @constraint{"onUpdate":"CASCADE","onDelete":"SET NULL"}
	 */
	public ?Supplier $supplier;
}
