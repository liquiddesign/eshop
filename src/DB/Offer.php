<?php

namespace Eshop\DB;

use StORM\Entity;

/**
 * @table
 * @index{"name":"code","unique":true,"columns":["code"]}
 * @index{"name":"order","unique":true,"columns":["fk_order"]}
 */
class Offer extends Entity
{
	/**
	 * @column
	 */
	public string $code;

	/**
	 * Vytvořena
	 * @column{"type":"timestamp","default":"CURRENT_TIMESTAMP"}
	 */
	public string $createdTs;

	/**
	 * @column{"type":"timestamp"}
	 */
	public string|null $sentTs = null;

	/**
	 * @column{"type":"timestamp"}
	 */
	public string|null $approvedTs = null;

	/**
	 * @column{"type":"timestamp"}
	 */
	public string|null $canceledTs = null;

	/**
	 * @column{"type":"timestamp"}
	 */
	public string|null $validFromTs = null;

	/**
	 * @column{"type":"timestamp"}
	 */
	public string|null $validUntilTs = null;

	/**
	 * @constraint{"onUpdate":"CASCADE","onDelete":"CASCADE"}
	 * @relation
	 */
	public Order $order;
}
