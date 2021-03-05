<?php

declare(strict_types=1);

namespace Eshop\DB;

/**
 * Položka množstevního ceníku
 * @table
 */
class QuantityPrice extends \StORM\Entity
{
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
	 * Od jakého množství je cena
	 * @column
	 */
	public ?int $validFrom;
	
	/**
	 * Produkt
	 * @constraint{"onUpdate":"CASCADE","onDelete":"CASCADE"}
	 * @relation
	 */
	public Product $product;
	
	/**
	 * Ceník
	 * @constraint{"onUpdate":"CASCADE","onDelete":"CASCADE"}
	 * @relation
	 */
	public Pricelist $pricelist;
}