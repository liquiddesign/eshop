<?php

declare(strict_types=1);

namespace Eshop\DB;

use Eshop\Common\DB\IPackageItem;

/**
 * Item of package set item
 * @table
 * @index{"name":"related_package_item_unique","unique":true,"columns":["fk_packageItem","fk_cartItem"]}
 */
class RelatedPackageItem extends \StORM\Entity implements IPackageItem
{
	/**
	 * Main item
	 * @constraint{"onUpdate":"CASCADE","onDelete":"CASCADE"}
	 * @relation
	 */
	public PackageItem $packageItem;

	/**
	 * Related cart item
	 * @constraint{"onUpdate":"CASCADE","onDelete":"CASCADE"}
	 * @relation
	 */
	public RelatedCartItem $cartItem;

	public function getProduct(): Product|null
	{
		return $this->cartItem->getProduct();
	}

	public function getAmount(): int
	{
		return $this->cartItem->amount;
	}
}
