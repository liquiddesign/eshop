<?php

declare(strict_types=1);

namespace Eshop\DB;

/**
 * Přiřazení dodavatelských hodnot atributů
 * @table
 * @index{"name":"supplier_attribute_assign","unique":true,"columns":["fk_attributeValue","fk_supplierProduct"]}
 */
class SupplierAttributeAssign extends \StORM\Entity
{
	/**
	 * Hodnota atributu
	 * @relation
	 * @constraint{"onUpdate":"CASCADE","onDelete":"CASCADE"}
	 */
	public AttributeValue $attributeValue;
	
	/**
	 * Dodavatelský produkt
	 * @relation
	 * @constraint{"onUpdate":"CASCADE","onDelete":"CASCADE"}
	 */
	public SupplierProduct $supplierProduct;
}