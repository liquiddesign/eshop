<?php

declare(strict_types=1);

namespace Eshop\DB;

/**
 * Skupina parametrů
 * @table
 * @index{"name":"value_unique","unique":true,"columns":["fk_product","fk_value"]}
 * @deprecated
 */
class ParameterValue extends \StORM\Entity
{
	/**
	 * Priorita
	 * @column
	 */
	public int $priority = 10;
	
	/**
	 * @constraint{"onUpdate":"CASCADE","onDelete":"CASCADE"}
	 * @relation
	 */
	public Product $product;

	/**
	 * @constraint{"onUpdate":"CASCADE","onDelete":"CASCADE"}
	 * @relation
	 */
	public ParameterAvailableValue $value;
}