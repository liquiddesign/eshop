<?php

declare(strict_types=1);

namespace Eshop\DB;

use Admin\DB\Administrator;

/**
 * Adresa
 * @table
 */
class InternalCommentOrder extends \StORM\Entity
{
	/**
	 * Text
	 * @column
	 */
	public ?string $text;

	/**
	 * Administrátor
	 * @column
	 */
	public ?string $adminFullname;

	/**
	 * Vytvořen
	 * @column{"type":"timestamp","default":"CURRENT_TIMESTAMP"}
	 */
	public string $createdTs;

	/**
	 * Administrátor
	 * @relation
	 * @constraint{"onUpdate":"SET NULL","onDelete":"SET NULL"}
	 */
	public ?Administrator $administrator;

	/**
	 * Objednávky
	 * @relation
	 * @constraint{"onUpdate":"CASCADE","onDelete":"CASCADE"}
	 */
	public Order $order;
}
