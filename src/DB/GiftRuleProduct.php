<?php

declare(strict_types=1);

namespace Eshop\DB;

use StORM\Entity;

/**
 * Produkt v pravidle dárku
 * @table
 */
class GiftRuleProduct extends Entity
{
	/**
	 * Pravidlo dárku
	 * @relation
	 * @constraint{"onUpdate":"CASCADE","onDelete":"CASCADE"}
	 */
	public GiftRule $giftRule;

	/**
	 * Produkt
	 * @relation
	 * @constraint{"onUpdate":"CASCADE","onDelete":"CASCADE"}
	 */
	public Product $product;

	/**
	 * Priorita (nižší = vyšší priorita)
	 * @column
	 */
	public int $priority = 0;
}
