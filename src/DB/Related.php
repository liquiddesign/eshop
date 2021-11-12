<?php

declare(strict_types=1);

namespace Eshop\DB;

/**
 * Produkty ve vztahu
 * @table
 * @index{"name":"related_code","unique":true,"columns":["fk_master","fk_slave","amount","discountPct","masterPct"]}
 */
class Related extends \StORM\Entity
{
	/**
	 * @relation
	 * @constraint{"onUpdate":"CASCADE","onDelete":"CASCADE"}
	 */
	public RelatedType $type;

	/**
	 * Priorita
	 * @column
	 */
	public int $priority = 10;

	/**
	 * Množství
	 * @column
	 */
	public int $amount = 1;

	/**
	 * Sleva %, např.: pro set
	 * @column
	 */
	public ?float $discountPct;

	/**
	 * Ceny z masteru v %, např.: pro upsell
	 * @column
	 */
	public ?float $masterPct;

	/**
	 * Skryto
	 * @column
	 */
	public bool $hidden = false;

	/**
	 * Systemic
	 * @column
	 */
	public bool $systemic = false;

	/**
	 * Master produkt
	 * @relation
	 * @constraint{"onUpdate":"CASCADE","onDelete":"CASCADE"}
	 */
	public Product $master;

	/**
	 * Slave produkt
	 * @relation
	 * @constraint{"onUpdate":"CASCADE","onDelete":"CASCADE"}
	 */
	public Product $slave;

	public function isSystemic(): bool
	{
		return $this->systemic;
	}
}
