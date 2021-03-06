<?php

declare(strict_types=1);

namespace Eshop\DB;

use Nette\Application\ApplicationException;

/**
 * Fotografie k produktu
 * @table
 */
class Photo extends \StORM\Entity
{
	public const IMAGE_DIR = 'product_gallery_images';
	
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
	
	public function getImagePath(string $basePath, string $size = 'detail'): string
	{
		if (!\in_array($size, ['origin', 'detail', 'thumb'])) {
			throw new ApplicationException('Invalid product image size: '. $size);
		}
		
		return $this->fileName ? $basePath .'/userfiles/' . self::IMAGE_DIR . '/' . $size . '/' . $this->fileName : $basePath . '/public/img/no-image.png';
	}
}
