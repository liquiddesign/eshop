<?php

declare(strict_types=1);

namespace Eshop\DB;

/**
 * Sklad
 * @table
 */
class Store extends \StORM\Entity
{
	/**
	 * Kód
	 * @column
	 */
	public ?string $code;
	
	/**
	 * Název
	 * @column{"mutations":true}
	 */
	public ?string $name;

	/**
	 * Systemic
	 * @column
	 */
	public int $systemicLock = 0;
	
	/**
	 * Dodavatel / externí sklad
	 * @relation
	 * @constraint{"onUpdate":"CASCADE","onDelete":"CASCADE"}
	 */
	public ?Supplier $supplier;

	public function isSystemic(): bool
	{
		return $this->systemicLock > 0;
	}

	public function addSystemic(): int
	{
		$this->systemicLock++;
		$this->updateAll();

		return $this->systemicLock;
	}

	public function removeSystemic(): int
	{
		$this->systemicLock--;

		if ($this->systemicLock < 0) {
			$this->systemicLock = 0;
		} else {
			$this->updateAll();
		}

		return $this->systemicLock;
	}
}
