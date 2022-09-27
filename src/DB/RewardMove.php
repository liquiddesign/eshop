<?php

declare(strict_types=1);

namespace Eshop\DB;

/**
 * Odměna
 * @table
 */
class RewardMove extends \StORM\Entity
{
	/**
	 * Vytvořen
	 * @column{"type":"timestamp","default":"CURRENT_TIMESTAMP"}
	 */
	public string $createdTs;

	/**
	 * @column
	 */
	public ?string $reason;
	
	/**
	 * @column
	 */
	public bool $applied = false;
	
	/**
	 * Platnost od
	 * @column{"type":"datetime"}
	 */
	public ?string $validFrom;
	
	/**
	 * Platnost do
	 * @column{"type":"datetime"}
	 */
	public ?string $validTo;
	
	/**
	 * Částka - doplatek +-
	 * @column
	 */
	public float $price;
	
	/**
	 * Částka - doplatek +-
	 * @column
	 */
	public float $priceVat;
	
	/**
	 * Kusů +-
	 * @column
	 */
	public float $productAmount;
	
	/**
	 * Nárok na produkt
	 * @relation
	 * @constraint{"onUpdate":"SET NULL","onDelete":"SET NULL"}
	 */
	public ?Product $product;
	
	/**
	 * @relation
	 * @constraint{"onUpdate":"RESTRICT","onDelete":"RESTRICT"}
	 */
	public ?Currency $currency;
	
	/**
	 * @relation
	 * @constraint{"onUpdate":"CASCADE","onDelete":"CASCADE"}
	 */
	public Customer $customer;
}
