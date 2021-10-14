<?php

declare(strict_types=1);

namespace Eshop\DB;

use Security\DB\Account;
use StORM\RelationCollection;

/**
 * Košík
 * @table
 */
class Cart extends \StORM\Entity
{
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
	 * @deprecated
	 */
	public ?Customer $customer;

	/**
	 * Zákazník
	 * @relation
	 * @constraint{"onUpdate":"CASCADE","onDelete":"CASCADE"}
	 * @deprecated
	 */
	public ?Account $account;

	/**
	 * Ztracený košík
	 * @column
	 */
	public bool $lostMark = false;
	
	/**
	 * Položky košíku
	 * @relation
	 * @var \StORM\RelationCollection<\Eshop\DB\CartItem>|\Eshop\DB\CartItem[]
	 */
	public RelationCollection $items;
}