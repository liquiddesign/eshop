<?php

declare(strict_types=1);

namespace Eshop\DB;

/**
 * Item of package set item
 * @table
 */
class RelatedPackageItem extends \StORM\Entity
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
}
