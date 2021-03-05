<?php

declare(strict_types=1);

namespace Eshop\DB;

use IGeneralRepository;
use Nette\Caching\Cache;
use Nette\Caching\IStorage;
use Nette\Utils\Strings;
use StORM\Collection;
use StORM\DIConnection;
use StORM\SchemaManager;
use Tracy\Debugger;

/**
 * @extends \StORM\Repository<\Eshop\DB\Category>
 */
class CategoryRepository extends \StORM\Repository implements IGeneralRepository
{
	/**
	 * @var \Nette\Caching\Cache
	 */
	private Cache $cache;

	public function __construct(DIConnection $connection, SchemaManager $schemaManager, IStorage $storage)
	{
		parent::__construct($connection, $schemaManager);
		$this->cache = new Cache($storage);
	}

	/**
	 * @param string[] $orderBy
	 * @return \Eshop\DB\Category[]
	 */
	public function getTree(): array
	{
		$repository = $this;

		// @TODO: jen viditelne kategorie pro daneho uzivatele, cistit cache po prihlaseni, take dle jazyku (muze byt jine poradi)

		return $this->cache->load('categoryTree', static function (&$dependencies) use ($repository) {
			$dependencies = [
				Cache::TAGS => 'categories',
			];

			return $repository->buildTree($repository->getCategories()->where('LENGTH(path) <= 40')->toArray(), null);
		});
	}

	/**
	 * Updates all paths of children of category.
	 * @param \Eshop\DB\Category $category
	 */
	public function updateCategoryChildrenPath(Category $category): void
	{
		if ($category->ancestor == null) {
			$this->cache->clean([
				Cache::TAGS => ["categories"],
			]);
		}

		$tree = $this->getTree();

		foreach ($tree as $item) {
			if ($item->getPK() == $category->getPK()) {
				$startCategory = $item;
				break;
			}

			if (\str_contains($category->path, $item->path)) {
				$startCategory = $this->findCategoryInTree($item, $category);

				if ($startCategory) {
					break;
				}
			}
		}

		if (isset($startCategory)) {
			$startCategory->setParent($this);
			$startCategory->update(['path' => $category->path]);

			foreach ($startCategory->children as $child) {
				$child->setParent($this);
				$child->update(['path' => $startCategory->path . \substr($child->path, -4)]);

				if (\count($child->children) > 0) {
					$this->doUpdateCategoryChildrenPath($child);
				}
			}
		}

		$this->cache->clean([
			Cache::TAGS => ["categories"],
		]);
	}

	private function findCategoryInTree(Category $category, Category $targetCategory): ?Category
	{
		foreach ($category->children as $child) {
			if ($child->getPK() == $targetCategory->getPK()) {
				return $child;
			}

			$returnCategory = $this->findCategoryInTree($child, $targetCategory);

			if ($returnCategory) {
				return $returnCategory;
			}
		}

		return null;
	}

	private function doUpdateCategoryChildrenPath(Category $category): void
	{
		foreach ($category->children as $child) {
			$child->setParent($this);
			$child->update(['path' => $category->path . \substr($child->path, -4)]);

			if (\count($child->children) > 0) {
				$this->doUpdateCategoryChildrenPath($child);
			}
		}
	}

	/**
	 * @param \Eshop\DB\Category[] $elements
	 * @param string|null $ancestorId
	 * @return \Eshop\DB\Category[]
	 */
	private function buildTree(array $elements, ?string $ancestorId = null): array
	{
		$branch = [];

		foreach ($elements as $element) {
			if ($element->getValue('ancestor') === $ancestorId) {
				if ($children = $this->buildTree($elements, $element->getPK())) {
					$element->children = $children;
				}

				$branch[] = $element;
			}
		}

		return $branch;
	}

	public function getArrayForSelect(bool $includeHidden = true): array
	{
		$suffix = $this->getConnection()->getMutationSuffix();

		return $this->many()->orderBy(["name$suffix"])->toArrayOf('name');
	}

	public function getCollection(bool $includeHidden = false): Collection
	{
		$suffix = $this->getConnection()->getMutationSuffix();
		$collection = $this->many();

		if (!$includeHidden) {
			$collection->where('hidden', false);
		}

		return $collection->orderBy(['priority', "name$suffix"]);
	}

	/**
	 * @return \StORM\Collection<\Eshop\DB\Category>|\Eshop\DB\Category[]
	 */
	public function getCategories(): Collection
	{
		$suffix = $this->getConnection()->getMutationSuffix();

		return $this->many()->where('hidden', false)->orderBy(['priority', "name$suffix"]);
	}

	public function getRootCategoryOfCategory(Category $category): Category
	{
		if ($category->ancestor == null) {
			return $category;
		}

		return $this->many()
			->where('path', \substr($category->path, 0, 4))
			->fetch();
	}

	public function getParameterCategoryOfCategory(Category $category): ?ParameterCategory
	{
		if ($category->ancestor == null) {
			return $category->parameterCategory;
		}

		do {
			if ($category->parameterCategory) {
				return $category->parameterCategory;
			}

			$category = $category->ancestor;
		} while ($category != null);

		return null;
	}
}
