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
	 * Exportováno
	 * @column{"type":"datetime"}
	 */
	public ?string $exportedTs;

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
	 * @var \StORM\RelationCollection<\Eshop\DB\RelatedPackageItem>
	 */
	public RelationCollection $relatedPackageItems;

	/**
	 * Returns selected supplier product by storeAmount
	 */
	public function getSelectedSupplierProductBySupplierCode(string $supplierCode): ?SupplierProduct
	{
		if (!$this->storeAmount) {
			return null;
		}

		return $this->storeAmount->product->getSupplierProduct($supplierCode);
	}

	public function getSupplierProduct(string $supplierCode): ?SupplierProduct
	{
		return $this->getSelectedSupplierProductBySupplierCode($supplierCode) ?: ($this->cartItem->product ? $this->cartItem->product->getSupplierProduct($supplierCode) : null);
	}
}
