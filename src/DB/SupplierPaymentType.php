<?php

declare(strict_types=1);

namespace Eshop\DB;

/**
 * Typ dopravy X supplier
 * @table
 * @index{"name":"supplier_payment_type_unique","unique":true,"columns":["fk_paymentType","fk_supplier"]}
 */
class SupplierPaymentType extends \StORM\Entity
{
	/**
	 * Externí ID
	 * @column
	 */
	public string $externalId;

	/**
	 * Platba
	 * @relation
	 * @constraint{"onUpdate":"CASCADE","onDelete":"CASCADE"}
	 */
	public PaymentType $paymentType;

	/**
	 * Supplier
	 * @relation
	 * @constraint{"onUpdate":"CASCADE","onDelete":"CASCADE"}
	 */
	public Supplier $supplier;
}
