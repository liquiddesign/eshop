<?php

declare(strict_types=1);

namespace Eshop\DB;

use Eshop\Common\DB\SystemicEntity;

/**
 * AttributeValue
 * @table
 * @index{"name":"attributeValue_code_unique","unique":true,"columns":["code", "fk_attribute"]}
 */
class AttributeValue extends SystemicEntity
{
	public const IMAGE_DIR = 'attribute_values_images';

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
	 * Číselná reprezentace pro Slider jako rozsah OD
	 * @column
	 */
	public ?float $numberFrom;

	/**
	 * Číselná reprezentace pro Slider jako rozsah DO
	 * @column
	 */
	public ?float $numberTo;

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
	 * Doporučené
	 * @column
	 */
	public bool $recommended = false;

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
	 * Jméno pro Heureku
	 * @column
	 */
	public ?string $heurekaLabel;

	/**
	 * Jméno pro Zboží
	 * @column
	 */
	public ?string $zboziLabel;

	/**
	 * Image filename
	 * @column
	 */
	public ?string $imageFileName;

	/**
	 * Custom field 1
	 * @column{"mutations":true}
	 */
	public ?string $customField1;

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

	/**
	 * ID
	 * column - don't created by auto migration, only by manual
	 */
	public int $id;

	public function getInternalName(): string
	{
		return $this->internalName ?? $this->label;
	}

	public function getSliderValue(): float|null
	{
		return $this->number ?? $this->numberFrom ?? $this->numberTo;
	}
}
