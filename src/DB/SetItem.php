<?php

declare(strict_types=1);

namespace Eshop\DB;

/**
 * Set produktu
 * @table
 * @deprecated
 */
class SetItem extends \StORM\Entity
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
	 * Sleva v rámci setu
	 * @column
	 */
	public float $discountPct = 0;

	/**
	 * Pořadí v setu
	 * @column
	 */
	public int $priority;
	
	/**
	 * @relation
	 * @constraint{"onUpdate":"SET NULL","onDelete":"SET NULL"}
	 */
	public ?Set $productSet;

	/**
	 * @relation
	 * @constraint{"onUpdate":"CASCADE","onDelete":"CASCADE"}
	 */
	public CartItem $cartItem;
}
