<?php

declare(strict_types=1);

namespace Eshop\DB;

/**
 * Stav reklamace
 * @table
 */
class ComplaintState extends \StORM\Entity
{
	/**
	 * Název
	 * @column{"mutations":true}
	 */
	public ?string $name;
	
	/**
	 * Pořadí
	 * @column
	 */
	public int $sequence = 0;
}