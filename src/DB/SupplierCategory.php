<?php

declare(strict_types=1);

namespace Eshop\DB;

/**
 * Mapování kategorií
 * @table
 * @index{"name":"supplier_category_name","unique":true,"columns":["categoryNameL1","categoryNameL2","categoryNameL3","fk_supplier"]}
 */
class SupplierCategory extends \StORM\Entity
{
	/**
	 * Vzor na mapování 1
	 * @column
	 */
	public string $categoryNameL1;
	
	/**
	 * Vzor na mapování 2
	 * @column
	 */
	public ?string $categoryNameL2;
	
	/**
	 * Vzor na mapování 3
	 * @column
	 */
	public ?string $categoryNameL3;
	
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