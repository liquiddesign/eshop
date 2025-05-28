<?php

declare(strict_types=1);

namespace Eshop\DB;

use Security\DB\Account;
use StORM\Entity;

/**
 * @table
 * @index{"name":"catalog_permission","unique":true,"columns":["fk_customer","fk_account"]}
 */
class CatalogPermission extends Entity
{
	/**
	 * Oprávnění: katalog
	 * @column{"type":"enum","length":"'none','catalog','price'"}
	 */
	public ?string $catalogPermission = null;

	/**
	 * Oprávnění: nákup
	 * @column
	 */
	public ?bool $buyAllowed = null;

	/**
	 * Oprávnění: objednávka
	 * @column
	 */
	public ?bool $orderAllowed = null;

	/**
	 * Oprávnění: vidět všechny objednávky zákazníka
	 * @column
	 */
	public ?bool $viewAllOrders = null;

	/**
	 * Oprávnění: vidět ceny
	 * @column
	 */
	public ?bool $showPricesWithoutVat = null;

	/**
	 * Oprávnění: vidět ceny s daní
	 * @column
	 */
	public ?bool $showPricesWithVat = null;

	/**
	 * Formát ceny
	 * @column{"type":"enum","length":"'withoutVat','withVat'"}
	 */
	public ?string $priorityPrice = null;

	/**
	 * Zákazník
	 * @constraint{"onUpdate":"CASCADE","onDelete":"CASCADE"}
	 * @relation
	 */
	public Customer $customer;

	/**
	 * Účet
	 * @constraint{"onUpdate":"CASCADE","onDelete":"CASCADE"}
	 * @relation
	 */
	public Account $account;
}
