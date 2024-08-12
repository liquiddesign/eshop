<?php

declare(strict_types=1);

namespace Eshop\DB;

use Base\Entity\ShopEntity;
use Carbon\Carbon;
use StORM\RelationCollection;

/**
 * Slevová akce
 * @table
 * @method \StORM\RelationCollection<\Eshop\DB\Pricelist> getPricelists()
 */
class Discount extends ShopEntity
{
	/**
	 * Název
	 * @column{"mutations":true}
	 */
	public ?string $name;

	/**
	 * Název
	 * @column{"mutations":true}
	 */
	public ?string $internalName;
	
	/**
	 * Platná od
	 * @column{"type":"datetime"}
	 */
	public ?string $validFrom;
	
	/**
	 * Platná do
	 * @column{"type":"datetime"}
	 */
	public ?string $validTo;
	
	/**
	 * Doporučeno
	 * @column
	 */
	public bool $recommended = false;
	
	/**
	 * Akční ceníky
	 * @relationNxN
	 * @var \StORM\RelationCollection<\Eshop\DB\Pricelist>
	 */
	public RelationCollection $pricelists;
	
	/**
	 * Slevy na dopravu
	 * @relation
	 * @var \StORM\RelationCollection<\Eshop\DB\DeliveryDiscount>
	 */
	public RelationCollection $deliveryDiscounts;
	
	/**
	 * Kůpony
	 * @relation
	 * @var \StORM\RelationCollection<\Eshop\DB\DiscountCoupon>
	 */
	public RelationCollection $coupons;

	/**
	 * Štítky
	 * @relationNxN
	 * @var \StORM\RelationCollection<\Eshop\DB\Ribbon>
	 */
	public RelationCollection $ribbons;

	public function isActive(): bool
	{
		return ($this->validFrom === null || Carbon::parse($this->validFrom)->getTimestamp() <= \time()) && ($this->validTo === null || Carbon::parse($this->validTo)->getTimestamp() >= \time());
	}
}
