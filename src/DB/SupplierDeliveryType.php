<?php

declare(strict_types=1);

namespace Eshop\DB;

use Base\Entity\ShopEntity;

/**
 * Typ dopravy X supplier
 * @table
 * @index{"name":"supplier_delivery_type_unique","unique":true,"columns":["fk_deliveryType","fk_supplier","fk_shop"]}
 */
class SupplierDeliveryType extends ShopEntity
{
	/**
	 * Externí ID
	 * @column
	 */
	public string $externalId;

	/**
	 * Doprava
	 * @relation
	 * @constraint{"onUpdate":"CASCADE","onDelete":"CASCADE"}
	 */
	public DeliveryType $deliveryType;

	/**
	 * Supplier
	 * @relation
	 * @constraint{"onUpdate":"CASCADE","onDelete":"CASCADE"}
	 */
	public Supplier $supplier;
}
