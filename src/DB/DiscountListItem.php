<?php

namespace Eshop\DB;

use StORM\Entity;

/**
 * Stav dopravy objednávky
 * @table
 * @index{"name":"discountlistitem_product_unique","unique":true,"columns":["fk_product", "fk_discountList"]}
 */
class DiscountListItem extends Entity
{
	/**
	 * Skryto
	 * @column
	 */
	public int $discountPct;

	/**
	 * @relation
	 * @constraint{"onUpdate":"CASCADE","onDelete":"CASCADE"}
	 */
	public Product $product;

	/**
	 * @relation
	 * @constraint{"onUpdate":"CASCADE","onDelete":"CASCADE"}
	 */
	public DiscountList $discountList;
}