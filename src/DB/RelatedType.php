<?php

declare(strict_types=1);

namespace Eshop\DB;

use Nette\Utils\Strings;

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
	 * Skryto
	 * @column
	 */
	public bool $hidden = false;

	/**
	 * Název pro front s proměnnými (Latte)
	 * @column{"mutations":true}
	 */
	public ?string $frontMasterName;

	/**
	 * Název pro front s proměnnými (Latte)
	 * @column{"mutations":true}
	 */
	public ?string $frontSlaveName;

	/**
	 * Systemic
	 * @column
	 */
	public bool $systemic = false;

	/**
	 * Systemic
	 * @column
	 */
	public int $systemicLock = 0;

	public function isSystemic(): bool
	{
		return $this->systemic || $this->systemicLock > 0;
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

	public function getMasterInternalName(): ?string
	{
		return \is_string($this->masterName) && Strings::length($this->masterName) > 0 ? $this->masterName : 'Master produkty';
	}

	public function getSlaveInternalName(): ?string
	{
		return \is_string($this->slaveName) && Strings::length($this->slaveName) > 0 ? $this->slaveName : 'Slave produkty';
	}

	/**
	 * @return array<string|array>
	 */
	public function getFrontendData(Product $product): array
	{
		return [
			'productName' => $product->name,
		];
	}
}
