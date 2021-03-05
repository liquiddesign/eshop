<?php

declare(strict_types=1);

namespace Eshop\DB;

use Eshop\DB\Product;
use Eshop\DB\Variant;
use StORM\Collection;
use StORM\ICollection;

/**
 * Položka košíku
 * @table
 * @index{"name":"cartitem_item","unique":true,"columns":["fk_product","fk_variant","fk_cart"]}
 */
class CartItem extends \StORM\Entity
{
	/**
	 * Název produktu
	 * @column{"mutations":true}
	 */
	public ?string $productName;
	
	/**
	 * Kód produktu
	 * @column
	 */
	public ?string $productCode;
	
	/**
	 * Podkód produktu
	 * @column
	 */
	public ?string $productSubCode;
	
	/**
	 * Váha produktu
	 * @column
	 */
	public ?float $productWeight;
	
	/**
	 * Název varianty
	 * @column{"mutations":true}
	 */
	public ?string $variantName;
	
	/**
	 * Množství
	 * @column
	 */
	public int $amount;
	
	/**
	 * Dodané množství
	 * @column
	 */
	public ?int $realAmount;
	
	/**
	 * Cena
	 * @column
	 */
	public float $price;
	
	/**
	 * Cena s DPH
	 * @column
	 */
	public ?float $priceVat;
	
	/**
	 * DPH
	 * @column
	 */
	public ?float $vatPct;
	
	/**
	 * Cena
	 * @column
	 */
	public ?int $pts;
	
	/**
	 * Poznámka
	 * @column
	 */
	public ?string $note;
	
	/**
	 * Produkt
	 * @constraint{"onUpdate":"SET NULL","onDelete":"SET NULL"}
	 * @relation
	 */
	public ?Product $product;
	
	/**
	 * Varianta
	 * @constraint{"onUpdate":"SET NULL","onDelete":"SET NULL"}
	 * @relation
	 */
	public ?Variant $variant;
	
	/**
	 * Košík
	 * @constraint{"onUpdate":"CASCADE","onDelete":"CASCADE"}
	 * @relation
	 */
	public Cart $cart;
	
	public function getPriceSum(): float
	{
		return $this->price * $this->amount;
	}
	
	public function getPriceVatSum(): float
	{
		return $this->priceVat * $this->amount;
	}
	
	public function isAvailable(): bool
	{
		return $this->product && !$this->product->unavailable;
	}
	
	public function getFullCode(): ?string
	{
		//@TODO code-subcode delimeter (tečka) by mel jit nastavit
		return $this->productSubCode ? $this->productCode . '.' . $this->productSubCode : $this->productCode;
	}
	
	public function getDeliveries(): ICollection
	{
		return $this->getConnection()->findRepository(Delivery::class)->many()
			->join(['package' => 'eshop_packageitem'], 'this.uuid=package.fk_delivery')
			->where('package.fk_cartItem', $this->getPK());
	}
}
