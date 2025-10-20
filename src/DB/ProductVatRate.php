<?php

declare(strict_types=1);

namespace Eshop\DB;

/**
 * Daň produktu v dané zemi
 * @table
 * @index{"name":"product_vat_rate_unique_product_country","unique":true,"columns":["fk_product","fk_country"]}
 */
class ProductVatRate extends \StORM\Entity
{
	/**
	 * Daň
	 * @relation
	 * @constraint{"onUpdate":"CASCADE","onDelete":"RESTRICT"}
	 */
	public VatRate $vatRate;

	/**
	 * Země
	 * @relation
	 * @constraint{"onUpdate":"CASCADE","onDelete":"CASCADE"}
	 */
	public Country $country;

	/**
	 * Produkt
	 * @relation
	 * @constraint{"onUpdate":"CASCADE","onDelete":"CASCADE"}
	 */
	public Product $product;
}
