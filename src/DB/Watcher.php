<?php

declare(strict_types=1);

namespace Eshop\DB;

/**
 * Hlidací pes
 * @table
 * @index{"name":"product_watchlist","unique":true,"columns":["fk_product","fk_customer"]}
 */
class Watcher extends \StORM\Entity
{
	/**
	 * Oznámit od množství
	 * @column
	 */
	public ?int $amountFrom;

	/**
	 * Oznámit od ceny
	 * @column
	 */
	public ?float $priceFrom;

	/**
	 * Předchozí množství
	 * @column
	 */
	public ?int $beforeAmountFrom;

	/**
	 * Předchozí cena
	 * @column
	 */
	public ?float $beforePriceFrom;

	/**
	 * Ponechat watcher po splnění podmínky a odeslání upozornění pro další upozornění
	 * @column
	 */
	public bool $keepAfterNotify = true;

	/**
	 * Zákazník
	 * @constraint{"onUpdate":"CASCADE","onDelete":"CASCADE"}
	 * @relation
	 */
	public Customer $customer;

	/**
	 * Produkt
	 * @constraint{"onUpdate":"CASCADE","onDelete":"CASCADE"}
	 * @relation
	 */
	public Product $product;

	/**
	 * Vytvořen
	 * @column{"type":"timestamp","default":"CURRENT_TIMESTAMP"}
	 */
	public string $createdTs;
}
