<?php

declare(strict_types=1);

namespace Eshop\DB;

use Eshop\Common\DB\IPackageItem;
use StORM\Entity;

/**
 * Položka nabídky
 * @table{"name":"eshop_offeritem"}
 * @index{"name":"offeritem_product","columns":["fk_offer","fk_product","fk_variant"]}
 */
class OfferItem extends Entity implements IPackageItem
{
	/**
	 * Název produktu
	 * @column{"mutations":true}
	 */
	public string|null $productName = null;

	/**
	 * Kód produktu
	 * @column
	 */
	public string|null $productCode = null;

	/**
	 * Podkód produktu
	 * @column
	 */
	public string|null $productSubCode = null;

	/**
	 * EAN produktu
	 * @column
	 */
	public string|null $productEan = null;

	/**
	 * Název varianty
	 * @column{"mutations":true}
	 */
	public string|null $variantName = null;

	/**
	 * Množství
	 * @column
	 */
	public int $amount = 1;

	/**
	 * Jednotková cena bez DPH
	 * @column
	 */
	public float $price;

	/**
	 * Jednotková cena s DPH
	 * @column
	 */
	public float|null $priceVat = null;

	/**
	 * Původní katalogová cena bez DPH
	 * @column
	 */
	public float|null $priceBefore = null;

	/**
	 * Původní katalogová cena s DPH
	 * @column
	 */
	public float|null $priceVatBefore = null;

	/**
	 * DPH procento
	 * @column
	 */
	public float|null $vatPct = null;

	/**
	 * Priorita řazení
	 * @column
	 */
	public int $priority = 10;

	/**
	 * Váha produktu
	 * @column
	 */
	public float|null $productWeight = null;

	/**
	 * Poznámka
	 * @column
	 */
	public string|null $note = null;

	/**
	 * Nabídka
	 * @relation
	 * @constraint{"onUpdate":"CASCADE","onDelete":"CASCADE"}
	 */
	public Offer $offer;

	/**
	 * Produkt
	 * @relation
	 * @constraint{"onUpdate":"CASCADE","onDelete":"SET NULL"}
	 */
	public Product|null $product = null;

	/**
	 * Varianta
	 * @relation
	 * @constraint{"onUpdate":"SET NULL","onDelete":"SET NULL"}
	 */
	public Variant|null $variant = null;

	/**
	 * Skladová zásoba (vybraný dodavatel)
	 * @relation
	 * @constraint{"onUpdate":"CASCADE","onDelete":"SET NULL"}
	 */
	public Amount|null $storeAmount = null;

	/**
	 * Drop shipping
	 * @column
	 */
	public bool $dropShipping = false;

	/**
	 * Plovoucí cena (aktualizovatelná podle aktuálního katalogu)
	 * @column{"type":"tinyint","default":"0"}
	 */
	public bool $floatingPrice = false;

	/**
	 * Cílová marže pro plovoucí cenu (v procentech)
	 * @column
	 */
	public float|null $targetMarginPct = null;

	/**
	 * Skrýt v CKP ceníku
	 * @column{"type":"tinyint","default":"0"}
	 */
	public bool $hideInPricelist = false;

	public function getFullCode(): string|null
	{
		return $this->productSubCode !== null ? $this->productCode . '.' . $this->productSubCode : $this->productCode;
	}

	public function getProduct(): Product|null
	{
		if ($this->product !== null) {
			$this->product->setValue('price', $this->price);
			$this->product->setValue('priceVat', $this->priceVat);

			return $this->product;
		}

		return null;
	}

	public function getPriceSum(): float
	{
		return $this->price * $this->amount;
	}

	public function getPriceVatSum(): float|null
	{
		return $this->priceVat !== null ? $this->priceVat * $this->amount : null;
	}

	public function getAmount(): int
	{
		return $this->amount;
	}

	public function isDropShipping(): bool
	{
		return $this->dropShipping;
	}

	public function getSelectedAmount(): Amount|null
	{
		return $this->storeAmount;
	}

	/**
	 * Returns selected supplier product by storeAmount
	 */
	public function getSelectedSupplierProductByStoreAmount(): SupplierProduct|null
	{
		if ($this->storeAmount === null || $this->storeAmount->store->supplier?->code === null) {
			return null;
		}

		return $this->storeAmount->product->getSupplierProduct($this->storeAmount->store->supplier->code);
	}
}
