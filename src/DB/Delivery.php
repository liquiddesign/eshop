<?php

declare(strict_types=1);

namespace Eshop\DB;

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
	 * Cena před (pokud je akční)
	 * @column
	 */
	public ?float $priceBefore;

	/**
	 * Cena před (pokud je akční) s DPH
	 * @column
	 */
	public ?float $priceVatBefore;
	
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
	 * DPD kód
	 * @column
	 */
	public ?string $dpdCode;

	/**
	 * DPD vytištěno
	 * @column
	 */
	public bool $dpdPrinted = false;

	/**
	 * DPD chyba
	 * @column
	 */
	public bool $dpdError = false;

	/**
	 * PPL kód
	 * @column
	 */
	public ?string $pplCode;

	/**
	 * PPL chyba
	 * @column
	 */
	public bool $pplError = false;

	/**
	 * PPL vytištěno
	 * @column
	 */
	public bool $pplPrinted = false;

	/**
	 * Zasilkovna kód
	 * @column
	 */
	public ?string $zasilkovnaCode;

	/**
	 * Zasilkovna chyba
	 * @column
	 */
	public bool $zasilkovnaError = false;

	/**
	 * Zasilkovna doručování dokončeno - není nutné zjištovat stav
	 * @column
	 */
	public bool $zasilkovnaFinished = false;
	
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

	public function getDpdCode(): ?string
	{
		return $this->dpdError ? null : $this->dpdCode;
	}

	public function getPplCode(): ?string
	{
		return $this->pplError ? null : $this->pplCode;
	}

	public function getZasilkovnaCode(): ?string
	{
		return $this->zasilkovnaError ? null : $this->zasilkovnaCode;
	}
}
