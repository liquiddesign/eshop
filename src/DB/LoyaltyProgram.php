<?php

declare(strict_types=1);

namespace Eshop\DB;

use StORM\RelationCollection;

/**
 * Věrnostní program
 * @table
 */
class LoyaltyProgram extends \StORM\Entity
{
	/**
	 * Název
	 * @column{"mutations":true}
	 */
	public ?string $name;

	/**
	 * Počíta obrat od
	 * @column{"type":"datetime"}
	 */
	public ?string $turnoverFrom;

	/**
	 * Platnost od
	 * @column{"type":"datetime"}
	 */
	public ?string $validFrom;

	/**
	 * Platnost do
	 * @column{"type":"datetime"}
	 */
	public ?string $validTo;

	/**
	 * Cashback měna
	 * @relation
	 * @constraint{"onUpdate":"SET NULL","onDelete":"SET NULL"}
	 */
	public ?Currency $currency;

	/**
	 * @relation
	 * @var \StORM\RelationCollection<\Eshop\DB\Customer>|\Eshop\DB\Customer[]
	 */
	public RelationCollection $customers;

	/**
	 * @relation
	 * @var \StORM\RelationCollection<\Eshop\DB\LoyaltyProgramDiscountLevel>|\Eshop\DB\LoyaltyProgramDiscountLevel[]
	 */
	public RelationCollection $discountLevels;

	/**
	 * @relation
	 * @var \StORM\RelationCollection<\Eshop\DB\LoyaltyProgramProduct>|\Eshop\DB\LoyaltyProgramProduct[]
	 */
	public RelationCollection $products;

	/**
	 * @relation
	 * @var \StORM\RelationCollection<\Eshop\DB\LoyaltyProgramHistory>|\Eshop\DB\LoyaltyProgramHistory[]
	 */
	public RelationCollection $histories;

	public function isActive(): bool
	{
		return ($this->validFrom === null || \strtotime($this->validFrom) <= \time()) && ($this->validTo === null || \strtotime($this->validTo) >= \time());
	}
}
