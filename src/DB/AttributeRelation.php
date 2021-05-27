<?php

declare(strict_types=1);

namespace Eshop\DB;

/**
 * AttributeRelation
 * @table
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