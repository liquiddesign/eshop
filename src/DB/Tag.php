<?php

declare(strict_types=1);

namespace Eshop\DB;

use StORM\RelationCollection;

/**
 * Tag
 * @table
 */
class Tag extends \StORM\Entity
{
	public const IMAGE_DIR = 'tag_images';
	
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
	 * Obrázek tagu
	 * @column
	 */
	public ?string $imageFileName;
	
	/**
	 * Priorita
	 * @column
	 */
	public int $priority = 10;
	
	/**
	 * Systemový
	 * @column
	 */
	public bool $systemic = false;
	
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
	 * Podobné tagy
	 * @relationNxN
	 * @var \Eshop\DB\Tag[]|\StORM\RelationCollection<\Eshop\DB\Tag>
	 */
	public RelationCollection $similar;
	
	public function getImageFileName(string $basePath): string
	{
		return $this->imageFileName ? $basePath . '/userfiles/' . self::IMAGE_DIR . '/detail/' . $this->imageFileName : $basePath . '/public/img/no-image.png';
	}
}
