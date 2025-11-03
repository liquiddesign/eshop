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
	 * Povolené mutace
	 * @column
	 */
	public ?string $mutations;

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

	public function getFilePath(string $basePath): ?string
	{
		if (!$this->fileName) {
			return null;
		}

		$mutationsDir = $this->mutations ? $this->mutations . '/' : '';

		return $basePath . '/userfiles/' . self::FILE_DIR . '/' . $mutationsDir . $this->fileName;
	}
}
