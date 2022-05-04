<?php

declare(strict_types=1);

namespace Eshop\DB;

/**
 * Ceník
 * @table
 */
class Pricelist extends \StORM\Entity
{
	/**
	 * Kód
	 * @column
	 * @unique
	 */
	public ?string $code;
	
	/**
	 * Název
	 * @column
	 */
	public ?string $name;
	
	/**
	 * Je aktivní?
	 * @column
	 */
	public bool $isActive;
	
	/**
	 * Je nákupní?
	 * @column
	 */
	public bool $isPurchase = false;
	
	/**
	 * Povolit slevovou hladinu
	 * @column
	 */
	public bool $allowDiscountLevel = false;

	/**
	 * Platí pouze s kuponem
	 * @column
	 */
	public bool $activeOnlyWithCoupon = false;
	
	/**
	 * Priorita
	 * @column
	 */
	public int $priority = 0;

	/**
	 * Custom label
	 * @column
	 * @unique
	 */
	public ?string $customLabel;

	/**
	 * Systemic
	 * @column
	 */
	public int $systemicLock = 0;
	
	/**
	 * Měna
	 * @relation
	 * @constraint
	 */
	public Currency $currency;
	
	/**
	 * Země DPH
	 * @relation
	 * @constraint
	 */
	public Country $country;
	
	/**
	 * Akce
	 * @relation
	 * @constraint{"onUpdate":"CASCADE","onDelete":"SET NULL"}
	 */
	public ?Discount $discount;
	
	/**
	 * Dodavatel / externí ceník
	 * @relation
	 * @constraint{"onUpdate":"CASCADE","onDelete":"SET NULL"}
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
