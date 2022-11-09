<?php

declare(strict_types=1);

namespace Eshop\DB;

/**
 * Typ reklamace
 * @table
 */
class ComplaintType extends \StORM\Entity
{
	/**
	 * Název
	 * @column{"mutations":true}
	 */
	public ?string $name;
	
	/**
	 * Priorita
	 * @column
	 */
	public int $priority = 10;
}
