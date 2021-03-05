<?php

declare(strict_types=1);

namespace Eshop\DB;

/**
 * Slevový kupón
 * @table
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
	 * Sleva na dopravu (%)
	 * @column
	 */
	public ?float $deliveryDiscountPct;
	
	/**
	 * Sleva na dopravu v měně
	 * @column
	 */
	public ?float $deliveryDiscountValue;
	
	/**
	 * Sleva na dopravu v měně s DPH
	 * @column
	 */
	public ?float $deliveryDiscountValueVat;
	
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
