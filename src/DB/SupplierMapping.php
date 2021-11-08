<?php

declare(strict_types=1);

namespace Eshop\DB;

/**
 * Mapování
 * @table
 * @index{"name":"supplier_mapping","unique":true,"columns":["type","pattern","fk_supplier"]}
 */
class SupplierMapping extends \StORM\Entity
{
	/**
	 * Typ
	 * @column{"type":"enum","length":"'category','producer','amount','delivery'"}
	 */
	public string $type;
	
	/**
	 * Vzor na mapování
	 * @column
	 */
	public string $pattern;
	
	/**
	 * Mapování výrobce, jestli je zadáno
	 * @relation
	 * @constraint{"onUpdate":"CASCADE","onDelete":"CASCADE"}
	 */
	public ?Producer $producer;
	
	/**
	 * Mapování dostupnost, jestli je zadáno
	 * @relation
	 * @constraint{"onUpdate":"CASCADE","onDelete":"CASCADE"}
	 */
	public ?DisplayAmount $displayAmount;
	
	/**
	 * Mapování doručitelnost, jestli je zadáno
	 * @relation
	 * @constraint{"onUpdate":"CASCADE","onDelete":"CASCADE"}
	 */
	public ?DisplayDelivery $displayDelivery;
	
	/**
	 * Mapování kategorie, jestli je zadáno
	 * @relation
	 * @constraint{"onUpdate":"CASCADE","onDelete":"CASCADE"}
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
