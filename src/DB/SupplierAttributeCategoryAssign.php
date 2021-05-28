<?php

declare(strict_types=1);

namespace Eshop\DB;

/**
 * Přiřazení dodavatelských atributů na kategorie
 * @table
 * @index{"name":"supplier_attribute_category_assign","unique":true,"columns":["fk_attribute","fk_supplierCategory"]}
 */
class SupplierAttributeCategoryAssign extends \StORM\Entity
{
	/**
	 * Atributu
	 * @relation
	 * @constraint{"onUpdate":"CASCADE","onDelete":"CASCADE"}
	 */
	public Attribute $attribute;
	
	/**
	 * Dodavatelská kategorie
	 * @relation
	 * @constraint{"onUpdate":"CASCADE","onDelete":"CASCADE"}
	 */
	public SupplierCategory $supplierCategory;
}