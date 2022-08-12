<?php

declare(strict_types=1);

namespace Eshop\DB;

use Eshop\Common\DB\SystemicEntity;
use StORM\RelationCollection;

/**
 * Typ dopravy
 * @table
 */
class DeliveryType extends SystemicEntity
{
	public const IMAGE_DIR = 'deliverytype_images';
	
	/**
	 * Kód
	 * @column
	 */
	public string $code;
	
	/**
	 * Externí ID
	 * @column
	 */
	public ?string $externalId;
	
	/**
	 * Název
	 * @column{"mutations":true}
	 */
	public ?string $name;
	
	/**
	 * Popisek
	 * @column{"type":"text","mutations":true}
	 */
	public ?string $perex;
	
	/**
	 * Instrukce (např. do emailu)
	 * @column{"type":"text","mutations":true}
	 */
	public ?string $instructions;
	
	/**
	 * Náhledový obrázek
	 * @column
	 */
	public ?string $imageFileName;
	
	/**
	 * Trakovací odkaz v printf formátu
	 * @column
	 */
	public ?string $trackingLink;
	
	/**
	 * Exportovat do feedu
	 * @column
	 */
	public bool $exportToFeed = false;
	
	/**
	 * Exclusivní pro skupiny uživatelů
	 * @relation
	 * @constraint{"onUpdate":"CASCADE","onDelete":"CASCADE"}
	 */
	public ?CustomerGroup $exclusive;
	
	/**
	 * Priorita
	 * @column
	 */
	public int $priority = 10;
	
	/**
	 * Skryto
	 * @column
	 */
	public bool $hidden = false;
	
	/**
	 * Doporučeno
	 * @column
	 */
	public bool $recommended = false;
	
	/**
	 * Externí dopravce
	 * @column
	 */
	public bool $externalCarrier = false;

	/**
	 * Max váha
	 * @column
	 */
	public ?float $maxWeight;

	/**
	 * Max rozměr
	 * @column
	 */
	public ?float $maxDimension;
	
	/**
	 * @relationNxN
	 * @var \StORM\RelationCollection<\Eshop\DB\PaymentType>|\Eshop\DB\PaymentType[]
	 */
	public RelationCollection $allowedPaymentTypes;

	/**
	 * Výdejní typ
	 * @relation
	 * @constraint
	 */
	public ?PickupPointType $pickupPointType;
}
