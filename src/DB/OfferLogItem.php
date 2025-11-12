<?php

declare(strict_types=1);

namespace Eshop\DB;

use StORM\Entity;

/**
 * @table{"name":"eshop_offerlogitem"}
 */
class OfferLogItem extends Entity
{
	// State transitions
	public const CREATED = 'created';
	public const SENT = 'sent';
	public const APPROVED = 'approved';
	public const COMPLETED = 'completed';
	public const CANCELED = 'canceled';

	// Manager approval workflow
	public const MANAGER_APPROVAL_REQUESTED = 'managerApprovalRequested';
	public const MANAGER_APPROVED = 'managerApproved';
	public const MANAGER_REJECTED = 'managerRejected';
	public const MANAGER_CHANGED_ITEM = 'managerChangedItem';

	// Editing events
	public const ITEM_ADDED = 'itemAdded';
	public const ITEM_EDITED = 'itemEdited';
	public const ITEM_DELETED = 'itemDeleted';
	public const VALIDITY_CHANGED = 'validityChanged';

	/**
	 * Operations that can be filtered (excluding basic state transitions)
	 */
	public const OPERATIONS_FOR_FILTER = [
		self::MANAGER_APPROVAL_REQUESTED,
		self::MANAGER_APPROVED,
		self::MANAGER_CHANGED_ITEM,
		self::ITEM_ADDED,
		self::ITEM_EDITED,
		self::ITEM_DELETED,
		self::VALIDITY_CHANGED,
	];

	/**
	 * Operace
	 * @column
	 */
	public string $operation;

	/**
	 * Zpráva
	 * @column{"type":"longtext"}
	 */
	public ?string $message;

	/**
	 * Vytvořeno
	 * @column{"type":"timestamp","default":"CURRENT_TIMESTAMP"}
	 */
	public string $createdTs;

	/**
	 * Jméno obchodníka
	 * @column
	 */
	public ?string $merchantFullName;

	/**
	 * Nabídka
	 * @relation
	 * @constraint{"onUpdate":"CASCADE","onDelete":"CASCADE"}
	 */
	public Offer $offer;

	/**
	 * Obchodník
	 * @relation
	 * @constraint{"onUpdate":"SET NULL","onDelete":"SET NULL"}
	 */
	public ?Merchant $merchant;
}
