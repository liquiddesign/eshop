<?php

declare(strict_types=1);

namespace Eshop\DB;

use StORM\Entity;

/**
 * Minimální odběr
 * @table
 */
class MinimalOrderValue extends Entity
{
	/**
	 * Minimální cena bez DPH včetně
	 * @column
	 */
	public float $price;

	/**
	 * Měna
	 * @relation
	 * @constraint
	 */
	public Currency $currency;

	/**
	 * Skupina uživatel
	 * @relation
	 * @constraint{"onUpdate":"CASCADE","onDelete":"CASCADE"}
	 */
	public ?CustomerGroup $customerGroup;

	/**
	 * Zákazník
	 * @relation
	 * @constraint{"onUpdate":"CASCADE","onDelete":"CASCADE"}
	 */
	public ?Customer $customer;
}
