<?php

declare(strict_types=1);

namespace Eshop\DB;

/**
 * Role uzivatelů uživatelů
 * @table
 */
class CustomerRole extends \StORM\Entity
{
	/**
	 * Jméno
	 * @column
	 */
	public string $name;

	/**
	 * @column
	 */
	public int $priority;

	/**
	 * Systémová
	 * @column
	 */
	public bool $systemic = false;

	public function isSystemic(): bool
	{
		return $this->systemic;
	}
}
