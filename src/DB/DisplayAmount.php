<?php

declare(strict_types=1);

namespace Eshop\DB;

/**
 * Zobrazení skladového množství
 * @table
 */
class DisplayAmount extends \StORM\Entity
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
	 * Doprava
	 * @constraint{"onUpdate":"SET NULL","onDelete":"SET NULL"}
	 * @relation
	 */
	public ?DisplayDelivery $displayDelivery;
}