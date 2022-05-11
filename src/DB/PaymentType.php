<?php

declare(strict_types=1);

namespace Eshop\DB;

use Eshop\Common\DB\SystemicEntity;

/**
 * Typ platby
 * @table
 */
class PaymentType extends SystemicEntity
{
	public const IMAGE_DIR = 'paymenttype_images';
	
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
	 * Náhledový obrázek
	 * @column
	 */
	public ?string $imageFileName;
	
	/**
	 * Priorita
	 * @column
	 */
	public int $priority = 10;
	
	/**
	 * Exclusivní pro skupiny uživatelů
	 * @relation
	 * @constraint{"onUpdate":"CASCADE","onDelete":"CASCADE"}
	 */
	public ?CustomerGroup $exclusive;
	
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
}
