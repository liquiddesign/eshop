<?php

declare(strict_types=1);

namespace Eshop\DB;

use StORM\RelationCollection;

/**
 * Produkty ve vztahu
 * @table
 * @index{"name":"products","unique":true,"columns":["fk_master","fk_slave"]}
 */
class Related extends \StORM\Entity
{
	/**
	 * @relation
	 * @constraint
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
	 * Readonly
	 * @column
	 */
	public bool $readonly = false;

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