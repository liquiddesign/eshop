<?php

declare(strict_types=1);

namespace Eshop\DB;

use Eshop\Common\DB\SystemicEntity;
use Nette\Utils\Strings;
use StORM\Collection;
use StORM\ICollection;
use StORM\RelationCollection;

/**
 * Kategorie
 * @table
 * @index{"name":"category_path","unique":true,"columns":["path", "fk_type"]}
 * @index{"name":"category_path_no_uniq","unique":false,"columns":["path"]}
 */
class Category extends SystemicEntity
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
	 * Alternativní název
	 * @column{"mutations":true}
	 */
	public ?string $alternativeName;
	
	/**
	 * Celý název
	 * @column{"mutations":true}
	 */
	public ?string $fullName;

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
	 * OG Obrázek
	 * @column
	 */
	public ?string $ogImageFileName;

	/**
	 * Exportní název pro Googlecomp
	 * @column
	 */
	public ?string $exportGoogleCategory;
	
	/**
	 * Exportní ID kategorie Google
	 * @column
	 */
	public ?string $exportGoogleCategoryId;

	/**
	 * Kategorie pro Heuréku
	 * @relation
	 * @constraint{"onUpdate":"CASCADE","onDelete":"SET NULL"}
	 */
	public ?Category $exportHeurekaCategory;

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
	 * Zobrazit pokud nemá produkty
	 * @column
	 */
	public bool $showEmpty = true;

	/**
	 * Systémová
	 * @column
	 * @deprecated Use SystemicEntity
	 */
	public bool $systemic = false;

	/**
	 * Zobrazit produkty v podkategoriích
	 * @column
	 */
	public bool $showDescendantProducts = true;

	/**
	 * Pomocí repositářové metody getTree(array $orderBy)
	 * @var array<\Eshop\DB\Category>
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
	 * ID
	 * column - don't created by auto migration, only by manual
	 */
	public int $id;

	/**
	 * @column{"type":"enum","length":"'row','card'"}
	 */
	public string|null $defaultViewType;

	/**
	 * Kategorie
	 * @relation
	 * @var \StORM\RelationCollection<\Eshop\DB\Category>
	 */
	public RelationCollection $producerCategories;

	public function isSystemic(): bool
	{
		if ($this->type->isReadOnly()) {
			return true;
		}

		return $this->systemic || parent::isSystemic();
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
		return Strings::substring($this->path, 0, 4 * ($level + 1));
	}

	/**
	 * @param array<mixed> $filters
	 * @param array<string, string>|array<string, \Eshop\DB\Pricelist> $priceLists
	 * @param array<string, string>|array<string, \Eshop\DB\VisibilityList> $visibilityLists
	 * @throws \StORM\Exception\NotFoundException
	 * @throws \Throwable
	 */
	public function getProductCount(array $filters = [], array $priceLists = [], array $visibilityLists = []): int|null
	{
		/** @var \Eshop\DB\CategoryRepository $repository */
		$repository = $this->getRepository();

		return $repository->getCounts($this->path, $filters, $priceLists, $visibilityLists);
	}
}
