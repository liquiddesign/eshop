<?php

declare(strict_types=1);

namespace Eshop\DB;

/**
 * EHub - Transaction
 * @table
 * @index{"name":"eHubTransaction_order_unique","unique":true,"columns":["fk_order"]}
 * @index{"name":"eHubTransaction_transactionId_unique","unique":true,"columns":["transactionId"]}
 */
class EHubTransaction extends \StORM\Entity
{
	public const STATUS_PENDING = 'pending';
	public const STATUS_APPROVED = 'approved';
	public const STATUS_INVOICED = 'invoiced';
	public const STATUS_PAID = 'paid';
	public const STATUS_DECLINED = 'declined';

	public const STATUSES = [
		self::STATUS_PENDING => 'Čeká',
		self::STATUS_APPROVED => 'Schváleno',
		self::STATUS_INVOICED => 'Vyfakturováno',
		self::STATUS_PAID => 'Zaplaceno',
		self::STATUS_DECLINED => 'Zamítnuto',
	];

	public const STATUSES_TO_UPDATE = [
		self::STATUS_APPROVED => 'Schváleno',
		self::STATUS_DECLINED => 'Zamítnuto',
	];

	/**
	 * @column
	 */
	public string $status;

	/**
	 * @column
	 */
	public string $transactionId;

	/**
	 * @column{"type":"timestamp"}
	 */
	public string $createdTs;

	/**
	 * @column{"type":"timestamp"}
	 */
	public ?string $clickDateTime;

	/**
	 * @column
	 */
	public float $orderAmount;

	/**
	 * @column
	 */
	public ?float $originalOrderAmount;

	/**
	 * @column
	 */
	public ?string $originalCurrency;

	/**
	 * @column
	 */
	public ?float $commission;

	/**
	 * @column
	 */
	public string $type;

	/**
	 * @column
	 */
	public ?string $orderId;

	/**
	 * @column
	 */
	public ?string $couponCode;

	/**
	 * @column
	 */
	public ?bool $newCustomer;

	/**
	 * @relation
	 * @constraint{"onUpdate":"CASCADE","onDelete":"SET NULL"}
	 */
	public ?Order $order;
}
