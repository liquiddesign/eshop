<?php

declare(strict_types=1);

namespace Eshop\DB;

use StORM\RelationCollection;

/**
 * Adresa
 * @table
 * @property-read string $concatValues
 */
class AttributeValueRange extends \StORM\Entity
{
	/**
	 * Interní název
	 * @column
	 */
	public ?string $internalName;

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
	 * Hodnoty atributu
	 * @relation
	 * @var \StORM\RelationCollection<\Eshop\DB\AttributeValue>
	 */
	public RelationCollection $attributeValues;
}
