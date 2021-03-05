<?php

declare(strict_types=1);

namespace Eshop\DB;

use Eshop\DB\Currency;
use Eshop\DB\DeliveryType;
use Eshop\DB\Supplier;
use StORM\RelationCollection;

/**
 * Doprava
 * @table
 */
class Delivery extends \StORM\Entity
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
	 * Cena
	 * @column
	 */
	public float $price;
	
	/**
	 * Cena s DPH
	 * @column
	 */
	public ?float $priceVat;
	
	/**
	 * Externí id
	 * @column
	 */
	public ?string $externalId;
	
	/**
	 * Vytvořen
	 * @column{"type":"timestamp","default":"CURRENT_TIMESTAMP"}
	 */
	public string $createdTs;
	
	/**
	 * Expedováno
	 * @column{"type":"timestamp"}
	 */
	public ?string $shippedTs;
	
	/**
	 * Datum expedice
	 * @column{"type":"date"}
	 */
	public ?string $shippingDate;
	
	/**
	 * Typ dopravy
	 * @constraint{"onUpdate":"SET NULL","onDelete":"SET NULL"}
	 * @relation
	 */
	public ?DeliveryType $type;
	
	/**
	 * Objednávka
	 * @constraint{"onUpdate":"CASCADE","onDelete":"CASCADE"}
	 * @relation
	 */
	public Order $order;
	
	/**
	 * Měna
	 * @relation
	 * @constraint
	 */
	public Currency $currency;
	
	/**
	 * Dodavatel / Dropship
	 * @relation
	 * @constraint
	 */
	public ?Supplier $supplier;
	
	public function getTypeName(): ?string
	{
		return $this->type ? $this->type->name : $this->typeName;
	}
}