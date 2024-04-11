<?php

declare(strict_types=1);

namespace Eshop\DB;

/**
 * Položka ceníku
 * @table
 * @index{"name":"product_pricelist","unique":true,"columns":["fk_product","fk_pricelist"]}
 * @index{"name":"price_createdts","unique":false,"columns":["createdTs"]}
 */
class Price extends \StORM\Entity
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
	 * Cena před (pokud je akční)
	 * @column
	 */
	public ?float $priceBefore;
	
	/**
	 * Cena před (pokud je akční) s DPH
	 * @column
	 */
	public ?float $priceVatBefore;

	/**
	 * @column
	 */
	public bool $hidden = false;

	/**
	 * Vytvořen
	 * @column{"type":"timestamp","default":"CURRENT_TIMESTAMP"}
	 */
	public string $createdTs;
	
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
