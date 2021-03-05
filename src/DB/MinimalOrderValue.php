<?php

declare(strict_types=1);

namespace Eshop\DB;

use User\DB\CustomerGroup;

/**
 * Minimální odběr
 * @table
 */
class MinimalOrderValue extends \StORM\Entity
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
	 * @constraint
	 */
	public CustomerGroup $customerGroup;
}