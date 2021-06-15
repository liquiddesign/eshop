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
	 * Skryto
	 * @column
	 */
	public bool $hidden = false;

	/**
	 * Master produkt
	 * @relation
	 * @constraint
	 */
	public Product $master;

	/**
	 * Slave produkt
	 * @relation
	 * @constraint
	 */
	public Product $slave;
}