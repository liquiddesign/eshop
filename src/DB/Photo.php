<?php

declare(strict_types=1);

namespace Eshop\DB;

use Nette\Application\ApplicationException;
use Nette\Utils\Arrays;

/**
 * Fotografie k produktu
 * @table
 */
class Photo extends \StORM\Entity
{
	/**
	 * Soubor
	 * @column{"type":"longtext"}
	 */
	public ?string $fileName;

	/**
	 * Původní název
	 * @column{"type":"longtext"}
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

	/**
	 * Dodavatel / externí
	 * @relation
	 * @constraint{"onUpdate":"CASCADE","onDelete":"SET NULL"}
	 */
	public ?Supplier $supplier;

	/**
	 * Use in googleFeed
	 * @column
	 */
	public bool $googleFeed = false;
	
	public function getImagePath(string $basePath, string $size = 'detail'): string
	{
		if (!Arrays::contains(['origin', 'detail', 'thumb'], $size)) {
			throw new ApplicationException('Invalid product image size: ' . $size);
		}
		
		return $this->fileName ? $basePath . '/userfiles/' . Product::GALLERY_DIR . '/' . $size . '/' . $this->fileName : $basePath . '/public/img/no-image.png';
	}
}
