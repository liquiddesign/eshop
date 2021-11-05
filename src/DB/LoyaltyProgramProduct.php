<?php

declare(strict_types=1);

namespace Eshop\DB;

/**
 * Věrnostní program X produkt
 * @table
 * @index{"name":"loyaltyprogram_product_unique","unique":true,"columns":["fk_product", "fk_loyaltyProgram"]}
 */
class LoyaltyProgramProduct extends \StORM\Entity
{
	/**
	 * Počet získaných "bodů" při nákupu
	 * @column
	 */
	public float $points = 0.0;

	/**
	 * @relation
	 * @constraint{"onUpdate":"CASCADE","onDelete":"CASCADE"}
	 */
	public Product $product;

	/**
	 * @relation
	 * @constraint{"onUpdate":"CASCADE","onDelete":"CASCADE"}
	 */
	public LoyaltyProgram $loyaltyProgram;
}
