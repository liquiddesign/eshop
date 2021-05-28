<?php

declare(strict_types=1);

namespace Eshop\DB;

/**
 * AttributeAssign
 * @table
 * @index{"name":"attributeAssign_unique","unique":true,"columns":["fk_product","fk_value"]}
 */
class AttributeAssign extends \StORM\Entity
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