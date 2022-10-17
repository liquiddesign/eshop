<?php

declare(strict_types=1);

namespace Eshop\DB;

use StORM\RelationCollection;

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

	/**
	 * @relationNxN
	 * @var \StORM\RelationCollection<\Eshop\DB\Pricelist>
	 */
	public RelationCollection $pricelists;
}
