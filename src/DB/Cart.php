<?php

declare(strict_types=1);

namespace Eshop\DB;

use Base\Entity\ShopEntity;
use StORM\RelationCollection;

/**
 * Košík
 * @table
 */
class Cart extends ShopEntity
{
	public const EXPIRATION_DAYS = 30;

	/**
	 * Číslo košíku
	 * @column
	 */
	public int $id;
	
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
	public ?string $expirationTs;
	
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
