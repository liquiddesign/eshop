<?php

declare(strict_types=1);

namespace Eshop\DB;

/**
 * Výrobce
 * @table
 */
class Producer extends \StORM\Entity
{
	public const IMAGE_DIR = 'producer_images';
	
	/**
	 * Název
	 * @column{"mutations":true}
	 */
	public ?string $name;
	
	/**
	 * Perex
	 * @column{"type":"text","mutations":true}
	 */
	public ?string $perex;
	
	/**
	 * Obsah
	 * @column{"type":"longtext","mutations":true}
	 */
	public ?string $content;
	
	/**
	 * Logo výrobce
	 * @column
	 */
	public ?string $imageFileName;
	
	/**
	 * Priorita
	 * @column
	 */
	public int $priority = 10;
	
	/**
	 * Doporučené
	 * @column
	 */
	public bool $recommended = false;
	
	/**
	 * Skryto
	 * @column
	 */
	public bool $hidden = false;
}