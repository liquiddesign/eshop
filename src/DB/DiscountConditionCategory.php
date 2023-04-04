<?php

declare(strict_types=1);

namespace Eshop\DB;

use StORM\RelationCollection;

/**
 * Podmínka slevového kuponu pro kategorie
 * @table
 */
class DiscountConditionCategory extends \StORM\Entity
{
	/**
	 * Typ
	 * @column{"type":"enum","length":"'isInCart','notInCart'"}
	 */
	public string $cartCondition;

	/**
	 * Typ
	 * @column{"type":"enum","length":"'all','atLeastOne'"}
	 */
	public string $quantityCondition;

	/**
	 * Only one of three sources is set (coupon, delivery, api generator)
	 * @relation
	 * @constraint{"onUpdate":"CASCADE","onDelete":"CASCADE"}
	 */
	public ?DiscountCoupon $discountCoupon;

	/**
	 * Only one of three sources is set (coupon, delivery, api generator)
	 * @relation
	 * @constraint{"onUpdate":"CASCADE","onDelete":"CASCADE"}
	 */
	public ?DeliveryDiscount $deliveryDiscount;

	/**
	 * Only one of three sources is set (coupon, delivery, api generator)
	 * @relation
	 * @constraint{"onUpdate":"CASCADE","onDelete":"CASCADE"}
	 */
	public ?ApiGeneratorDiscountCoupon $apiGeneratorDiscountCoupon;

	/**
	 * Produkty
	 * @relationNxN
	 * @var \StORM\RelationCollection<\Eshop\DB\Category>|\Eshop\DB\Category[]
	 */
	public RelationCollection $categories;
}
