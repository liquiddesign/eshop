<?php

declare(strict_types=1);

namespace Eshop\DB;

use Eshop\Common\DB\SystemicEntity;
use Nette\Utils\Strings;

/**
 * Produkty ve vztahu
 * @table
 * @index{"name":"related_code","unique":true,"columns":["fk_master","fk_slave","amount","discountPct","masterPct"]}
 * @index{"name":"related_shops","unique":false,"columns":["shops"]}
 */
class Related extends SystemicEntity
{
	/**
	 * @relation
	 * @constraint{"onUpdate":"CASCADE","onDelete":"CASCADE"}
	 */
	public RelatedType $type;

	/**
	 * Priorita
	 * @column
	 */
	public int $priority = 10;

	/**
	 * Množství
	 * @column
	 */
	public int $amount = 1;

	/**
	 * Sleva %, např.: pro set
	 * @column
	 */
	public ?float $discountPct;

	/**
	 * Ceny z masteru v %, např.: pro upsell
	 * @column
	 */
	public ?float $masterPct;

	/**
	 * Skryto
	 * @column
	 */
	public bool $hidden = false;

	/**
	 * Systemic
	 * @deprecated
	 * @column
	 */
	public bool $systemic = false;

	/**
	 * Obchody oddělené čárkou
	 * @column
	 */
	public string|null $shops = null;

	/**
	 * Master produkt
	 * @relation
	 * @constraint{"onUpdate":"CASCADE","onDelete":"CASCADE"}
	 */
	public Product $master;

	/**
	 * Slave produkt
	 * @relation
	 * @constraint{"onUpdate":"CASCADE","onDelete":"SET NULL"}
	 */
	public ?Product $slave = null;

	/**
	 * Název slave produktu (pokud produkt neexistuje)
	 * @column
	 */
	public ?string $slaveName = null;

	/**
	 * Název souboru obrázku (očekávaný název pro FTP upload)
	 * @column
	 */
	public ?string $image = null;

	/**
	 * Výrobce slave produktu (pro případ kdy slave je NULL)
	 * @relation
	 * @constraint{"onUpdate":"CASCADE","onDelete":"SET NULL"}
	 */
	public ?Producer $slaveProducer = null;

	public function isSystemic(): bool
	{
		return $this->systemic || $this->systemicLock > 0;
	}

	/**
	 * Get expected image name - auto-generates from slaveName if slave is null
	 */
	public function getExpectedImageName(): ?string
	{
		if ($this->image !== null && $this->image !== '') {
			return $this->image;
		}

		if ($this->slave === null && $this->slaveName !== null && $this->slaveName !== '') {
			return self::generateImageName($this->slaveName);
		}

		return null;
	}

	/**
	 * Generate expected image filename based on slaveName (sanitized + .jpg)
	 */
	public static function generateImageName(?string $slaveName): ?string
	{
		if ($slaveName === null || $slaveName === '') {
			return null;
		}

		$sanitized = Strings::webalize($slaveName);

		return $sanitized . '.jpg';
	}
}
