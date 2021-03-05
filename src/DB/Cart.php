<?php

declare(strict_types=1);

namespace Eshop\DB;

use Eshop\DB\Currency;
use Eshop\DB\DeliveryType;
use Eshop\DB\Supplier;
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
	 * Položky košíku
	 * @relation
	 * @var \StORM\RelationCollection<\Eshop\DB\CartItem>|\Eshop\DB\CartItem[]
	 */
	public RelationCollection $items;
}