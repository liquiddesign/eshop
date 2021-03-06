<?php

declare(strict_types=1);

namespace Eshop\DB;

/**
 * Kategorie parametrů
 * @table
 */
class ParameterCategory extends \StORM\Entity
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
	
	/**
	 * Skryto
	 * @column
	 */
	public bool $hidden = false;
}