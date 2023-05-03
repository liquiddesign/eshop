<?php

declare(strict_types=1);

namespace Eshop\DB;

use Base\Entity\ShopEntity;

/**
 * Stužky k produktu
 * @table
 */
class Ribbon extends ShopEntity
{
	public const IMAGE_DIR = 'ribbon_images';
	
	/**
	 * Název / Popisek
	 * @column{"mutations":true}
	 */
	public ?string $name;
	
	/**
	 * Typ
	 * @column{"type":"enum","length":"'normal','onlyImage'"}
	 */
	public string $type;
	
	/**
	 * Obrázek štítku
	 * @column
	 */
	public ?string $imageFileName;
	
	/**
	 * Barva textu
	 * @column
	 */
	public ?string $color;
	
	/**
	 * Pozadí
	 * @column
	 */
	public ?string $backgroundColor;
	
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
	 * Dynamický štítek
	 * @column
	 */
	public bool $dynamic = false;

	/**
	 * Dynamický štítek - prodejnost
	 * @column
	 */
	public ?string $saleability;

	/**
	 * Dynamický štítek - maximální počet produktů
	 * @column
	 */
	public ?int $maxProducts;
}
