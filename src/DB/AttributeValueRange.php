<?php

declare(strict_types=1);

namespace Eshop\DB;

use StORM\RelationCollection;

/**
 * Adresa
 * @table
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
	 * Hodnoty atributu
	 * @relation
	 * @var \StORM\RelationCollection<\Eshop\DB\AttributeValue>|\Eshop\DB\AttributeValue[]
	 */
	public RelationCollection $attributeValues;
}