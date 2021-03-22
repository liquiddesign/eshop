<?php

declare(strict_types=1);

namespace Eshop\DB;

use StORM\Collection;

/**
 * Kategorie
 * @table
 * @index{"name":"category_path","unique":true,"columns":["path"]}
 */
class Category extends \StORM\Entity
{
	public const IMAGE_DIR = 'category_images';

	/**
	 * Kód
	 * @column
	 */
	public ?string $code;

	/**
	 * Název
	 * @column{"mutations":true}
	 */
	public ?string $name;

	/**
	 * Cesta
	 * @column
	 */
	public string $path;

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
	 * Obrázek
	 * @column
	 */
	public ?string $imageFileName;

	/**
	 * Záložní obrázek pro produkty
	 * @column
	 */
	public ?string $productFallbackImageFileName;

	/**
	 * Exportní název pro Google
	 * @column
	 */
	public ?string $exportGoogleCategory;

	/**
	 * Export název pro Heuréku
	 * @column
	 */
	public ?string $exportHeurekaCategory;

	/**
	 * Export název pro Zbozi
	 * @column
	 */
	public ?string $exportZboziCategory;

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
	 * Systémová
	 * @column
	 */
	public bool $systemic = false;


	public function isSystemic(): bool
	{
		return $this->systemic;
	}

	/**
	 * Pomocí repositářové metody getTree(array $orderBy)
	 * @var \Eshop\DB\Category[]
	 */
	public array $children = [];

	/**
	 * Nadřazený
	 * @relation
	 * @constraint{"onUpdate":"CASCADE","onDelete":"CASCADE"}
	 */
	public ?Category $ancestor;

	/**
	 * Kategorie parametrů
	 * @relation
	 * @constraint{"onUpdate":"CASCADE","onDelete":"CASCADE"}
	 */
	public ?ParameterCategory $parameterCategory;

	public function isBottom()
	{
		return $this->getRepository()->many()->where('fk_ancestor', $this->uuid)->isEmpty();
	}

	public function getFamilyTree(bool $asc = true): Collection
	{
		return $this->getRepository()->many()->where(":path LIKE CONCAT(this.path,'%')", ['path' => $this->path])->orderBy(['LENGTH(path)' => $asc ? 'ASC' : 'DESC']);
	}

	public function getDescendants(?int $level = null)
	{
		$collection = $this->getRepository()->many()->where("this.path LIKE :path", ['path' => $this->path . '%'])->whereNot('this.uuid', $this->getPK());

		if ($level !== null) {
			$this->getRepository()->many()->where("LENGTH(path) / 4 >= :level", ['level' => $level + 1]);
		}

		return $collection->orderBy(['LENGTH(path)' => 'ASC']);
	}

	public function getParentPath(int $level)
	{
		return \substr($this->path, 0, 4 * ($level + 1));
	}

	public function getFallbackImage(?Category $category = null): ?string
	{
		if (!$category) {
			$category = $this;
		}

		if ($category->productFallbackImageFileName) {
			return $category->productFallbackImageFileName;
		}

		if ($category->ancestor) {
			return $this->getFallbackImage($category->ancestor);
		}

		return null;
	}
}