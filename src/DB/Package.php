<?php

declare(strict_types=1);

namespace Eshop\DB;

use StORM\RelationCollection;

/**
 * Balíčku
 * @table
 */
class Package extends \StORM\Entity
{
	/**
	 * Číslo balíku
	 * @column
	 */
	public int $id = 1;
	
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
