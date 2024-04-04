<?php

declare(strict_types=1);

namespace Eshop\DB;

use Eshop\Common\DB\SystemicEntity;
use Nette\Application\ApplicationException;
use Nette\Utils\Arrays;
use StORM\RelationCollection;

/**
 * Výrobce
 * @table
 * @index{"name":"producer_code_unique","unique":true,"columns":["code"]}
 */
class Producer extends SystemicEntity
{
	public const IMAGE_DIR = 'producer_images';

	/**
	 * Kód
	 * @column
	 * @unique
	 */
	public ?string $code;

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

	/**
	 * ID
	 * column - don't created by auto migration, only by manual
	 */
	public int $id;

	/**
	 * Hlavní přiřazená kategorie
	 * @relation
	 * @constraint{"onUpdate":"CASCADE","onDelete":"SET NULL"}
	 * @deprecated use mainCategories
	 */
	public ?Category $mainCategory;

	/**
	 * @relationNxN
	 * @var \StORM\RelationCollection<\Eshop\DB\Category>
	 */
	public RelationCollection $mainCategories;

	public function getPreviewImage(string $basePath, string $size = 'detail'): string
	{
		if (!Arrays::contains(['origin', 'detail', 'thumb'], $size)) {
			throw new ApplicationException('Invalid product image size: ' . $size);
		}

		return $this->imageFileName ? $basePath . '/userfiles/' . self::IMAGE_DIR . '/' . $size . '/' . $this->imageFileName : $basePath . '/public/img/no-image.png';
	}
}
