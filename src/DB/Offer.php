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
	public const STATE_OPEN = 'open';
	public const STATE_RECEIVED = 'received';
	public const STATE_COMPLETED = 'finished';
	public const STATE_CANCELED = 'canceled';

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
	public string|null $approvedTs = null;

	/**
	 * @column{"type":"timestamp"}
	 */
	public string|null $completedTs = null;

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

	public function getState(): string
	{
		if ($this->approvedTs === null && $this->completedTs === null && $this->canceledTs === null) {
			return self::STATE_OPEN;
		}

		if ($this->approvedTs !== null && $this->completedTs === null && $this->canceledTs === null) {
			return self::STATE_RECEIVED;
		}

		if ($this->approvedTs !== null && $this->completedTs !== null && $this->canceledTs === null) {
			return self::STATE_COMPLETED;
		}

		if ($this->canceledTs !== null) {
			return self::STATE_CANCELED;
		}

		throw new \RuntimeException('Unknown offer state');
	}

	/**
	 * @return array<string>
	 */
	public static function getAvailableStates(): array
	{
		return [
			self::STATE_OPEN,
			self::STATE_CANCELED,
			self::STATE_COMPLETED,
			self::STATE_RECEIVED,
		];
	}
}
