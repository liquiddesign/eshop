<?php

declare(strict_types=1);

namespace Eshop\DB;

use StORM\RelationCollection;

/**
 * Balíčku
 * @table
 * @method \StORM\RelationCollection<\Eshop\DB\PackageItem> getItems()
 */
class Package extends \StORM\Entity
{
	/**
	 * Číslo balíku
	 * @column
	 */
	public int $id = 1;
	
	/**
	 * Váha
	 * @column
	 */
	public ?float $weight = null;
	
	/**
	 * @relation
	 * @var \StORM\RelationCollection<\Eshop\DB\PackageItem>|\Eshop\DB\PackageItem[]
	 */
	public RelationCollection $items;
	
	/**
	 * Doručení
	 * @constraint{"onUpdate":"CASCADE","onDelete":"CASCADE"}
	 * @relation
	 */
	public Delivery $delivery;
	
	/**
	 * Objednávka
	 * @constraint{"onUpdate":"CASCADE","onDelete":"CASCADE"}
	 * @relation
	 */
	public Order $order;
}
