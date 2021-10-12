<?php

declare(strict_types=1);

namespace Eshop\DB;

use Common\DB\IGeneralRepository;
use Eshop\Shopper;
use Nette\Caching\Cache;
use Nette\Caching\Storage;
use Nette\Utils\Arrays;
use Nette\Utils\Random;
use Nette\Utils\Strings;
use Pages\Helpers;
use StORM\ArrayWrapper;
use StORM\Expression;
use Web\DB\Page;
use Web\DB\PageRepository;
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

	private PageRepository $pageRepository;

	private ProducerRepository $producerRepository;

	private ProductRepository $productRepository;

	public array $categoryMap;

	public function __construct(
		DIConnection       $connection,
		SchemaManager      $schemaManager,
		Shopper            $shopper,
		Storage            $storage,
		PageRepository     $pageRepository,
		ProducerRepository $producerRepository,
		ProductRepository  $productRepository
	)
	{
		parent::__construct($connection, $schemaManager);
		$this->cache = new Cache($storage);
		$this->shopper = $shopper;
		$this->pageRepository = $pageRepository;
		$this->producerRepository = $producerRepository;
		$this->productRepository = $productRepository;
	}

	public function clearCategoriesCache()
	{
		$this->cache->clean([
			Cache::TAGS => ['categories'],
		]);
	}

	public function getProducerPages(bool $includeInactive = true): array
	{
		return $this->cache->load('categoryProducerPages', function (&$dependencies) use ($includeInactive) {
			$dependencies = [
				Cache::TAGS => 'categories',
			];

			$mutationSuffix = $this->pageRepository->getConnection()->getMutationSuffix();

			$pages = $this->pageRepository->many()->where('type', 'product_list')->setOrderBy(['this.priority']);

			if (!$includeInactive) {
				$pages->where("active$mutationSuffix", true);
			}

			$producerPages = [];

			/** @var Page $page */
			while ($page = $pages->fetch()) {
				$params = $page->getParsedParameters();

				if (!isset($params['category']) || !isset($params['producer'])) {
					continue;
				}

				$producerPages[$params['category']][] = [$page, $this->producerRepository->one($params['producer'])];
			}

			return $producerPages;
		});
	}

	public function getTree(string $typeId = 'main', bool $cache = true, bool $includeHidden = false): ArrayWrapper
	{
		$repository = $this;

		$result = $this->cache->load("categoryTree-$typeId", function (&$dependencies) use ($repository, $typeId, $includeHidden) {
			$dependencies = [
				Cache::TAGS => 'categories',
			];

			return [
				'tree' => $this->getTreeHelper($typeId, $repository, $includeHidden),
				'map' => $this->categoryMap[$typeId],
			];
		});
		$this->categoryMap[$typeId] ??= $result['map'];
		$result = new ArrayWrapper($result['tree'], $this->getRepository(), ['children' => $this->getRepository()], true);

		return $result;
	}

	private function getTreeHelper($typeId, CategoryRepository $repository, bool $includeHidden = false)
	{
		$collection = $repository->getCategories($includeHidden)->where('LENGTH(path) <= 40');

		if ($typeId) {
			$collection->where('this.fk_type', $typeId);
		}

		return $repository->buildTree($collection->toArray(), null, $typeId);
	}

	public function getCountsGrouped(?string $groupBy = null, array $filters = [], ?array $pricelists = null): array
	{
		if ($pricelists === null) {
			$pricelists = $this->shopper->getPricelists()->toArray();
		}

		unset($filters['category']);
		\ksort($filters);
		$cacheIndex = $groupBy . \implode('_', \array_keys($pricelists)) . \http_build_query($filters);
		$rows = $this->many();
		$productRepository = $this->getConnection()->findRepository(Product::class);

		return $this->cache->load($cacheIndex, static function (&$dependencies) use ($rows, $groupBy, $productRepository, $pricelists, $filters) {
			$dependencies = [
				Cache::TAGS => ['categories', 'products', 'pricelists', 'attributes'],
			];

			$rows->setFrom(['category' => 'eshop_category']);
			$rows->setSmartJoin(true, Product::class);
			$rows->setFetchClass(\stdClass::class);

			$groupByClause = ['category.uuid'];
			$selectClause = ['category' => 'category.path', 'count' => 'COUNT(this.uuid)'];

			if ($groupBy) {
				$selectClause['grouped'] = $groupBy;
				$groupByClause[] = $groupBy;
			}

			$subSelect = "SELECT fk_product FROM eshop_product_nxn_eshop_category JOIN eshop_category ON eshop_category.uuid=eshop_product_nxn_eshop_category.fk_category WHERE eshop_category.path LIKE CONCAT(category.path,'%')";
			$rows->join(['this' => 'eshop_product'], "this.fk_primaryCategory=category.uuid OR this.uuid IN ($subSelect)")
				->setSelect($selectClause)
				->setGroupBy($groupByClause)
				->where('this.hidden=0');

			if ($groupBy === 'assign.fk_value') {
				$rows->join(['assign' => 'eshop_attributeassign'],
					"assign.fk_product=this.uuid");
			}

			$priceWhere = new Expression();

			foreach (\array_keys($pricelists) as $id => $pricelist) {
				$rows->join(["prices$id" => 'eshop_price'], "prices$id.fk_product=this.uuid AND prices$id.fk_pricelist = '" . $pricelist . "'");
				$priceWhere->add('OR', "prices$id.price IS NOT NULL");
			}

			if ($priceWhere->getSql()) {
				$rows->where($priceWhere->getSql());
			}

			$productRepository->filter($rows, $filters);

			if ($groupBy === null) {
				return $rows->setIndex('category')->toArrayOf('count');
			}

			$results = [];

			/** @var \stdClass $result */
			foreach ($rows->toArray() as $result) {
				$results[$result->category] ??= [];
				$results[$result->category][$result->grouped] = (int)$result->count;
			}

			return $results;
		});
	}

	/**
	 * @deprecated User getCountsByAttributes instead
	 */
	public function getCounts(array $pricelists = []): array
	{
		$currency = $this->shopper->getCurrency();
		$suffix = $this->getConnection()->getMutationSuffix();
		$pricelists = $pricelists ? $pricelists : \array_values($this->shopper->getPricelists($currency->isConversionEnabled() ? $currency->convertCurrency : null)->toArray());

		if (\count($pricelists) == 0) {
			return [];
		}

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

	public function generateUniquePath(string $prefix = ''): string
	{
		do {
			$random = $prefix . Random::generate(4);
			$tempCategory = $this->many()->where('path', $random)->first();
		} while ($tempCategory);

		return $random;
	}

	/**
	 * Updates all paths of children of category.
	 * @param \Eshop\DB\Category $category
	 * @param string|null $typeId
	 * @throws \StORM\Exception\NotFoundException
	 */
	public function updateCategoryChildrenPath(Category $category, ?string $typeId = null): void
	{
		if ($typeId) {
			$type = $typeId;
		} else {
			if (!$category->getValue('type')) {
				$category = $this->one($category->getPK());
			}

			$type = $category->getValue('type');
		}

		if (!$type) {
			throw new \InvalidArgumentException('Invalid category type!');
		}

		/** @var Category[] $tree */
		$tree = $this->getTree($type, false, true);

		$startCategory = null;

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

		if ($startCategory) {
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
	private function buildTree(array $elements, ?string $ancestorId, string $typeId): array
	{
		$branch = [];

		foreach ($elements as $element) {
			if ($element->getValue('ancestor') === $ancestorId) {
				if ($children = $this->buildTree($elements, $element->getPK(), $typeId)) {
					$element->children = $children;
				}

				$branch[] = $element;
				$this->categoryMap[$typeId] ??= [];
				$this->categoryMap[$typeId][$element->path] = $element;
			}
		}

		return $branch;
	}

	public function getCategoryByPath(string $typeId, string $path): ?Category
	{
		return $this->categoryMap[$typeId][$path] ?? null;
	}

	public function isTreeBuild(string $typeId): bool
	{
		return isset($this->categoryMap[$typeId]);
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

	public function generateCategoryProducerPages(?array $activeProducers = null)
	{
		/** @var Category[] $categories */
		$categories = $this->getCollection(true);

		foreach ($categories as $category) {
			/** @var Producer[] $producers */
			$producers = $this->producerRepository->many()
				->join(['product' => 'eshop_product'], 'product.fk_producer = this.uuid')
				->join(['nxnCategory' => 'eshop_product_nxn_eshop_category'], 'nxnCategory.fk_product = product.uuid')
				->where('nxnCategory.fk_category', \array_keys($this->getBranch($category)));

			foreach ($producers as $producer) {
				$page = $this->pageRepository->getPageByTypeAndParams('product_list', null, ['category' => $category->getPK(), 'producer' => $producer->getPK()]);

				if ($page) {
					continue;
				}

				$values = [];

				$activeProducer = \array_search($producer->code, $activeProducers);

				foreach ($this->getConnection()->getAvailableMutations() as $mutation => $suffix) {
					$urlMutation = $category->getValue('name', $mutation) && $producer->getValue('name', $mutation) ?
						$category->getValue('name', $mutation) . '-' . $producer->getValue('name', $mutation) :
						$category->name . '-' . $producer->name;
					$urlMutation = Strings::webalize($urlMutation);

					while (!$this->pageRepository->isUrlAvailable($urlMutation, $mutation)) {
						$urlMutation .= '-' . Random::generate(4);
					}

					$values['url'][$mutation] = $urlMutation;

					$nameTitle = $category->getValue('name', $mutation) && $producer->getValue('name', $mutation) ?
						$category->getValue('name', $mutation) . ' ' . $producer->getValue('name', $mutation) :
						$category->name . '-' . $producer->name;

					$values['name'][$mutation] = $nameTitle;
					$values['title'][$mutation] = $nameTitle;
					$values['active'][$mutation] = $activeProducers === null || $activeProducer !== false;
				}


				$values['type'] = 'product_list';
				$values['priority'] = $activeProducers === null || $activeProducer === false ? 10 : $activeProducer;
				$values['params'] = Helpers::serializeParameters(['category' => $category->getPK(), 'producer' => $producer->getPK()]);

				$this->pageRepository->syncOne($values);
			}
		}

		$this->clearCategoriesCache();
	}

	public function generateProducerCategories(array $categories, ?array $activeProducers = null)
	{
		$connection = $this->getConnection();
		$mutations = $connection->getAvailableMutations();

		/** @var Category[] $categories */
		$categories = $this->many()->where('uuid', $categories);

		foreach ($categories as $category) {
			/** @var Producer[] $producers */
			$producers = $this->producerRepository->many()
				->join(['product' => 'eshop_product'], 'product.fk_producer = this.uuid', [], 'INNER')
				->join(['nxnCategory' => 'eshop_product_nxn_eshop_category'], 'nxnCategory.fk_product = product.uuid')
				->where('nxnCategory.fk_category', $category->getPK())
				->toArray();

			foreach ($producers as $producer) {
				$values = [];

				$activeProducer = \array_search($producer->code, $activeProducers);

				foreach ($mutations as $mutation => $suffix) {
					$urlMutation = null;

					if ($category->getValue('name', $mutation) && $producer->getValue('name', $mutation)) {
						$urlMutation = Strings::webalize($category->getValue('name', $mutation) . '-' . $producer->getValue('name', $mutation));

						while (!$this->pageRepository->isUrlAvailable($urlMutation, $mutation)) {
							$urlMutation .= '-' . Random::generate(4);
						}
					}

					$values['url'][$mutation] = $urlMutation;
					$values['title'][$mutation] = $category->getValue('name', $mutation) && $producer->getValue('name', $mutation) ? $category->getValue('name', $mutation) . ' ' . $producer->getValue('name', $mutation) : null;
					$values['active'][$mutation] = true;
				}

				/** @var Category $producerCategory */
				$producerCategory = $this->syncOne([
					'code' => $category->code && $producer->code ? $category->code . '-' . $producer->code : null,
					'path' => $this->generateUniquePath($category->path),
					'ancestor' => $category->getPK(),
					'name' => $values['title'],
					'hidden' => !($activeProducers === null || $activeProducer !== false),
					'type' => $category->getValue('type')
				], null, true);

				$values['type'] = 'product_list';
				$values['params'] = Helpers::serializeParameters(['category' => $producerCategory->getPK()]);

				$this->pageRepository->syncOne($values);

				/** @var Product[] $products */
				$products = $this->productRepository->many()
					->join(['nxnCategory' => 'eshop_product_nxn_eshop_category'], 'nxnCategory.fk_product = this.uuid')
					->where('nxnCategory.fk_category', $category->getPK())
					->where('this.fk_producer', $producer->getPK())
					->toArray();

				foreach ($products as $product) {
					$product->categories->relate([$producerCategory->getPK()]);
				}
			}
		}

		$this->clearCategoriesCache();
	}
}
