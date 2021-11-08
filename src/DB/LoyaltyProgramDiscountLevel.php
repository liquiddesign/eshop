<?php

declare(strict_types=1);

namespace Eshop\DB;

/**
 * Slevová hladina věrnostního programu
 * @table
 */
class LoyaltyProgramDiscountLevel extends \StORM\Entity
{
	/**
	 * Obratový práh
	 * @column
	 */
	public float $priceThreshold = 0.0;

	/**
	 * Slevová hladina
	 * @column
	 */
	public int $discountLevel = 0;

	/**
	 * @relation
	 * @constraint{"onUpdate":"CASCADE","onDelete":"CASCADE"}
	 */
	public LoyaltyProgram $loyaltyProgram;
}
