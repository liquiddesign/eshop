<?php

declare(strict_types=1);

namespace Eshop\DB;

use Security\DB\Account;

/**
 * @table
 * @index{"name":"catalog_permission","unique":true,"columns":["fk_customer","fk_account"]}
 */
class CatalogPermission extends \StORM\Entity
{
	/**
	 * Oprávnění: katalog
	 * @column{"type":"enum","length":"'none','catalog','price'"}
	 */
	public string $catalogPermission = 'price';
	
	/**
	 * Oprávnění: nákup
	 * @column
	 */
	public bool $buyAllowed = true;
	
	/**
	 * Oprávnění: objednávka
	 * @column
	 */
	public bool $orderAllowed = true;
	
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