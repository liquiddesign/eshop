<?php

declare(strict_types=1);

namespace Eshop\DB;

/**
 * Mapování kategorií
 * @table
 */
class SupplierCategory extends \StORM\Entity
{
	/**
	 * Vzor na mapování
	 * @column{"unique":true}
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