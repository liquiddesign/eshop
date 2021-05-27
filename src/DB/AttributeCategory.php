<?php

declare(strict_types=1);

namespace Eshop\DB;

/**
 * AttributeCategory
 * @table
 */
class AttributeCategory extends \StORM\Entity
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
	 * Dodavatel / externí
	 * @relation
	 * @constraint{"onUpdate":"CASCADE","onDelete":"CASCADE"}
	 */
	public ?Supplier $supplier;
}