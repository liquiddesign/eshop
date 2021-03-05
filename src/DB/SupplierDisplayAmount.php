<?php

declare(strict_types=1);

namespace Eshop\DB;

/**
 * Mapování dostupnosti
 * @table
 */
class SupplierDisplayAmount extends \StORM\Entity
{
	/**
	 * Vzor na mapování
	 * @column{"unique":true}
	 */
	public string $name;
	
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
	 * Vytvořen
	 * @column{"type":"timestamp","default":"CURRENT_TIMESTAMP"}
	 */
	public string $createdTs;
}