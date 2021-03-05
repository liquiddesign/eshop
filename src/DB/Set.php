<?php

declare(strict_types=1);

namespace Eshop\DB;

/**
 * Set produktu
 * @table
 */
class Set extends \StORM\Entity
{
	/**
	 * @column
	 */
	public float $discountPct;
	
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