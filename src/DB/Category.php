<?php

declare(strict_types=1);

namespace Eshop\DB;

use StORM\Collection;
use StORM\ICollection;
use StORM\RelationCollection;

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
	 * Perex
	 * @column{"type":"text","mutations":true}
	 */
	public ?string $defaultProductPerex;

	/**
	 * Obsah
	 * @column{"type":"longtext","mutations":true}
	 */
	public ?string $defaultProductContent;

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
	 * Kategorie zboží
	 * @relation
	 * @constraint{"onUpdate":"CASCADE","onDelete":"SET NULL"}
	 */
	public ?Category $exportZboziCategory;

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
	 * Zobrazit v menu
	 * @column
	 */
	public bool $showInMenu = true;

	/**
	 * Systémová
	 * @column
	 */
	public bool $systemic = false;

	/**
	 * Pomocí repositářové metody getTree(array $orderBy)
	 * @var \Eshop\DB\Category[]
	 */
	public $children = [];

	/**
	 * Nadřazený
	 * @relation
	 * @constraint{"onUpdate":"CASCADE","onDelete":"CASCADE"}
	 */
	public ?Category $ancestor;

	/**
	 * Zařazení do menu
	 * @relation
	 * @constraint{"onUpdate":"CASCADE","onDelete":"CASCADE"}
	 */
	public CategoryType $type;

	/**
	 * Kategorie
	 * @relationNxN
	 * @var \StORM\RelationCollection<\Eshop\DB\ParameterCategory>|\Eshop\DB\ParameterCategory[]
	 */
	public RelationCollection $parameterCategories;

	public function isSystemic(): bool
	{
		if ($this->type->isReadOnly()) {
			return true;
		}

		return $this->systemic;
	}

	public function isBottom(): bool
	{
		return $this->getRepository()->many()->where('fk_ancestor', $this->getPK())->isEmpty();
	}

	public function getFamilyTree(bool $asc = true): Collection
	{
		return $this->getRepository()->many()->where(":path LIKE CONCAT(this.path,'%')", ['path' => $this->path])->orderBy(['LENGTH(path)' => $asc ? 'ASC' : 'DESC']);
	}

	public function getDescendants(?int $level = null): ICollection
	{
		$collection = $this->getRepository()->many()->where('this.path LIKE :path', ['path' => $this->path . '%'])->whereNot('this.uuid', $this->getPK());

		if ($level !== null) {
			$this->getRepository()->many()->where('LENGTH(path) / 4 >= :level', ['level' => $level + 1]);
		}

		return $collection->orderBy(['LENGTH(path)' => 'ASC']);
	}

	public function getParentPath(int $level): string
	{
		return \substr($this->path, 0, 4 * ($level + 1));
	}

	public function getProductCount(): ?int
	{
		/** @var \Eshop\DB\CategoryRepository $repository */
		$repository = $this->getRepository();

		return $repository->getCounts($this->path);
	}

	/**
	 * @deprecated User property instead
	 */
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
