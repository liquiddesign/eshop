<?php

namespace Eshop\DB;

use Base\Entity\ShopEntity;
use StORM\RelationCollection;

class DiscountList extends ShopEntity
{
	/**
	 * Priorita
	 * @column
	 */
	public int $priority = 10;

	/**
	 * Skryto
	 * @column
	 */
	public bool $hidden = false;

	/**
	 * @relation
	 * @var \StORM\RelationCollection<\Eshop\DB\DiscountList>
	 */
	public RelationCollection $discountListItems;
}