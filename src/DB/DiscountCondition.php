<?php

declare(strict_types=1);

namespace Eshop\DB;

use StORM\RelationCollection;

/**
 * Podmínka slevového kuponu
 * @table
 */
class DiscountCondition extends \StORM\Entity
{
	public const CART_CONDITION_IS_IN_CART = 'isInCart';
	public const CART_CONDITION_NOT_IN_CART = 'notInCart';
	public const CART_CONDITIONS = [self::CART_CONDITION_IS_IN_CART => 'je v košíku', self::CART_CONDITION_NOT_IN_CART => 'není v košíku'];
	public const QUANTITY_CONDITION_ALL = 'all';
	public const QUANTITY_CONDITION_AT_LEAST_ONE = 'atLeastOne';
	public const QUANTITY_CONDITIONS = [self::QUANTITY_CONDITION_ALL => 'všechny z ', self::QUANTITY_CONDITION_AT_LEAST_ONE => 'alespoň jeden z'];

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
	 * Kupon
	 * @relation
	 * @constraint{"onUpdate":"CASCADE","onDelete":"CASCADE"}
	 */
	public ?DiscountCoupon $discountCoupon;

	/**
	 * Sleva na dopravu
	 * @relation
	 * @constraint{"onUpdate":"CASCADE","onDelete":"CASCADE"}
	 */
	public ?DeliveryDiscount $deliveryDiscount;

	/**
	 * Generator kuponu
	 * @relation
	 * @constraint{"onUpdate":"CASCADE","onDelete":"CASCADE"}
	 */
	public ?ApiGeneratorDiscountCoupon $apiGeneratorDiscountCoupon;

	/**
	 * Produkty
	 * @relationNxN
	 * @var \StORM\RelationCollection<\Eshop\DB\Product>|\Eshop\DB\Product[]
	 */
	public RelationCollection $products;
}
