<?php

declare(strict_types=1);

namespace Eshop\DB;

/**
 * Cena dopravy
 * @table
 */
class DeliveryTypePrice extends \StORM\Entity
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
	 * Dostupné do váhy kg (včetně)
	 * @column
	 */
	public ?float $weightTo;

	/**
	 * Dostupné do rozměru (včetně)
	 * @column
	 */
	public ?float $dimensionTo;
	
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
	 * Doprava
	 * @relation
	 * @constraint{"onUpdate":"CASCADE","onDelete":"CASCADE"}
	 */
	public DeliveryType $deliveryType;
}