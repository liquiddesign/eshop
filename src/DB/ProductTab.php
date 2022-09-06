<?php

declare(strict_types=1);

namespace Eshop\DB;

/**
 * Založka produktu
 * @table
 */
class ProductTab extends \StORM\Entity
{
	/**
	 * Název záložky
	 * @column{"mutations":true}
	 */
	public ?string $name;

	/**
	 * Kód záložky
	 * @column
	 */
	public ?string $code;

	/**
	 * Priorita
	 * @column
	 */
	public int $priority = 10;
}
