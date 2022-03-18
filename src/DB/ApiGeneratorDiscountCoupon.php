<?php

declare(strict_types=1);

namespace Eshop\DB;

/**
 * API klíč pro vygenerování slevového kuponu pro partnera dle nastavení
 * @table
 */
class ApiGeneratorDiscountCoupon extends \StORM\Entity
{
	/**
	 * Kód
	 * @column
	 */
	public string $code;

	/**
	 * Popisek
	 * @column
	 */
	public ?string $label;

	/**
	 * Kolikrát je možné využít
	 * @column
	 */
	public ?int $apiUsageLimit;

	/**
	 * Kolikrát je již využito
	 * @column
	 */
	public int $apiUsagesCount = 0;

	/**
	 * Platná od
	 * @column{"type":"datetime"}
	 */
	public ?string $validFrom;

	/**
	 * Platná do
	 * @column{"type":"datetime"}
	 */
	public ?string $validTo;

	/**
	 * Poslední využití
	 * @column{"type":"timestamp"}
	 */
	public ?string $usedTs;

	/**
	 * Vytvořen
	 * @column{"type":"timestamp","default":"CURRENT_TIMESTAMP"}
	 */
	public ?string $createdTs;

	/**
	 * Sleva v měně
	 * @column
	 */
	public ?float $discountValue;

	/**
	 * Sleva v měně s DPH
	 * @column
	 */
	public ?float $discountValueVat;

	/**
	 * Sleva (%)
	 * @column
	 */
	public ?float $discountPct;

	/**
	 * Kolikrát je možné využít
	 * @column
	 */
	public ?int $usageLimit;

	/**
	 * Minimální objednávka
	 * @column
	 */
	public ?float $minimalOrderPrice;

	/**
	 * Maximální objednávka
	 * @column
	 */
	public ?float $maximalOrderPrice;

	/**
	 * Typ
	 * @column{"type":"enum","length":"'or','and'"}
	 */
	public string $conditionsType;

	/**
	 * Exkluzivně pro zákazníka
	 * @constraint{"onUpdate":"CASCADE","onDelete":"CASCADE"}
	 * @relation
	 */
	public ?Customer $exclusiveCustomer;

	/**
	 * Měna
	 * @relation
	 * @constraint
	 */
	public Currency $currency;

	/**
	 * Akce
	 * @relation
	 * @constraint{"onUpdate":"CASCADE","onDelete":"CASCADE"}
	 */
	public Discount $discount;

	public function isActive(): bool
	{
		return ($this->validFrom === null || \strtotime($this->validFrom) <= \time()) &&
			($this->validTo === null || \strtotime($this->validTo) >= \time()) &&
			($this->apiUsageLimit === null || $this->apiUsageLimit > $this->apiUsagesCount);
	}

	public function used(): void
	{
		if ($this->apiUsageLimit === null) {
			return;
		}

		$this->apiUsagesCount++;

		$this->updateAll(['apiUsagesCount']);
	}
}