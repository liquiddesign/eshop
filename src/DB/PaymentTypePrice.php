<?php

declare(strict_types=1);

namespace Eshop\DB;

/**
 * Cena platby
 * @table
 * @index{"name":"paymenttypeprice_currency","unique":true,"columns":["fk_currency"]}
 */
class PaymentTypePrice extends \StORM\Entity
{
	/**
	 * Cena
	 * @column
	 */
	public float $price;
	
	/**
	 * Cena s DPH
	 * @column
	 */
	public float $priceVat;
	
	/**
	 * Země DPH
	 * @relation
	 * @constraint
	 */
	public Country $country;
	
	/**
	 * Měna
	 * @relation
	 * @constraint
	 */
	public Currency $currency;
	
	/**
	 * Platba
	 * @relation
	 * @constraint{"onUpdate":"CASCADE","onDelete":"CASCADE"}
	 */
	public PaymentType $paymentType;
}
