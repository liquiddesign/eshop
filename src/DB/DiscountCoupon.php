<?php

declare(strict_types=1);

namespace Eshop\DB;

/**
 * Slevový kupón
 * @table
 * @index{"name":"discount_coupon_unique","unique":true,"columns":["code","fk_discount"]}
 */
class DiscountCoupon extends \StORM\Entity
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
	 * Kolikrát je možné využít
	 * @column
	 */
	public ?int $usageLimit;
	
	/**
	 * Kolikrát je již využito
	 * @column
	 */
	public int $usagesCount = 0;

	/**
	 * Last usage datetime
	 * @column{"type":"datetime"}
	 */
	public ?string $lastUsageTs;

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
	 * Maximální objednávka
	 * @column
	 */
	public bool $targitoExport = false;
	
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
}
