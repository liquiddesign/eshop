<?php

declare(strict_types=1);

namespace Eshop\DB;

use Common\DB\IGeneralRepository;
use Eshop\ShopperUser;
use Latte\Loaders\StringLoader;
use Latte\Sandbox\SecurityPolicy;
use League\Csv\Reader;
use League\Csv\Writer;
use Nette\Bridges\ApplicationLatte\LatteFactory;
use Nette\Bridges\ApplicationLatte\UIExtension;
use Nette\Caching\Cache;
use Nette\Caching\Storage;
use Nette\Utils\Arrays;
use Nette\Utils\Random;
use Nette\Utils\Strings;
use Pages\Helpers;
use StORM\ArrayWrapper;
use StORM\Collection;
use StORM\DIConnection;
use StORM\SchemaManager;
use Web\DB\PageRepository;

/**
 * @extends \StORM\Repository<\Eshop\DB\Category>
 */
class CategoryRepository extends \StORM\Repository implements IGeneralRepository
{
	/**
	 * @var array<array<object>>
	 */
	public array $categoryMap;

	private Cache $cache;
	
	public function __construct(
		protected DIConnection $connection,
		protected SchemaManager $schemaManager,
		private readonly ShopperUser $shopperUser,
		Storage $storage,
		private readonly PageRepository $pageRepository,
		private readonly ProducerRepository $producerRepository,
		private readonly ProductRepository $productRepository,
		private readonly LatteFactory $latteFactory,
		/** @var array<int> */
		private readonly array $preloadCategoryCounts = []
	) {
		parent::__construct($connection, $schemaManager);

		$this->cache = new Cache($storage);
	}

	public function getCounts(string $path): ?int
	{
		$levels = $this->preloadCategoryCounts;
		$stm = $this->connection;
		$productRepository = $this->productRepository;
		
		if (!Arrays::contains($levels, Strings::length($path) / 4)) {
			return null;
		}
		
		$result = $this->cache->load($this->shopperUser->getPriceCacheIndex('categories'), static function (&$dependencies) use ($stm, $productRepository, $levels) {
			$dependencies = [
				Cache::TAGS => ['categories', 'products', 'pricelists'],
			];
			$result = [];
			
			foreach ($levels as $level) {
				$rows = $stm->rows(['nxn' => 'eshop_product_nxn_eshop_category'], ['uuid' => 'nxn.fk_category', 'path' => 'category.path', 'total' => 'COUNT(DISTINCT nxn.fk_product)'])
					->join(['this' => 'eshop_product'], 'this.uuid=nxn.fk_product')
					->join(['category' => 'eshop_category'], 'category.uuid=nxn.fk_category')
					->setGroupBy(['SUBSTR(category.path,1,:level)'], null, ['level' => $level * 4]);
				$productRepository->setProductsConditions($rows, false);
				$productRepository->joinVisibilityListItemToProductCollection($rows);
				
				$result += $rows->setIndex('SUBSTR(category.path,1,:level)')->toArrayOf('total');
			}
			
			return $result;
		});
		
		return (int) ($result[$path] ?? 0);
	}

	public function getTree(string $typeId = 'main', bool $cache = true, bool $includeHidden = false, bool $onlyMenu = false): ArrayWrapper
	{
		unset($cache);

		$repository = $this;

		$result = $this->cache->load("categoryTree-$typeId", function (&$dependencies) use ($repository, $typeId, $includeHidden, $onlyMenu) {
			$dependencies = [
				Cache::TAGS => 'categories',
			];

			return [
				'tree' => $this->getTreeHelper($typeId, $repository, $includeHidden, $onlyMenu),
				'map' => $this->categoryMap[$typeId] ?? [],
			];
		});
		$this->categoryMap[$typeId] ??= $result['map'];
		$result = new ArrayWrapper($result['tree'], $this->getRepository(), ['children' => $this->getRepository()], true);

		return $result;
	}

	/**
	 * @param bool $includeHidden
	 * @return \StORM\Collection<\Eshop\DB\Category>
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

	public function clearCategoriesCache(): void
	{
		$this->cache->clean([
			Cache::Tags => ['categories'],
		]);
	}

	public function getCategoryByPath(string $typeId, string $path): ?Category
	{
		return $this->categoryMap[$typeId][$path] ?? null;
	}

	public function isTreeBuild(string $typeId): bool
	{
		return isset($this->categoryMap[$typeId]);
	}

	/**
	 * @inheritDoc
	 */
	public function getArrayForSelect(bool $includeHidden = true): array
	{
		unset($includeHidden);

		$suffix = $this->getConnection()->getMutationSuffix();

		return $this->many()->orderBy(["name$suffix"])->toArrayOf('name');
	}

	/**
	 * @param bool $includeHidden
	 * @param string|null $type
	 * @return array<string>
	 */
	public function getTreeArrayForSelect(bool $includeHidden = true, ?string $type = null): array
	{
		$repository = $this;

		return $this->cache->load(($includeHidden ? '1' : '0') . "_$type", static function (&$dependencies) use ($includeHidden, $type, $repository) {
			$dependencies = [
				Cache::TAGS => ['categories'],
			];
			$collection = $repository->getCategories($includeHidden)->where('LENGTH(path) <= 40');

			if ($type) {
				$collection->where('fk_type', $type);
			}

			$list = [];

			/** @var \Eshop\DB\Category $category */
			foreach ($collection as $category) {
				$currentCategories = [];
				$currentCategory = $category;

				while ($currentCategory !== null) {
					$currentCategories[] = $currentCategory->name;
					$currentCategory = $currentCategory->ancestor;
				}

				$currentCategories = \array_reverse($currentCategories);

				$list[$category->getPK()] = $category->type->name . ': ' . \implode(' -> ', $currentCategories) . ' (' . $category->code . ($category->isSystemic() ? ', systémová' : '') . ')';
			}

			return $list;
		});
	}

	/**
	 * @throws \StORM\Exception\NotFoundException
	 * @deprecated low performance, use getBranch($category)
	 */
	public function getRootCategoryOfCategory(Category $category): Category
	{
		if ($category->ancestor === null) {
			return $category;
		}

		return $this->many()
			->where('path', Strings::substring($category->path, 0, 4))
			->first();
	}

	public function generateCategoryProducerPages(?array $activeProducers = null): void
	{
		/** @var array<\Eshop\DB\Category> $categories */
		$categories = $this->getCollection(true);

		foreach ($categories as $category) {
			/** @var array<\Eshop\DB\Producer> $producers */
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

				foreach (\array_keys($this->getConnection()->getAvailableMutations()) as $mutation) {
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

	/**
	 * @param bool $includeHidden
	 * @return \StORM\Collection<\Eshop\DB\Category>
	 */
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
	 * @param \Eshop\DB\Category|string $category
	 * @param array<\Eshop\DB\Category>|null $allCategories
	 * @return array<\Eshop\DB\Category>
	 * @throws \StORM\Exception\NotFoundException
	 */
	public function getBranch($category, ?array $allCategories = null): array
	{
		if (!$category instanceof Category) {
			if (!$category = $this->one($category)) {
				return [];
			}
		}

		if ($category->getValue('ancestor') === null) {
			return [$category->getPK() => $category];
		}

		$categories = [];

		do {
			$categories[$category->getPK()] = $category;
			$category = $allCategories[$category->getValue('ancestor')] ?? $category->ancestor;
		} while ($category !== null);

		return \array_reverse($categories);
	}

	/**
	 * @param \StORM\Collection<\Eshop\DB\Category> $categories
	 * @return array<array<\Eshop\DB\Category>>
	 */
	public function getCategoriesMapWithHeurekaCategories(Collection $categories): array
	{
		$map = [];

		foreach ($categories as $category) {
			$map[$category->getPK()] = [
				'category' => $category,
				'heureka' => null,
			];

			if ($category->exportHeurekaCategory || $category->ancestor === null) {
				$map[$category->getPK()]['heureka'] = $category->exportHeurekaCategory;

				continue;
			}

			$categoryBranch = \array_reverse($this->getBranch($category));

			foreach (\array_slice($categoryBranch, 1) as $branchCategory) {
				if ($branchCategory->exportHeurekaCategory) {
					$map[$category->getPK()]['heureka'] = $branchCategory->exportHeurekaCategory;

					break;
				}
			}
		}

		return $map;
	}

	public function importHeurekaTreeCsv(Reader $reader, CategoryType $categoryType): void
	{
		$columns = [
			'Subcategory 1',
			'Subcategory 2',
			'Subcategory 3',
			'Subcategory 4',
			'Subcategory 5',
		];

		$iterator = $reader->getRecords($columns);

		$defaultMutationSuffix = '_cs';

		foreach ($iterator as $value) {
			$previousCategory = null;

			foreach ($columns as $column) {
				if (!isset($value[$column]) || Strings::length($value[$column]) === 0) {
					break;
				}

				$collection = $this->many()
					->where('fk_type', $categoryType->getPK())
					->where("name$defaultMutationSuffix", $value[$column]);

				if ($previousCategory) {
					$collection->where('path LIKE :s', ['s' => "$previousCategory->path%"]);
				} else {
					$collection->where('fk_ancestor IS NULL');
				}

				$existingCategory = $collection->first();

				$previousCategory = $existingCategory ?? $this->createOne([
						'name' => ['cs' => $value[$column]],
						'path' => $this->generateUniquePath($previousCategory ? $previousCategory->path : ''),
						'ancestor' => $previousCategory,
						'type' => $categoryType->getPK(),
					]);
			}
		}

		$this->clearCategoriesCache();
	}

	public function importZboziTreeCsv(Reader $reader, CategoryType $categoryType): void
	{
		$columns = [
			'id kategorie',
			'Název kategorie',
			'Celá cesta',
		];

		$iterator = $reader->getRecords($columns);

		$defaultMutationSuffix = '_cs';

		foreach ($iterator as $value) {
			$previousCategory = null;

			foreach (\explode(' | ', $value['Celá cesta']) as $category) {
				$collection = $this->many()
					->where('fk_type', $categoryType->getPK())
					->where("name$defaultMutationSuffix", $category);

				if ($previousCategory) {
					$collection->where('path LIKE :s', ['s' => "$previousCategory->path%"]);
				} else {
					$collection->where('fk_ancestor IS NULL');
				}

				$existingCategory = $collection->first();

				$previousCategory = $existingCategory ?? $this->createOne([
						'name' => ['cs' => $category],
						'path' => $this->generateUniquePath($previousCategory ? $previousCategory->path : ''),
						'ancestor' => $previousCategory,
						'type' => $categoryType->getPK(),
					]);
			}
		}

		$this->clearCategoriesCache();
	}

	public function generateUniquePath(string $prefix = ''): string
	{
		do {
			$random = $prefix . Random::generate(4);
			$tempCategory = $this->many()->where('path', $random)->first();
		} while ($tempCategory);

		return $random;
	}

	public function exportTreeCsv(Writer $writer, array $items): void
	{
		$writer->setDelimiter(';');

		$columns = [
			'Subcategory 1',
			'Subcategory 2',
			'Subcategory 3',
			'Subcategory 4',
			'Subcategory 5',
		];

		$writer->insertOne($columns);

		$defaultMutationSuffix = '_cs';

		/** @var \Eshop\DB\Category $category */
		foreach ($items as $category) {
			$tree = \array_reverse(\explode(';', $category->getFamilyTree()->select(['tree' => 'GROUP_CONCAT(name' . $defaultMutationSuffix . ' SEPARATOR ";")'])->first()->getValue('tree')));

			$row = [];

			foreach (\array_keys($columns) as $i) {
				$row[] = $tree[$i] ?? null;
			}

			$writer->insertOne($row);
		}
	}

	/**
	 * @param \League\Csv\Writer $writer
	 * @param \StORM\Collection<\Eshop\DB\Category> $categories
	 * @throws \League\Csv\CannotInsertRecord
	 * @throws \League\Csv\InvalidArgument
	 */
	public function csvExportTargito(Writer $writer, Collection $categories): void
	{
		$writer->setDelimiter(',');

		$writer->insertOne([
			'id',
			'name',
			'parent_id',
			'full_name',
			'is_hidden',
		]);

		/** @var \Eshop\DB\Category $category */
		foreach ($categories as $category) {
			$tree = $this->getBranch($category);

			foreach ($tree as $key => $treeCategory) {
				$tree[$key] = $treeCategory->name;
			}

			$writer->insertOne([
				$category->code,
				$category->name,
				$category->ancestor ? $category->ancestor->code : null,
				\implode('|', $tree),
				$category->hidden ? '1' : '0',
			]);
		}
	}

	/**
	 * @param array<\Eshop\DB\Category> $branch
	 */
	public function getBranchFullName(array $branch, string $separator = '|'): string
	{
		foreach ($branch as $key => $treeCategory) {
			$branch[$key] = $treeCategory->name;
		}

		return \implode($separator, $branch);
	}

	public function generateProducerCategories(array $categories, bool $deep = false): void
	{
		$connection = $this->getConnection();
		$mutations = $connection->getAvailableMutations();

		/** @var array<\Eshop\DB\Category> $categories */
		$categories = $this->many()->where('uuid', $categories);

		foreach ($categories as $category) {
			/** @var array<\Eshop\DB\Producer>|\StORM\ICollection $producers */
			$producers = $this->producerRepository->many()
				->join(['product' => 'eshop_product'], 'product.fk_producer = this.uuid', [], 'INNER')
				->join(['nxnCategory' => 'eshop_product_nxn_eshop_category'], 'nxnCategory.fk_product = product.uuid');

			if ($deep) {
				$producers->join(['category' => 'eshop_category'], 'nxnCategory.fk_category = category.uuid')
					->where('category.path LIKE :s', ['s' => $category->path . '%']);
			} else {
				$producers->where('nxnCategory.fk_category', $category->getPK());
			}

			foreach ($producers as $producer) {
				$values = [];
				$categoryValues = [];

				foreach (\array_keys($mutations) as $mutation) {
					$urlMutation = null;

					if ($category->getValue('name', $mutation) && $producer->getValue('name', $mutation)) {
						$urlMutation = Strings::webalize($category->getValue('name', $mutation) . '-' . $producer->getValue('name', $mutation));

						while (!$this->pageRepository->isUrlAvailable($urlMutation, $mutation)) {
							$urlMutation .= '-' . Random::generate(4);
						}
					}

					$values['url'][$mutation] = $urlMutation;
					$values['active'][$mutation] = true;
					$values['title'][$mutation] = $category->getValue('name', $mutation) && $producer->getValue('name', $mutation) ?
						$category->getValue('name', $mutation) . ' ' . $producer->getValue('name', $mutation) : null;
					$categoryValues['name'][$mutation] = $producer->getValue('name', $mutation);
					$categoryValues['perex'][$mutation] = '<h1>' . $values['title'][$mutation] . '</h1>';
				}

				/** @var \Eshop\DB\Category $producerCategory */
				$producerCategory = $this->syncOne([
					'uuid' => DIConnection::generateUuid($category->getPK(), $producer->getPK()),
					'path' => $this->generateUniquePath($category->path),
					'ancestor' => $category->getPK(),
					'name' => $categoryValues['name'] ?? [],
					'type' => $category->getValue('type'),
					'perex' => $categoryValues['perex'] ?? [],
				], [], true);

				$values['type'] = 'product_list';
				$values['params'] = Helpers::serializeParameters(['category' => $producerCategory->getPK()]);

				$page = $this->pageRepository->getPageByTypeAndParams('product_list', null, ['category' => $producerCategory->getPK()]);

				if (!$page) {
					$this->pageRepository->syncOne($values);
				}

				$products = $this->productRepository->many()
					->join(['nxnCategory' => 'eshop_product_nxn_eshop_category'], 'nxnCategory.fk_product = this.uuid')
					->where('this.fk_producer', $producer->getPK());

				if ($deep) {
					$products->join(['category' => 'eshop_category'], 'nxnCategory.fk_category = category.uuid')
						->where('category.path LIKE :s', ['s' => $category->path . '%']);
				} else {
					$products->where('nxnCategory.fk_category', $category->getPK());
				}

				foreach ($products->toArrayOf('uuid') as $product) {
					$this->connection->syncRow('eshop_product_nxn_eshop_category', [
						'fk_category' => $producerCategory->getPK(),
						'fk_product' => $product,
					]);
				}
			}
		}

		$this->clearCategoriesCache();
	}

	/**
	 * @throws \Exception
	 */
	public function importHeurekaTreeXml(string $content, CategoryType $categoryType): void
	{
		$xml = \simplexml_load_string($content);

		if (!$xml || !isset($xml->CATEGORY)) {
			throw new \Exception('Invalid XML!');
		}

		$this->iterateAndImportHeurekaTreeXml($xml->CATEGORY, $categoryType);

		$this->clearCategoriesCache();
	}

	/**
	 * Used to check if default perex or default content of category is valid by Latte.
	 * @param string|null $content
	 */
	public function isDefaultContentValid(?string $content): bool
	{
		if ($content === null) {
			return true;
		}

		$policy = SecurityPolicy::createSafePolicy();
		$policy->allowFilters(['price', 'date']);

		$latte = $this->latteFactory->create();
		$latte->addExtension(new UIExtension(null));

		$latte->setLoader(new StringLoader());
		$latte->setPolicy($policy);
		$latte->setSandboxMode();

		$params = [
			'name' => '',
			'producer' => '',
			'code' => '',
			'ean' => '',
			'attributes' => [],
		];

		try {
			$latte->renderToString($content, $params);

			return true;
		} catch (\Throwable $e) {
			return false;
		}
	}

	public function recalculateCategoryTree(string $categoryType): void
	{
		$tree = [];
		$insertedCount = 0;

		foreach ($this->many()->where('fk_type', $categoryType) as $category) {
			$branch = $this->getBranch($category);

			foreach ($branch as $branchCategory) {
				$this->insertCategoryToTree($tree, $branchCategory, $insertedCount);
			}
		}

		$updates = [];

		$this->recalculateTree($tree, null, $updates);

		foreach ($updates as $updatePK => $updatePath) {
			$this->many()->where('this.uuid', $updatePK)->update(['path' => $updatePath]);
		}
	}

	private function recalculateTree(array &$tree, ?string $ancestorPath = null, array &$updates = []): void
	{
		foreach ($tree as $branch) {
			if (!$ancestorPath || (Strings::startsWith($branch['category']['path'], $ancestorPath) && Strings::length($branch['category']['path']) === Strings::length($ancestorPath) + 4)) {
				$this->recalculateTree($tree[$branch['category']['uuid']]['children'], $branch['category']['path'], $updates);

				continue;
			}

			do {
				$newPath = $ancestorPath . Random::generate(4);
			} while (\count(\array_filter($branch['children'], function ($element) use ($newPath) {
				return $element['category']['path'] === $newPath;
			})) !== 0);

			$updates[$branch['category']['uuid']] = $newPath;
			$tree[$branch['category']['uuid']]['category']['path'] = $newPath;


			$this->recalculateTree($tree[$branch['category']['uuid']]['children'], $newPath, $updates);
		}
	}

	private function insertCategoryToTree(array &$tree, Category $category, int &$insertedCount): bool
	{
		if (!$category->ancestor) {
			if (isset($tree[$category->getPK()])) {
				return true;
			}

			$tree[$category->getPK()] = [
				'category' => [
					'uuid' => $category->getPK(),
					'path' => $category->path,
				],
				'children' => [],
			];

			$insertedCount++;

			return true;
		}

		if (isset($tree[$category->getPK()])) {
			return true;
		}

		if (isset($tree[$category->getValue('ancestor')])) {
			if (!isset($tree[$category->getValue('ancestor')]['children'][$category->getPK()])) {
				$tree[$category->getValue('ancestor')]['children'][$category->getPK()] = [
					'category' => [
						'uuid' => $category->getPK(),
						'path' => $category->path,
					],
					'children' => [],
				];

				$insertedCount++;
			}

			return true;
		}

		foreach ($tree as $subTree) {
			if ($this->insertCategoryToTree($tree[$subTree['category']['uuid']]['children'], $category, $insertedCount)) {
				return true;
			}
		}

		return false;
	}

	private function iterateAndImportHeurekaTreeXml(\SimpleXMLElement $tree, CategoryType $categoryType, ?Category $ancestor = null): void
	{
		foreach ($tree as $categoryXml) {
			$category = $this->saveImportHeurekaTreeCategory($categoryXml, $categoryType, $ancestor);

			if (!isset($categoryXml->CATEGORY)) {
				continue;
			}

			$this->iterateAndImportHeurekaTreeXml($categoryXml->CATEGORY, $categoryType, $category);
		}
	}

	private function saveImportHeurekaTreeCategory(\SimpleXMLElement $element, CategoryType $categoryType, ?Category $ancestor = null): ?Category
	{
		$elementArray = (array) $element;

		if (!isset($elementArray['CATEGORY_NAME'])) {
			return null;
		}

		$collection = $this->many()
			->where('fk_type', $categoryType->getPK())
			->where('code', $elementArray['CATEGORY_ID']);

		if ($ancestor) {
			$collection->where('path LIKE :s', ['s' => "$ancestor->path%"]);
		} else {
			$collection->where('fk_ancestor IS NULL');
		}

		$existingCategory = $collection->first();

		return $existingCategory ?? $this->createOne([
				'name' => ['cs' => $elementArray['CATEGORY_NAME']],
				'fullName' => ['cs' => $elementArray['CATEGORY_FULLNAME'] ?? null],
				'path' => $this->generateUniquePath($ancestor ? $ancestor->path : ''),
				'ancestor' => $ancestor,
				'type' => $categoryType->getPK(),
				'code' => $elementArray['CATEGORY_ID'] ?? null,
			]);
	}

	/**
	 * @param string $typeId
	 * @param \Eshop\DB\CategoryRepository $repository
	 * @param bool $includeHidden
	 * @param bool $onlyMenu
	 * @return array<\Eshop\DB\Category>
	 */
	private function getTreeHelper(string $typeId, CategoryRepository $repository, bool $includeHidden = false, bool $onlyMenu = false): array
	{
		$collection = $repository->getCategories($includeHidden)->where('LENGTH(path) <= 40');

		if ($onlyMenu) {
			$collection->where('this.showInMenu', true);
		}

		if ($typeId) {
			$collection->where('this.fk_type', $typeId);
		}

		return $repository->buildTree($collection->toArray(), null, $typeId);
	}

	/**
	 * @param array<\Eshop\DB\Category> $elements
	 * @param string|null $ancestorId
	 * @return array<\Eshop\DB\Category>
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
}
