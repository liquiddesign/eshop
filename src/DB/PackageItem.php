<?php

declare(strict_types=1);

namespace Eshop\DB;

use Eshop\Common\DB\IPackageItem;
use StORM\RelationCollection;

/**
 * Položka balíčku
 * @table
 * @index{"name":"package_item","unique":true,"columns":["fk_store","fk_package","fk_cartItem"]}
 * @method \StORM\RelationCollection<\Eshop\DB\RelatedPackageItem> getRelatedPackageItems()
 */
class PackageItem extends \StORM\Entity implements IPackageItem
{
	/**
	 * Počet
	 * @column
	 */
	public int $amount = 0;
	
	/**
	 * Expedováno kusů
	 * @column
	 */
	public int $dispatchedAmount = 0;
	
	/**
	 * Výdejka
	 * @column
	 */
	public ?string $expeditionNumber = null;

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
	 * @constraint{"onUpdate":"CASCADE","onDelete":"SET NULL"}
	 * @relation
	 * @deprecated Use $storeAmount
	 */
	public ?Store $store;

	/**
	 * Skladová zásoba
	 * @constraint{"onUpdate":"CASCADE","onDelete":"SET NULL"}
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

	public function getProduct(): Product|null
	{
		return $this->cartItem->getProduct();
	}

	public function getAmount(): int
	{
		return $this->amount;
	}
}
