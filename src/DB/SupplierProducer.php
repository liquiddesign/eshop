<?php

declare(strict_types=1);

namespace Eshop\DB;

/**
 * Mapování výrobců
 * @table
 * @index{"name":"supplier_producer_name","unique":true,"columns":["name","fk_supplier"]}
 */
class SupplierProducer extends \StORM\Entity
{
	/**
	 * Vzor na mapování
	 * @column
	 */
	public string $name;
	
	/**
	 * Mapování výrobce, jestli je zadáno
	 * @relation
	 * @constraint{"onUpdate":"CASCADE","onDelete":"SET NULL"}
	 */
	public ?Producer $producer;
	
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
