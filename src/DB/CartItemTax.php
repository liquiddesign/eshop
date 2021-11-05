<?php

declare(strict_types=1);

namespace Eshop\DB;

/**
 * Poplatky a daně
 * @table
 */
class CartItemTax extends \StORM\Entity
{
	/**
	 * Název
	 * @column{"mutations":true}
	 */
	public ?string $name;

	/**
	 * Cena
	 * @column
	 */
	public ?float $price;

	/**
	 * Položka košíku
	 * @relation
	 */
	public CartItem $cartItem;
}
