<?php

declare(strict_types=1);

namespace Eshop\DB;

/**
 * Skladové množství
 * @table
 * @index{"name":"amount_unique_product_store","unique":true,"columns":["fk_product","fk_store"]}
 */
class Amount extends \StORM\Entity
{
	/**
	 * Naskladněno
	 * @column
	 */
	public int $inStock;
	
	/**
	 * Rezervováno
	 * @column
	 */
	public ?int $reserved;
	
	/**
	 * Objednáno
	 * @column
	 */
	public ?int $ordered;
	
	/**
	 * Produkt
	 * @relation
	 * @constraint{"onUpdate":"CASCADE","onDelete":"CASCADE"}
	 */
	public Product $product;
	
	/**
	 * Sklad
	 * @relation
	 * @constraint{"onUpdate":"CASCADE","onDelete":"CASCADE"}
	 */
	public Store $store;
}
