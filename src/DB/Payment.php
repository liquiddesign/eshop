<?php

declare(strict_types=1);

namespace Eshop\DB;

/**
 * Platba
 * @table
 */
class Payment extends \StORM\Entity
{
	/**
	 * Kód typu dopravy
	 * @column
	 */
	public ?string $typeCode;
	
	/**
	 * Jméno typu dopravy
	 * @column{"mutations":true}
	 */
	public ?string $typeName;
	
	/**
	 * Cena za platbu
	 * @column
	 */
	public float $price;
	
	/**
	 * Cena za platbu s DPH
	 * @column
	 */
	public ?float $priceVat;
	
	/**
	 * Již zaplaceno
	 * @column
	 */
	public float $paidPrice = 0.0;
	
	/**
	 * Již zaplaceno s DPH
	 * @column
	 */
	public float $paidPriceVat = 0.0;
	
	/**
     * Zaplaceno
	 * @column{"type":"timestamp"}
	 */
	public ?string $paidTs;
	
	/**
	 * Vytvořen
	 * @column{"type":"timestamp","default":"CURRENT_TIMESTAMP"}
	 */
	public string $createdTs;
	
	/**
	 * Měna
	 * @relation
	 * @constraint
	 */
	public Currency $currency;
	
	/**
	 * Typ platby
	 * @constraint{"onUpdate":"SET NULL","onDelete":"SET NULL"}
	 * @relation
	 */
	public ?PaymentType $type;
	
	/**
	 * Objednávka
	 * @constraint{"onUpdate":"CASCADE","onDelete":"CASCADE"}
	 * @relation
	 */
	public Order $order;
	
	public function getTypeName(): ?string
	{
		return $this->type ? $this->type->name : $this->typeName;
	}
}