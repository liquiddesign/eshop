<?php

declare(strict_types=1);

namespace Eshop\DB;

use Common\DB\IGeneralRepository;
use Eshop\Shopper;
use Nette\Caching\Cache;
use Nette\Caching\Storage;
use StORM\Collection;
use StORM\DIConnection;
use StORM\SchemaManager;

/**
 * @extends \StORM\Repository<\Eshop\DB\Category>
 */
class CategoryRepository extends \StORM\Repository implements IGeneralRepository
{
	private Cache $cache;

	private Shopper $shopper;

	public function __construct(
		DIConnection $connection,
		SchemaManager $schemaManager,
		Shopper $shopper,
		Storage $storage
	)
	{
		parent::__construct($connection, $schemaManager);
		$this->cache = new Cache($storage);
		$this->shopper = $shopper;
	}

	public function clearCategoriesCache()
	{
		$this->cache->clean([
			Cache::TAGS => ['categories'],
		]);
	}

	/**
	 * @param string|null $typeId
	 * @param bool $cache
	 * @return \Eshop\DB\Category[]
	 * @throws \Throwable
	 */
	public function getTree(?string $typeId = null, bool $cache = true): array
	{
		$repository = $this;

		// @TODO: jen viditelne kategorie pro daneho uzivatele, cistit cache po prihlaseni, take dle jazyku (muze byt jine poradi)

		if ($cache) {
			return $this->cache->load("categoryTree-" . ($typeId ?: ''), function (&$dependencies) use ($repository, $typeId) {
				$dependencies = [
					Cache::TAGS => 'categories',
				];

				return $this->getTreeHelper($typeId, $repository);
			});
		} else {
			return $this->getTreeHelper($typeId, $repository);
		}
	}

	private function getTreeHelper($typeId, $repository)
	{
		$collection = $repository->getCategories()->where('LENGTH(path) <= 40');

		if ($typeId) {
			$collection->where('this.fk_type', $typeId);
		}

		return $repository->buildTree($collection->toArray(), null);
	}

	public function getCounts(array $pricelists = []): array
	{
		$currency = $this->shopper->getCurrency();
		$suffix = $this->getConnection()->getMutationSuffix();
		$pricelists = $pricelists ? $pricelists : \array_values($this->shopper->getPricelists($currency->isConversionEnabled() ? $currency->convertCurrency : null)->toArray());

		$cacheIndex = "catagories_counts$suffix";

		foreach ($pricelists as $pricelist) {
			$cacheIndex .= '_' . $pricelist->getPK();
		}

		$rows = $this->many();

		return $this->cache->load($cacheIndex, static function (&$dependencies) use ($rows, $suffix, $pricelists) {
			$dependencies = [
				Cache::TAGS => ['categories', 'products', 'pricelists'],
			];

			$rows->join(['subs' => 'eshop_category'], 'subs.path LIKE CONCAT(this.path,"%")')
				->join(['nxn' => 'eshop_product_nxn_eshop_category'], 'nxn.fk_category=subs.uuid')
				->join(['product' => 'eshop_product'],
					"nxn.fk_product=product.uuid AND product.hidden = 0 AND product.fk_alternative IS NULL")
				->setSelect(['count' => 'COUNT(product.uuid)'])
				->setGroupBy(['this.uuid']);

			$priceWhere = [];

			foreach ($pricelists as $id => $pricelist) {
				$rows->join(["prices$id" => 'eshop_price'],
					"prices$id.fk_product=product.uuid AND prices$id.fk_pricelist = '" . $pricelist->getPK() . "'");
				$priceWhere[] = "prices$id.price IS NOT NULL";
			}

			if ($priceWhere) {
				$rows->where(\implode(' OR ', $priceWhere));
			}

			$rows->setIndex('this.uuid');
			$rows->setFetchClass(\stdClass::class);

			return $rows->toArrayOf('count');
		});
	}

	/**
	 * Updates all paths of children of category.
	 * @param \Eshop\DB\Category $category
	 * @throws \Throwable
	 */
	public function updateCategoryChildrenPath(Category $category): void
	{
		if ($category->ancestor == null) {
			$this->clearCategoriesCache();
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

		$this->clearCategoriesCache();
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

	public function getTreeArrayForSelect(bool $includeHidden = true, string $type = null): array
	{
		$collection = $this->getCategories($includeHidden)->where('LENGTH(path) <= 40');

		if ($type) {
			$collection->where('fk_type', $type);
		}

		$list = [];
		$this->buildTreeArrayForSelect($collection->toArray(), null, $list);

		return $list;
	}

	/**
	 * @param \Eshop\DB\Category[] $elements
	 * @param string|null $ancestorId
	 * @param array $list
	 * @return \Eshop\DB\Category[]
	 */
	private function buildTreeArrayForSelect(array $elements, ?string $ancestorId = null, array &$list = []): array
	{
		$branch = [];

		foreach ($elements as $element) {
			if ($element->getValue('ancestor') === $ancestorId) {
				$list[$element->getPK()] = \str_repeat('--', (\strlen($element->path) / 4) - 1) . " $element->name";

				if ($children = $this->buildTreeArrayForSelect($elements, $element->getPK(), $list)) {
					$element->children = $children;
				}

				$branch[] = $element;
			}
		}

		return $branch;
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
	 * @param bool $includeHidden
	 * @return \StORM\Collection<\Eshop\DB\Category>|\Eshop\DB\Category[]
	 */
	public function getCategories(bool $includeHidden = false): Collection
	{
		$suffix = $this->getConnection()->getMutationSuffix();

		$collection = $this->many()->orderBy(['priority', "name$suffix"]);

		if (!$includeHidden) {
			$collection->where('hidden', false);
		}

		return $collection;
	}

	public function getRootCategoryOfCategory(Category $category): Category
	{
		if ($category->ancestor == null) {
			return $category;
		}

		return $this->many()
			->where('path', \substr($category->path, 0, 4))
			->first();
	}

	public function getBranch($category): array
	{
		if (!$category instanceof Category) {
			if (!$category = $this->one($category)) {
				return [];
			}
		}

		if ($category->ancestor == null) {
			return [$category->getPK() => $category];
		}

		$categories = [];

		do {
			$categories[$category->getPK()] = $category;
			$category = $category->ancestor;
		} while ($category != null);

		return \array_reverse($categories);
	}

	public function getParameterCategoriesOfCategory($category): ?Collection
	{
		if (!$category instanceof Category) {
			if (!$category = $this->one($category)) {
				return null;
			}
		}

		if ($category->ancestor == null) {
			return $category->parameterCategories;
		}

		do {
			if (\count($category->parameterCategories->toArray()) > 0) {
				return $category->parameterCategories;
			}

			$category = $category->ancestor;
		} while ($category != null);

		return null;
	}
}
