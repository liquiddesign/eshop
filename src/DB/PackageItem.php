<?php

declare(strict_types=1);

namespace Eshop\DB;

/**
 * Položka balíčku
 * @table
 * @index{"name":"package_item","unique":true,"columns":["fk_delivery","fk_cartItem"]}
 */
class PackageItem extends \StORM\Entity
{
	/**
	 * Počet
	 * @column
	 */
	public int $amount = 0;
	
	/**
	 * Doručení
	 * @constraint{"onUpdate":"CASCADE","onDelete":"CASCADE"}
	 * @relation
	 */
	public Delivery $delivery;
	
	/**
	 * Položka košíku
	 * @constraint{"onUpdate":"CASCADE","onDelete":"CASCADE"}
	 * @relation
	 */
	public CartItem $cartItem;
}