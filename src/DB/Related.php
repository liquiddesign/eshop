<?php

declare(strict_types=1);

namespace Eshop\DB;

use StORM\RelationCollection;

/**
 * Produkty ve vztahu
 * @table
 * @index{"name":"related_code","unique":true,"columns":["code"]}
 */
class Related extends \StORM\Entity
{
	/**
	 * @relation
	 * @constraint{"onUpdate":"CASCADE","onDelete":"CASCADE"}
	 */
	public RelatedType $type;

	/**
	 * Kód
	 * @column
	 */
	public string $code;

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
	 * Systemic
	 * @column
	 */
	public bool $systemic = false;

	/**
	 * @relation
	 * @var \StORM\RelationCollection<\Eshop\DB\RelatedMaster>|\Eshop\DB\RelatedMaster[]
	 */
	public RelationCollection $masters;

	/**
	 * @relation
	 * @var \StORM\RelationCollection<\Eshop\DB\RelatedSlave>|\Eshop\DB\RelatedSlave[]
	 */
	public RelationCollection $slaves;

	public function isSystemic(): bool
	{
		return $this->systemic;
	}
}
