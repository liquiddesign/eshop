<?php

declare(strict_types=1);

namespace Eshop\DB;

/**
 * AttributeRelation
 * @table
 * @index{"name":"attributeRelation_unique","unique":true,"columns":["fk_product","fk_value"]}
 */
class AttributeRelation extends \StORM\Entity
{
	/**
	 * @constraint{"onUpdate":"CASCADE","onDelete":"CASCADE"}
	 * @relation
	 */
	public Product $product;

	/**
	 * @constraint{"onUpdate":"RESTRICT","onDelete":"RESTRICT"}
	 * @relation
	 */
	public AttributeValue $value;
}