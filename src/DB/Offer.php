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
	 * Požádáno o schválení managerem
	 * @column{"type":"timestamp"}
	 */
	public string|null $managerApprovalRequestedTs = null;

	/**
	 * Schváleno managerem
	 * @column{"type":"timestamp"}
	 */
	public string|null $managerApprovedTs = null;

	/**
	 * Poznámka
	 * @column{"type":"text"}
	 */
	public ?string $internalNote;

	/**
	 * Poznámka obchodníka pro zákazníka
	 * @column{"type":"longtext"}
	 */
	public string|null $note = null;

	/**
	 * Id v pipedrive
	 * @column
	 */
	public string|null $pipedriveDealId = null;

	/**
	 * Zaokrouhlování
	 * @column
	 */
	public float|null $roundingTo = null;

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

		return $validUntil && $now->gte($validUntil);
	}
}
