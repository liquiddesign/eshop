<?php

declare(strict_types=1);

namespace Eshop\DB;

/**
 * Skupina parametrů
 * @table
 */
class ParameterValue extends \StORM\Entity
{
	/**
	 * Hodnota přetypová string
	 * @column{"mutations":true}
	 */
	public ?string $content;
	
	/**
	 * Doprovodná hodnota, napřiklad barva pro zobrazení, css třída apod.
	 * @column
	 */
	public ?string $metaValue;

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
	public Parameter $parameter;
}