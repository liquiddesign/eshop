<?php

declare(strict_types=1);

namespace Eshop\DB;

/**
 * Položka balíčku
 * @table
 * @index{"name":"package_item","unique":true,"columns":["fk_store","fk_package","fk_cartItem"]}
 */
class PackageItem extends \StORM\Entity
{
	/**
	 * Počet
	 * @column
	 */
	public int $amount = 0;
	
	/**
	 * Smazáno
	 * @column
	 */
	public bool $deleted = false;

	/**
	 * Status
	 * @column{"type":"enum","length":"'waiting','reserved'"}
	 */
	public string $status = 'waiting';
	
	/**
	 * Sklad
	 * @constraint{"onUpdate":"SET NULL","onDelete":"SET NULL"}
	 * @relation
	 */
	public ?Store $store;
	
	/**
	 * Balík
	 * @constraint{"onUpdate":"CASCADE","onDelete":"CASCADE"}
	 * @relation
	 */
	public Package $package;
	
	/**
	 * Položka košíku
	 * @constraint{"onUpdate":"CASCADE","onDelete":"CASCADE"}
	 * @relation
	 */
	public CartItem $cartItem;
}
