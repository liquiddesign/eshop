<?php

declare(strict_types=1);

namespace Eshop\DB;

/**
 * Sazby DPH
 * @table
 */
class VatRate extends \StORM\Entity
{
	/**
	 * Název
	 * @column
	 */
	public ?string $name;
	
	/**
	 * Výše
	 * @column
	 */
	public float $rate;

	/**
	 * Priorita
	 * @column
	 */
	public int $priority = 10;
	
	/**
	 * Země
	 * @relation
	 * @constraint{"onUpdate":"CASCADE","onDelete":"CASCADE"}
	 */
	public Country $country;
	
	public function getVatMultiplier(bool $vat): float
	{
		return !$vat ? 100 / (100 + $this->rate) : (100 + $this->rate) / 100;
	}
}
