<?php

declare(strict_types=1);

namespace Eshop\DB;

use Eshop\Common\DB\SystemicEntity;
use StORM\RelationCollection;

/**
 * AttributeGroup
 * @table
 */
class AttributeGroup extends SystemicEntity
{
	/**
	 * @column{"mutations":true}
	 */
	public ?string $name;
	
	/**
	 * @column{"mutations":true, "type":"longtext"}
	 */
	public ?string $description;

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
	 * @relationNxN{"sourceViaKey":"fk_attributegroup","targetViaKey":"fk_attribute","via":"eshop_attributegroup_nxn_eshop_attribute"}
	 * @var \StORM\RelationCollection<\Eshop\DB\Attribute>
	 */
	public RelationCollection $attributes;
}
