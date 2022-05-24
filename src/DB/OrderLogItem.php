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
	public const CREATED = 'Vytvořeno';
	public const RECEIVED = 'Přijato';
	public const COMPLETED = 'Odesláno';
	public const CANCELED = 'Stornováno';
	public const PAYED = 'Zaplaceno';
	public const PAYED_CANCELED = 'Zaplacení zrušeno';
	public const PRICE_CHANGED = 'Cena objednávky změněna';
	public const EMAIL_SENT = 'Odeslán email';
	public const EDITED = 'Změna údajů';
	public const ITEM_EDITED = 'Změna údajů položky';
	public const ITEM_DELETED = 'Položka smazána';
	public const ITEM_RESTORED = 'Položka obnovena';
	public const SHIPPED = 'Expedováno';
	public const SHIPPED_CANCELED = 'Expedice zrušena';
	public const BAN = 'Zablokováno';
	public const UN_BAN = 'Odblokováno';
	public const DELIVERY_CHANGED = 'Změna způsobu dopravy';
	public const PAYMENT_CHANGED = 'Změna způsobu platby';
	public const PACKAGE_CHANGED = 'Úprava balíku/ů';
	public const NEW_COMMENT = 'Nový komentář';
	public const NEW_ITEM = 'Nová položka';
	public const SPLIT = 'Rozděleno';
	public const MERGED = 'Spojeno';
	public const CLONED = 'Duplikováno';
	public const SUPPLIER_SENT = 'Odesláno dodavateli';

	/**
	 * Operace
	 * @column
	 */
	public string $operation;

	/**
	 * Doplnující zpráva
	 * @column{"type":"longtext"}
	 */
	public ?string $message;

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
	 * @constraint{"onUpdate":"SET NULL","onDelete":"SET NULL"}
	 */
	public ?Administrator $administrator;
}
