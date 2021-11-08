<?php

declare(strict_types=1);

namespace Eshop\DB;

/**
 * AttributeValue
 * @table
 * @index{"name":"attributeValue_code_unique","unique":true,"columns":["code"]}
 */
class AttributeValue extends \StORM\Entity
{
	/**
	 * Kód
	 * @column
	 * @unique
	 */
	public string $code;

	/**
	 * Interní název
	 * @column
	 */
	public ?string $internalName;

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
	 * Zobrazit v pruvodci
	 * @column
	 */
	public bool $showWizard = true;

	/**
	 * Výchozí hodnota v průvodci
	 * @column{"type":"set","length":"'1','2','3','4'"}
	 */
	public ?string $defaultWizard = null;

	/**
	 * @constraint{"onUpdate":"CASCADE","onDelete":"CASCADE"}
	 * @relation
	 */
	public Attribute $attribute;

	/**
	 * @constraint{"onUpdate":"SET NULL","onDelete":"SET NULL"}
	 * @relation
	 */
	public ?AttributeValueRange $attributeValueRange;

	public function getInternalName(): string
	{
		return $this->internalName ?? $this->label;
	}
}
