<?php

declare(strict_types=1);

namespace Eshop\DB;

use Base\Entity\ShopSystemicEntity;
use StORM\RelationCollection;

/**
 * Typ platby
 * @table
 * @index{"name":"paymenttype_codeshop","unique":true,"columns":["code", "fk_shop"]}
 */
class PaymentType extends ShopSystemicEntity
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

	/**
	 * Comgate method
	 * @column
	 */
	public ?string $comgateMethod;

	/**
	 * @relation
	 * @var \StORM\RelationCollection<\Eshop\DB\PaymentTypePrice>
	 */
	public RelationCollection $paymentTypePrices;
}
