<?php

namespace Eshop\DB;

use Carbon\Carbon;
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

	public function isExpired(Carbon $now): bool
	{
		$validFrom = $this->validFromTs ? Carbon::parse($this->validFromTs) : null;
		$validUntil = $this->validUntilTs ? Carbon::parse($this->validUntilTs) : null;

		if ($validFrom && $now->lt($validFrom)) {
			return true;
		}

		if ($validUntil && $now->gte($validUntil)) {
			return true;
		}

		return false;
	}
}
