<?php

declare(strict_types=1);

namespace Eshop\DB;

/**
 * Věrnostní program historie
 * @table
 */
class LoyaltyProgramHistory extends \StORM\Entity
{
	/**
	 * Počet získaných/ztracených bodů "bodů" při operaci
	 * @column
	 */
	public float $points;

	/**
	 * Vytvořen
	 * @column{"type":"timestamp","default":"CURRENT_TIMESTAMP"}
	 */
	public string $createdTs;

	/**
	 * Zákazník
	 * @relation
	 * @constraint{"onUpdate":"CASCADE","onDelete":"CASCADE"}
	 */
	public Customer $customer;

	/**
	 * Produkt
	 * @relation
	 * @constraint{"onUpdate":"SET NULL","onDelete":"SET NULL"}
	 */
	public ?LoyaltyProgramProduct $loyaltyProgramProduct;

	/**
	 * Program
	 * @relation
	 * @constraint{"onUpdate":"CASCADE","onDelete":"CASCADE"}
	 */
	public LoyaltyProgram $loyaltyProgram;
}
