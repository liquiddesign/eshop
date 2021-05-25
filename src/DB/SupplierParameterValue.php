<?php

declare(strict_types=1);

namespace Eshop\DB;

/**
 * Mapování kategorií
 * @table
 * @index{"name":"supplier_parameter_value","unique":true,"columns":["fk_parameter","fk_supplierProduct"]}
 */
class SupplierParameterValue extends \StORM\Entity
{
	/**
	 * Parameter
	 * @relation
	 * @constraint{"onUpdate":"CASCADE","onDelete":"CASCADE"}
	 */
	public Parameter $parameter;
	
	/**
	 * Dodavatelský produckt
	 * @relation
	 * @constraint{"onUpdate":"CASCADE","onDelete":"CASCADE"}
	 */
	public SupplierProduct $supplierProduct;
}