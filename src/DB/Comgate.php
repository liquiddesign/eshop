<?php

namespace Eshop\DB;

use Eshop\DB\Order;
use StORM\Entity;

/**
 * @table
 */
class Comgate extends Entity
{
	/**
	 * @column
	 */
	public string $transactionId;

	/**
	 * @column
	 */
	public string $status;

	/**
	 * @column
	 */
	public string $refId;

	/**
	 * @column
	 */
	public string $currency;

	/**
	 * @column
	 */
	public float $price;

	/**
	 * @column
	 */
	public bool $test;

	/**
	 * Vytvořen
	 * @column{"type":"timestamp","default":"CURRENT_TIMESTAMP"}
	 */
	public string $createdTs;

	/**
	 * Objednávka
	 * @relation
	 * @constraint
	 */
	public ?Order $order;
}
