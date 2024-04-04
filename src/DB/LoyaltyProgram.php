<?php

declare(strict_types=1);

namespace Eshop\DB;

use Carbon\Carbon;
use Eshop\Common\DB\SystemicEntity;
use StORM\RelationCollection;

/**
 * Věrnostní program
 * @table
 */
class LoyaltyProgram extends SystemicEntity
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
	 * @var \StORM\RelationCollection<\Eshop\DB\Customer>
	 */
	public RelationCollection $customers;

	/**
	 * @relation
	 * @var \StORM\RelationCollection<\Eshop\DB\LoyaltyProgramDiscountLevel>
	 */
	public RelationCollection $discountLevels;

	/**
	 * @relation
	 * @var \StORM\RelationCollection<\Eshop\DB\LoyaltyProgramProduct>
	 */
	public RelationCollection $products;

	/**
	 * @relation
	 * @var \StORM\RelationCollection<\Eshop\DB\LoyaltyProgramHistory>
	 */
	public RelationCollection $histories;

	public function isActive(): bool
	{
		return ($this->validFrom === null || Carbon::parse($this->validFrom)->getTimestamp() <= \time()) && ($this->validTo === null || Carbon::parse($this->validTo)->getTimestamp() >= \time());
	}
}
