<?php

declare(strict_types=1);

namespace Eshop\DB;

use Nette\Application\ApplicationException;

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

	public function getPreviewImage(string $basePath, string $size = 'detail'): string
	{
		if (!\in_array($size, ['origin', 'detail', 'thumb'])) {
			throw new ApplicationException('Invalid product image size: ' . $size);
		}

		return $this->imageFileName ? $basePath . '/userfiles/' . self::IMAGE_DIR . '/' . $size . '/' . $this->imageFileName : $basePath . '/public/img/no-image.png';
	}
}