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
	 * Název
	 * @column{"mutations":true}
	 */
	public ?string $name;

	/**
	 * Kód
	 * @column
	 */
	public ?string $code;

	/**
	 * Dodavatel / externí
	 * @relation
	 * @constraint{"onUpdate":"CASCADE","onDelete":"CASCADE"}
	 */
	public ?Supplier $supplier;
}