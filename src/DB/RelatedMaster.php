<?php

declare(strict_types=1);

namespace Eshop\DB;

/**
 * Produkty ve vztahu
 * @table
 * @index{"name":"related_master_unique","unique":true,"columns":["fk_product","fk_related","amount"]}
 */
class RelatedMaster extends \StORM\Entity
{
	/**
	 * Množství
	 * @column
	 */
	public int $amount = 1;

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
