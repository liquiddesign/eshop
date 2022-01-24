<?php

declare(strict_types=1);

namespace Eshop\DB;

/**
 * Set produktu
 * @table
 * @index{"name":"uni","unique":true,"columns":["fk_set","fk_product"]}
 * @deprecated
 */
class Set extends \StORM\Entity
{
	/**
	 * Sleva v rámci setu
	 * @column
	 */
	public float $discountPct = 0;
	
	/**
	 * Množství v setu
	 * @column
	 */
	public int $amount;
	
	/**
	 * Pořadí v setu
	 * @column
	 */
	public int $priority = 1;
	
	/**
	 * @relation
	 * @constraint{"onUpdate":"CASCADE","onDelete":"CASCADE"}
	 */
	public Product $set;
	
	/**
	 * @relation
	 * @constraint{"onUpdate":"CASCADE","onDelete":"CASCADE"}
	 */
	public Product $product;
}
