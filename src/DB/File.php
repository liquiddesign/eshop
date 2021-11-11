<?php

declare(strict_types=1);

namespace Eshop\DB;

/**
 * Soubory k produktu
 * @table
 */
class File extends \StORM\Entity
{
	public const FILE_DIR = 'product_files';
	
	/**
	 * Soubor
	 * @column
	 */
	public ?string $fileName;

	/**
	 * Původní název
	 * @column
	 */
	public ?string $originalFileName;
	
	/**
	 * Popisek
	 * @column{"mutations":true}
	 */
	public ?string $label;
	
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
	 * @constraint{"onUpdate":"CASCADE","onDelete":"CASCADE"}
	 * @relation
	 */
	public Product $product;
}
