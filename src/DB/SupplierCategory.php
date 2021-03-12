<?php

declare(strict_types=1);

namespace Eshop\DB;

/**
 * Mapování kategorií
 * @table
 * @index{"name":"supplier_category_name","unique":true,"columns":["name","fk_supplier"]}
 */
class SupplierCategory extends \StORM\Entity
{
	/**
	 * Vzor na mapování
	 * @column
	 */
	public string $name;
	
	/**
	 * Mapování kategorií, jestli je zadáno
	 * @relation
	 * @constraint{"onUpdate":"CASCADE","onDelete":"SET NULL"}
	 */
	public ?Category $category;
	
	/**
	 * Dodavatel
	 * @relation
	 * @constraint{"onUpdate":"CASCADE","onDelete":"CASCADE"}
	 */
	public Supplier $supplier;
	
	/**
	 * Vytvořen
	 * @column{"type":"timestamp","default":"CURRENT_TIMESTAMP"}
	 */
	public string $createdTs;
}