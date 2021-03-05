<?php

declare(strict_types=1);

namespace Eshop\DB;

use StORM\RelationCollection;

/**
 * Typ vztahu produktů
 * @table
 */
class RelatedType extends \StORM\Entity
{
	/**
	 * Název vztahu
	 * @column{"mutations":true}
	 */
	public ?string $name;

	/**
	 * Zobrazovat jako podobné
	 * @column
	 */
	public bool $similar = false;

	/**
	 * Systemic
	 * @column
	 */
	public bool $systemic = false;

	public function isSystemic(): bool
	{
		return $this->systemic;
	}
}