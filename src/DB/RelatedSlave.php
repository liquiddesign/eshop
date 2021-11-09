<?php

declare(strict_types=1);

namespace Eshop\DB;

/**
 * Produkty ve vztahu
 * @table
 * @index{"name":"related_slave_unique","unique":true,"columns":["fk_product","fk_related","amount","discountPct"]}
 */
class RelatedSlave extends \StORM\Entity
{
	/**
	 * Množství
	 * @column
	 */
	public int $amount = 1;

	/**
	 * Sleva %
	 * @column
	 */
	public float $discountPct = 0;

	/**
	 * @relation
	 * @constraint{"onUpdate":"CASCADE","onDelete":"CASCADE"}
	 */
	public Related $related;

	/**
	 * @relation
	 * @constraint{"onUpdate":"CASCADE","onDelete":"CASCADE"}
	 */
	public Product $product;
}
