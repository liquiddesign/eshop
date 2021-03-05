<?php

declare(strict_types=1);

namespace Eshop\DB;

use StORM\RelationCollection;

/**
 * Typ dopravy
 * @table
 */
class DeliveryType extends \StORM\Entity
{
	public const IMAGE_DIR = 'deliverytype_images';
	
	/**
	 * Kód
	 * @column
	 */
	public string $code;
	
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
	 * @relationNxN
	 * @var \StORM\RelationCollection<\Eshop\DB\PaymentType>|\Eshop\DB\PaymentType[]
	 */
	public RelationCollection $allowedPaymentTypes;
}
