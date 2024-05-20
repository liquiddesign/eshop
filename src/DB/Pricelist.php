<?php

declare(strict_types=1);

namespace Eshop\DB;

use Base\Entity\ShopSystemicEntity;
use StORM\RelationCollection;

/**
 * Ceník
 * @table
 */
class Pricelist extends ShopSystemicEntity
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
	 * Popis
	 * @column
	 */
	public ?string $description;
	
	/**
	 * Je aktivní?
	 * @column
	 */
	public bool $isActive;

	/**
	 * Ceny se nastavují jen importy/programově
	 * @column
	 */
	public bool $isReadonly = false;
	
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

	/**
	 * ID
	 * column - don't created by auto migration, only by manual
	 */
	public int $id;

	/**
	 * @column{"type":"datetime"}
	 */
	public ?string $lastUpdateTs;

	/**
	 * @relationNxN
	 * @var \StORM\RelationCollection<\Eshop\DB\InternalRibbon>
	 */
	public RelationCollection $internalRibbons;
}
