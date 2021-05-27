<?php

declare(strict_types=1);

namespace Eshop\DB;

/**
 * AttributeValue
 * @table
 */
class AttributeValue extends \StORM\Entity
{
	/**
	 * Kód
	 * @column
	 */
	public string $code;

	/**
	 * Popisek pro front
	 * @column{"mutations":true}
	 */
	public ?string $label;

	/**
	 * Číselná reprezentace
	 * @column
	 */
	public ?float $number;

	/**
	 * @constraint{"onUpdate":"CASCADE","onDelete":"CASCADE"}
	 * @relation
	 */
	public Attribute $attribute;
}