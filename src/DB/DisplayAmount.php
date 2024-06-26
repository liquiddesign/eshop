<?php

declare(strict_types=1);

namespace Eshop\DB;

use Base\Entity\ShopSystemicEntity;

/**
 * Zobrazení skladového množství
 * @table
 */
class DisplayAmount extends ShopSystemicEntity
{
	/**
	 * Popisek
	 * @column{"mutations":true}
	 */
	public ?string $label;
	
	/**
	 * Množství od
	 * @column
	 */
	public ?int $amountFrom;
	
	/**
	 * Množství do
	 * @column
	 */
	public ?int $amountTo;
	
	/**
	 * Vyprodáno
	 * @column
	 */
	public bool $isSold = false;
	
	/**
	 * Priorita
	 * @column
	 */
	public int $priority = 10;

	/**
	 * ID
	 * column - don't created by auto migration, only by manual
	 */
	public int $id;

	/**
	 * Doprava
	 * @constraint{"onUpdate":"SET NULL","onDelete":"SET NULL"}
	 * @relation
	 */
	public ?DisplayDelivery $displayDelivery;
}
