<?php

declare(strict_types=1);

namespace Eshop\DB;

use Base\Entity\ShopEntity;

/**
 * Časové prahy dopravců (časy svozů)
 * @table
 */
class DeliveryTypeThreshold extends ShopEntity
{
	/**
	 * Platí do času aktuálního dne
	 * @column
	 */
	public string $time;

	/**
	 * Den v týdnu, kdy platí
	 * @column
	 */
	public bool $monday = true;

	/**
	 * Den v týdnu, kdy platí
	 * @column
	 */
	public bool $tuesday = true;

	/**
	 * Den v týdnu, kdy platí
	 * @column
	 */
	public bool $wednesday = true;

	/**
	 * Den v týdnu, kdy platí
	 * @column
	 */
	public bool $thursday = true;

	/**
	 * Den v týdnu, kdy platí
	 * @column
	 */
	public bool $friday = true;

	/**
	 * Den v týdnu, kdy platí
	 * @column
	 */
	public bool $saturday = false;

	/**
	 * Den v týdnu, kdy platí
	 * @column
	 */
	public bool $sunday = false;

	/**
	 * Výdejní typ
	 * @relation
	 * @constraint{"onUpdate":"CASCADE","onDelete":"CASCADE"}
	 */
	public DeliveryType $deliveryType;
}
