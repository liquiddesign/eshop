<?php

declare(strict_types=1);

namespace Eshop\DB;

use Base\DB\Shop;
use Base\Entity\ShopEntity;

/**
 * Typ kategorie
 * @table
 */
class CategoryType extends ShopEntity
{
	/**
	 * @column
	 */
	public string $name;

	/**
	 * @column
	 */
	public int $priority = 10;

	/**
	 * @column
	 */
	public bool $hidden = false;

	/**
	 * Systémový
	 * @column
	 */
	public bool $systemic = false;

	/**
	 * Pouze ke čtení
	 * @column
	 */
	public bool $readOnly = false;

	public function isSystemic(): bool
	{
		if ($this->readOnly) {
			return true;
		}

		return $this->systemic;
	}

	public function isReadOnly(): bool
	{
		return $this->readOnly;
	}
}
