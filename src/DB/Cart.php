<?php

declare(strict_types=1);

namespace Eshop\DB;

use Base\Entity\ShopEntity;
use StORM\RelationCollection;

/**
 * Košík
 * @table
 * @index{"name":"cart_id","columns":["id","fk_customer","closedTs"]
 * @method \StORM\RelationCollection<\Eshop\DB\CartItem> getItems()
 */
class Cart extends ShopEntity
{
	public const EXPIRATION_DAYS = 30;

	/**
	 * Číslo košíku
	 * @column
	 */
	public string $id;
	
	/**
	 * Hash nepřipojeného košíku
	 * @column
	 */
	public ?string $cartToken = null;
	
	/**
	 * Aktivní košík
	 * @column
	 */
	public bool $active;

	/**
	 * Schválený košík
	 * @column{"type":"enum","length":"'waiting','no','yes'"}
	 */
	public string $approved = 'waiting';
	
	/**
	 * Vytvořen
	 * @column{"type":"timestamp","default":"CURRENT_TIMESTAMP"}
	 */
	public string $createdTs;
	
	/**
	 * Expiruje
	 * @column{"type":"timestamp"}
	 */
	public ?string $expirationTs = null;
	
	/**
	 * Uzavřený
	 * @column{"type":"timestamp"}
	 */
	public ?string $closedTs = null;
	
	/**
	 * Měna
	 * @relation
	 * @constraint
	 */
	public Currency $currency;
	
	/**
	 * Požadovaný typ dopravy
	 * @relation
	 * @constraint
	 */
	public ?DeliveryType $delivery;
	
	/**
	 * Nákup
	 * @relation
	 * @constraint{"onUpdate":"CASCADE","onDelete":"CASCADE"}
	 */
	public ?Purchase $purchase;
	
	/**
	 * Zákazník
	 * @relation
	 * @constraint{"onUpdate":"CASCADE","onDelete":"CASCADE"}
	 */
	public ?Customer $customer;

	/**
	 * Ztracený košík
	 * @column
	 */
	public bool $lostMark = false;
	
	/**
	 * Položky košíku
	 * @relation
	 * @var \StORM\RelationCollection<\Eshop\DB\CartItem>
	 */
	public RelationCollection $items;
}
