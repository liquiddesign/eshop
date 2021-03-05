<?php

declare(strict_types=1);

namespace Eshop\DB;

use Eshop\DB\Currency;
use Eshop\DB\PaymentType;

/**
 * Pohyb kreditů
 * @table
 */
class PointMove extends \StORM\Entity
{
	/**
	 * @column
	 */
	public ?string $reason;
	
	/**
	 * @column
	 */
	public int $points;
	
	/**
	 * @relation
	 * @constraint{"onUpdate":"CASCADE","onDelete":"CASCADE"}
	 */
	public Customer $customer;
}