<?php

declare(strict_types=1);

namespace Eshop\DB;

use StORM\RelationCollection;

/**
 * Položka balíčku
 * @table
 * @index{"name":"package_item","unique":true,"columns":["fk_store","fk_package","fk_cartItem"]}
 */
class PackageItem extends \StORM\Entity
{
	/** @deprecated PackageItem is always deleted */
	public const DELETE_MODE_DELETE = 'delete';

	/** @deprecated PackageItem is always deleted */
	public const DELETE_MODE_MARK = 'mark';

	/**
	 * Počet
	 * @column
	 */
	public int $amount = 0;

	/**
	 * Smazáno
	 * @column
	 * @deprecated
	 */
	public bool $deleted = false;

	/**
	 * Status
	 * @column{"type":"enum","length":"'waiting','reserved'"}
	 */
	public string $status = 'waiting';

	/**
	 * Sklad
	 * @constraint{"onUpdate":"SET NULL","onDelete":"SET NULL"}
	 * @relation
	 * @deprecated Use $storeAmount
	 */
	public ?Store $store;

	/**
	 * Skladová zásoba
	 * @constraint{"onUpdate":"SET NULL","onDelete":"SET NULL"}
	 * @relation
	 */
	public ?Amount $storeAmount;

	/**
	 * Balík
	 * @constraint{"onUpdate":"CASCADE","onDelete":"CASCADE"}
	 * @relation
	 */
	public Package $package;

	/**
	 * Položka košíku
	 * @constraint{"onUpdate":"CASCADE","onDelete":"CASCADE"}
	 * @relation
	 */
	public CartItem $cartItem;

	/**
	 * Upsell
	 * @constraint{"onUpdate":"CASCADE","onDelete":"CASCADE"}
	 * @relation
	 */
	public ?PackageItem $upsell;

	/**
	 * Related package items
	 * @relation
	 * @var \StORM\RelationCollection<\Eshop\DB\RelatedPackageItem>|\Eshop\DB\RelatedPackageItem[]
	 */
	public RelationCollection $relatedPackageItems;

	public function getSupplierProduct(string $supplierCode): ?SupplierProduct
	{
		return $this->cartItem->product ? $this->cartItem->product->getSupplierProduct($supplierCode) : null;
	}
}
