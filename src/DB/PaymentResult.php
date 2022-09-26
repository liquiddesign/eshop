<?php

namespace Eshop\DB;

use StORM\Entity;

/**
 * @table
 */
class PaymentResult extends Entity
{
	/**
	 * @column
	 */
	public string $id;

	/**
	 * @column
	 */
	public string $status;

	/**
	 * Service type: "comgate", "goPay"
	 * @column
	 */
	public string $service;

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
	 * @constraint{"onUpdate":"CASCADE","onDelete":"CASCADE"}
	 */
	public ?Order $order;
}
