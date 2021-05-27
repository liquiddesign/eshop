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
	 * Dodatečné informace pro front, např.: na otazník
	 * @column{"mutations":true, "type":"longtext"}
	 */
	public ?string $note;

	/**
	 * Dodatečná hodnota, např.: barva
	 * @column
	 */
	public ?string $metaValue;

	/**
	 * Číselná reprezentace
	 * @column
	 */
	public ?float $number;

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

	/**
	 * @constraint{"onUpdate":"CASCADE","onDelete":"CASCADE"}
	 * @relation
	 */
	public Attribute $attribute;
}