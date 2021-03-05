<?php

declare(strict_types=1);

namespace Eshop\DB;

/**
 * Stužky k produktu
 * @table
 */
class Ribbon extends \StORM\Entity
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
}