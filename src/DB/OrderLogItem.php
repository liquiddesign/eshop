<?php

declare(strict_types=1);

namespace Eshop\DB;

use Admin\DB\Administrator;

/**
 * Log objednávky
 * @table
 */
class OrderLogItem extends \StORM\Entity
{
	/**
	 * Operace
	 * @column
	 */
	public string $operation;

	/**
	 * Vytvořen
	 * @column{"type":"timestamp","default":"CURRENT_TIMESTAMP"}
	 */
	public string $createdTs;

	/**
	 * Jméno admina
	 * @column
	 */
	public ?string $administratorFullName;

	/**
	 * Objednávka
	 * @relation
	 * @constraint{"onUpdate":"CASCADE","onDelete":"CASCADE"}
	 */
	public Order $order;

	/**
	 * Admin
	 * @relation
	 * @constraint{"onUpdate":"CASCADE","onDelete":"CASCADE"}
	 */
	public ?Administrator $administrator;
}
