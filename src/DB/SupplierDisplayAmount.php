<?php

declare(strict_types=1);

namespace Eshop\DB;

/**
 * Mapování dostupnosti
 * @table
 * @index{"name":"supplier_deliveryamount_name","unique":true,"columns":["name","fk_supplier"]}
 */
class SupplierDisplayAmount extends \StORM\Entity
{
	/**
	 * Vzor na mapování
	 * @column
	 */
	public string $name;

	/**
	 * Skladová zásoba
	 * @column
	 */
	public ?int $storeAmount;
	
	/**
	 * Mapování dostupnosti, jestli je zadáno
	 * @relation
	 * @constraint{"onUpdate":"CASCADE","onDelete":"SET NULL"}
	 */
	public ?DisplayAmount $displayAmount;
	
	/**
	 * Dodavatel
	 * @relation
	 * @constraint{"onUpdate":"CASCADE","onDelete":"CASCADE"}
	 */
	public Supplier $supplier;
	
	/**
	 * Aktualizován
	 * @column{"type":"timestamp","default":"CURRENT_TIMESTAMP","extra":"on update CURRENT_TIMESTAMP"}
	 */
	public string $updateTs;
	
	/**
	 * Vytvořen
	 * @column{"type":"timestamp","default":"CURRENT_TIMESTAMP"}
	 */
	public string $createdTs;
}
