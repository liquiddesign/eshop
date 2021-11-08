<?php

declare(strict_types=1);

namespace Eshop\DB;

use StORM\RelationCollection;

/**
 * Slevová akce
 * @table
 */
class Discount extends \StORM\Entity
{
	/**
	 * Název
	 * @column{"mutations":true}
	 */
	public ?string $name;
	
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
	 * @relation
	 * @var \StORM\RelationCollection<\Eshop\DB\Pricelist>|\Eshop\DB\Pricelist[]
	 */
	public RelationCollection $pricelists;
	
	/**
	 * Slevy na dopravu
	 * @relation
	 * @var \StORM\RelationCollection<\Eshop\DB\DeliveryDiscount>|\Eshop\DB\DeliveryDiscount[]
	 */
	public RelationCollection $deliveryDiscounts;
	
	/**
	 * Kůpony
	 * @relation
	 * @var \StORM\RelationCollection<\Eshop\DB\DiscountCoupon>|\Eshop\DB\DiscountCoupon[]
	 */
	public RelationCollection $coupons;
	
	/**
	 * Limitovani kuponů jen pro učité tagy
	 * @relationNxN
	 * @var \StORM\RelationCollection<\Eshop\DB\Tag>|\Eshop\DB\Tag[]
	 */
	public RelationCollection $limitCouponsForTags;

	/**
	 * Štítky
	 * @relationNxN
	 * @var \StORM\RelationCollection<\Eshop\DB\Ribbon>|\Eshop\DB\Ribbon[]
	 */
	public RelationCollection $ribbons;

	public function isActive(): bool
	{
		return ($this->validFrom === null || \strtotime($this->validFrom) <= \time()) && ($this->validTo === null || \strtotime($this->validTo) >= \time());
	}
}
