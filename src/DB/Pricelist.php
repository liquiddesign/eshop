<?php

declare(strict_types=1);

namespace Eshop\DB;

/**
 * Ceník
 * @table
 */
class Pricelist extends \StORM\Entity
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
	 * Povolit slevovou hladinu
	 * @column
	 */
	public bool $allowDiscountLevel = false;
	
	/**
	 * Priorita
	 * @column
	 */
	public int $priority = 0;
	
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
	 * @constraint{"onUpdate":"CASCADE","onDelete":"CASCADE"}
	 */
	public ?Discount $discount;
	
	/**
	 * Dodavatel / externí ceník
	 * @relation
	 * @constraint{"onUpdate":"CASCADE","onDelete":"CASCADE"}
	 */
	public ?Supplier $supplier;
	
	/**
	 * Štítek
	 * @relation
	 * @constraint{"onUpdate":"SET NULL","onDelete":"SET NULL"}
	 */
	public ?Ribbon $ribbon;
}
