<?php

declare(strict_types=1);

namespace Eshop\DB;

/**
 * Zobrazení doručení
 * @table
 */
class DisplayDelivery extends \StORM\Entity
{
	/**
	 * Popisek
	 * @column{"mutations":true}
	 */
	public ?string $label;
	
	/**
	 * Dní od
	 * @column
	 */
	public ?int $daysFrom;
	
	/**
	 * Dní do
	 * @column
	 */
	public ?int $daysTo;
	
	/**
	 * Priorita
	 * @column
	 */
	public int $priority = 10;
}