<?php

declare(strict_types=1);

namespace Eshop\DB;

/**
 * Mapování hodnoty atributu
 * @table
 * @index{"name":"supplier_attribute_code_supplier","unique":true,"columns":["code","fk_supplier"]}
 */
class SupplierAttributeValue extends \StORM\Entity
{
	/**
	 * Kód
	 * @column
	 */
	public ?string $code = null;
	
	/**
	 * Popisek pro front
	 * @column
	 */
	public ?string $label = null;
	
	/**
	 * Priorita
	 * @column
	 */
	public int $priority = 10;
	
	/**
	 * Atribut
	 * @relation
	 * @constraint{"onUpdate":"CASCADE","onDelete":"CASCADE"}
	 */
	public ?SupplierAttribute $supplierAttribute;
	
	/**
	 * Mapování atributu, jestli je zadáno
	 * @relation
	 * @constraint{"onUpdate":"CASCADE","onDelete":"SET NULL"}
	 */
	public ?AttributeValue $attributeValue;
	
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