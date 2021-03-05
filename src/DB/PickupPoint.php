<?php

declare(strict_types=1);

namespace Eshop\DB;

use User\DB\Address;
use StORM\RelationCollection;

/**
 * Výdejní místo
 * @table
 */
class PickupPoint extends \StORM\Entity
{
	public const IMAGE_DIR = 'pickupPoint_images';

	/**
	 * Název
	 * @column{"mutations":true}
	 */
	public ?string $name;

	/**
	 * Popis
	 * @column{"mutations":true}
	 */
	public ?string $description;

	/**
	 * Adresa
	 * @constraint{"onUpdate":"SET NULL","onDelete":"SET NULL"}
	 * @relation
	 */
	public ?Address $address;

	/**
	 * GPS souřadnice N ve stupních
	 * @column
	 */
	public ?float $gpsN;

	/**
	 * GPS souřadnice E ve stupních
	 * @column
	 */
	public ?float $gpsE;

	/**
	 * Obrázek
	 * @column
	 */
	public ?string $imageFileName;

	/**
	 * Email
	 * @column
	 */
	public ?string $email;

	/**
	 * Telefon
	 * @column
	 */
	public ?string $phone;

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
	 * Typ výdejního místa
	 * @constraint
	 * @relation
	 */
	public PickupPointType $pickupPointType;
}