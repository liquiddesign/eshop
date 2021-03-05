<?php

declare(strict_types=1);

namespace Eshop\DB;

/**
 * Sklad
 * @table
 */
class Store extends \StORM\Entity
{
	/**
	 * Kód
	 * @column
	 */
	public ?string $code;
	
	/**
	 * Název
	 * @column{"mutations":true}
	 */
	public ?string $name;
	
	/**
	 * Dodavatel / externí sklad
	 * @relation
	 * @constraint{"onUpdate":"CASCADE","onDelete":"CASCADE"}
	 */
	public ?Supplier $supplier;
}
