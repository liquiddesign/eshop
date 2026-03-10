<?php

declare(strict_types=1);

namespace Eshop\DB;

use StORM\RelationCollection;

/**
 * Podmínka slevového kuponu pro výrobce/značky
 * @table
 * @method \StORM\RelationCollection<\Eshop\DB\Producer> getProducers()
 */
class DiscountConditionProducer extends \StORM\Entity
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
	 * Výrobci/značky
	 * @relationNxN
	 * @var \StORM\RelationCollection<\Eshop\DB\Producer>
	 */
	public RelationCollection $producers;
}
