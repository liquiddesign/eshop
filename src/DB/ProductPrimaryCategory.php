<?php

declare(strict_types=1);

namespace Eshop\DB;

use StORM\Entity;

/**
 * Skladové množství
 * @table
 * @index{"name":"productprimarycategory_unique_product","unique":true,"columns":["fk_product","fk_categoryType"]}
 */
class ProductPrimaryCategory extends Entity
{
	/**
	 * @relation
	 * @constraint{"onUpdate":"CASCADE","onDelete":"SET NULL"}
	 */
	public ?Category $category;

	/**
	 * @relation
	 * @constraint{"onUpdate":"CASCADE","onDelete":"CASCADE"}
	 */
	public CategoryType $categoryType;
	
	/**
	 * Produkt
	 * @relation
	 * @constraint{"onUpdate":"CASCADE","onDelete":"CASCADE"}
	 */
	public Product $product;
}
