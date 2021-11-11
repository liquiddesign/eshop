<?php

declare(strict_types=1);

namespace Eshop\DB;

/**
 * Typ vztahu produktů
 * @table
 * @index{"name":"relatedType_uniqueCode","unique":true,"columns":["code"]}
 */
class RelatedType extends \StORM\Entity
{
	/**
	 * Kód
	 * @column
	 */
	public string $code;

	/**
	 * Název vztahu
	 * @column{"mutations":true}
	 */
	public ?string $name;

	/**
	 * Zobrazovat v detailu produktu
	 * @column
	 */
	public bool $showDetail = false;

	/**
	 * Zobrazovat v košíku
	 * @column
	 */
	public bool $showCart = false;

	/**
	 * Zobrazovat v našeptávači
	 * @column
	 */
	public bool $showSearch = false;

	/**
	 * Zobrazovat v detailu produktu jako set (vypsat položky)
	 * @column
	 */
	public bool $showAsSet = false;

	/**
	 * Výchozí množství produktu
	 * @column
	 */
	public int $defaultAmount = 1;

	/**
	 * Název master produktů
	 * @column
	 */
	public ?string $masterName;

	/**
	 * Název slave produktů
	 * @column
	 */
	public ?string $slaveName;

	/**
	 * Výchozí sleva %
	 * @column
	 */
	public ?float $defaultDiscountPct;

	/**
	 * Výchozí výpočet ceny z masteru v %, např.: pro upsell
	 * @column
	 */
	public ?float $defaultMasterPct;

	/**
	 * Systemic
	 * @column
	 */
	public bool $systemic = false;

	public function isSystemic(): bool
	{
		return $this->systemic;
	}

	public function getMasterInternalName(): ?string
	{
		return $this->masterName ?? 'Master produkty';
	}

	public function getSlaveInternalName(): ?string
	{
		return $this->slaveName ?? 'Slave produkty';
	}
}
