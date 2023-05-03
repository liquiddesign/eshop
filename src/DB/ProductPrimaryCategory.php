<?php

declare(strict_types=1);

namespace Eshop\DB;

use Base\Entity\ShopEntity;

/**
 * Skladové množství
 * @table
 * @index{"name":"productprimarycategory_unique_product","unique":true,"columns":["fk_product","fk_shop"]}
 */
class ProductPrimaryCategory extends ShopEntity
{
	/**
	 * @relation
	 * @constraint{"onUpdate":"CASCADE","onDelete":"CASCADE"}
	 */
	public Category $category;
	
	/**
	 * Produkt
	 * @relation
	 * @constraint{"onUpdate":"CASCADE","onDelete":"CASCADE"}
	 */
	public Product $product;
}
