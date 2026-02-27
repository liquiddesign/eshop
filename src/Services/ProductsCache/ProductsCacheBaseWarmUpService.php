<?php

namespace Eshop\Services\ProductsCache;

use Base\Application;
use Base\DB\Shop;
use Base\ShopsConfig;
use Eshop\Admin\ScriptsPresenter;
use Eshop\DB\AttributeRepository;
use Eshop\DB\AttributeValueRepository;
use Eshop\DB\CategoryRepository;
use Eshop\DB\CategoryTypeRepository;
use Eshop\DB\CustomerGroupRepository;
use Eshop\DB\CustomerRepository;
use Eshop\DB\DisplayAmountRepository;
use Eshop\DB\DisplayDeliveryRepository;
use Eshop\DB\MerchantRepository;
use Eshop\DB\PricelistRepository;
use Eshop\DB\PriceRepository;
use Eshop\DB\ProducerRepository;
use Eshop\DB\ProductPrimaryCategoryRepository;
use Eshop\DB\ProductRepository;
use Eshop\DB\RelatedRepository;
use Eshop\DB\RelatedTypeRepository;
use Eshop\DB\VisibilityListItemRepository;
use Eshop\DB\VisibilityListRepository;
use Eshop\Services\SettingsService;
use Eshop\ShopperUser;
use Nette\Caching\Cache;
use Nette\Caching\Storage;
use Nette\DI\Container;
use Nette\Utils\Arrays;
use Nette\Utils\FileSystem;
use StORM\DIConnection;
use Tracy\Debugger;
use Web\DB\SettingRepository;

abstract class ProductsCacheBaseWarmUpService
{
	public const PRODUCTS_TABLE_NAME = 'products';
	public const PRICES_TABLE_NAME = 'prices_';
	public const CATEGORIES_TABLE_NAME = 'categories';
	public const RELATIONS_TABLE_NAME = 'relations';

	protected Cache $cache;

	protected \PDO|false $link = false;

	protected string|false $dbName = false;

	protected string|false $mutationSuffix = false;

	protected string $logName = 'ProductsCache-timing';

	public function __construct(
		protected readonly ProductRepository $productRepository,
		protected readonly CategoryRepository $categoryRepository,
		protected readonly PriceRepository $priceRepository,
		/** @var \Eshop\DB\PricelistRepository<\Eshop\DB\Pricelist> */
		protected readonly PricelistRepository $pricelistRepository,
		protected readonly Container $container,
		protected readonly DIConnection $connection,
		protected readonly ShopsConfig $shopsConfig,
		protected readonly CategoryTypeRepository $categoryTypeRepository,
		protected readonly SettingRepository $settingRepository,
		protected readonly VisibilityListItemRepository $visibilityListItemRepository,
		protected readonly AttributeValueRepository $attributeValueRepository,
		protected readonly DisplayAmountRepository $displayAmountRepository,
		protected readonly VisibilityListRepository $visibilityListRepository,
		protected readonly ProducerRepository $producerRepository,
		protected readonly DisplayDeliveryRepository $displayDeliveryRepository,
		protected readonly AttributeRepository $attributeRepository,
		protected readonly ShopperUser $shopperUser,
		protected readonly RelatedRepository $relatedRepository,
		protected readonly RelatedTypeRepository $relatedTypeRepository,
		protected readonly ProductPrimaryCategoryRepository $productPrimaryCategoryRepository,
		protected readonly CustomerRepository $customerRepository,
		protected readonly CustomerGroupRepository $customerGroupRepository,
		protected readonly SettingsService $settingsService,
		protected readonly MerchantRepository $merchantRepository,
		readonly Storage $storage,
	) {
		$this->cache = new Cache($storage);
	}

	public function getConnection(): DIConnection
	{
		return $this->connection;
	}

	public function cleanProductsProviderCache(): void
	{
		$this->cache->clean([Cache::Tags => [GeneralProductsCacheProvider::PRODUCTS_PROVIDER_CACHE_TAG]]);
	}

	public function cleanAppCache(): void
	{
		$this->cache->clean([
			Cache::Tags => [
				ScriptsPresenter::PRODUCTS_CACHE_TAG,
				ScriptsPresenter::PRICELISTS_CACHE_TAG,
				ScriptsPresenter::CATEGORIES_CACHE_TAG,
				ScriptsPresenter::EXPORT_CACHE_TAG,
				ScriptsPresenter::ATTRIBUTES_CACHE_TAG,
				ScriptsPresenter::PRODUCERS_CACHE_TAG,
				ScriptsPresenter::SETTINGS_CACHE_TAG,
			],
		]);
	}

	/**
	 * @param array<mixed> $elements
	 * @return array<array<mixed>>
	 */
	public function generateCombinations(array $elements): array
	{
		// Empty combination = no dynamic price list
		$result = [[]];

		foreach ($elements as $element) {
			foreach ($result as $combination) {
				$result[] = \array_merge($combination, [$element]);
			}
		}

		return $result;
	}

	/**
	 * @param array<string|int> $customers
	 * @param array<string|int> $customerGroups
	 * @param array<string|int> $merchants
	 * @return array{0: array<string, true>, 1: list<int>, 2: list<int>, 3: array<string, true>}
	 */
	public function getAllPossibleVisibilityAndPriceListOptions(array $customers = [], array $customerGroups = [], array $merchants = []): array
	{
		/** @var array<string|int, true> $existingOptions */
		$existingOptions = [];
		$allVisibilityLists = [];
		$allPriceLists = [];
		/** @var array<string, true> $merchantIndexes */
		$merchantIndexes = [];

		if (!$this->shopsConfig->getAvailableShops()) {
			return $this->getAllPossibleVisibilityAndPriceListOptionsHelper($customers, $customerGroups, $merchants);
		}

		foreach ($this->shopsConfig->getAvailableShops() as $shop) {
			[
				$existingOptionsShop,
				$allVisibilityListsShop,
				$allPriceListsShop,
				$merchantIndexesShop,
			] = $this->getAllPossibleVisibilityAndPriceListOptionsHelper($customers, $customerGroups, $merchants, $shop);
			/** @var array<string, true> $existingOptions */
			$existingOptions = Arrays::mergeTree($existingOptions, $existingOptionsShop);
			/** @var array<string, true> $merchantIndexes */
			$merchantIndexes = Arrays::mergeTree($merchantIndexes, $merchantIndexesShop);

			// merge only new values
			$allVisibilityLists = \array_merge($allVisibilityLists, \array_diff($allVisibilityListsShop, $allVisibilityLists));
			$allPriceLists = \array_merge($allPriceLists, \array_diff($allPriceListsShop, $allPriceLists));
		}

		return [$existingOptions, $allVisibilityLists, $allPriceLists, $merchantIndexes];
	}

	/**
	 * @param string $tableName
	 * @param array<array<mixed>> $data
	 * @param int $chunkSize
	 * @throws \Exception
	 */
	protected function loadDataInfile(string $tableName, array $data, int $chunkSize = 10000): void
	{
//      Debugger::timer('loadDataInfile');
		$tmpFileName = \tempnam($this->container->getParameter('tempDir'), 'csv');

		$buffer = \fopen('php://memory', 'rw');
		$file = \fopen($tmpFileName, 'w');

		if ($buffer === false) {
			throw new \Exception("Resource 'buffer' was not created!");
		}

		if ($file === false) {
			throw new \Exception("Resource 'file' was not created!");
		}

		$i = 0;

		foreach ($data as $row) {
			\fputcsv($buffer, $row, escape: '\\');

			$i++;

			if ($i !== $chunkSize) {
				continue;
			}

			\rewind($buffer);
			$csv = \stream_get_contents($buffer);

			if ($csv === false) {
				throw new \Exception("Resource 'csv' was not created!");
			}

			\fclose($buffer);
			$buffer = \fopen('php://memory', 'rw');

			if ($buffer === false) {
				throw new \Exception("Resource 'buffer' was not created!");
			}

			\fwrite($file, $csv);
			unset($csv);

			$i = 0;
		}

		\rewind($buffer);
		$csv = \stream_get_contents($buffer);

		if ($csv === false) {
			throw new \Exception("Resource 'csv' was not created!");
		}

		\fclose($buffer);

		\fwrite($file, $csv);
		unset($csv);

		\fclose($file);

//      Debugger::dump('Insert to CSV: ' . Debugger::timer('loadDataInfile'));

		$tmpFileName = \str_replace('\\', '\\\\', $tmpFileName);

		$this->getLink()->exec("LOAD DATA LOCAL INFILE \"$tmpFileName\"
            INTO TABLE $tableName
            fields terminated by ','
            optionally enclosed by '\"'
            escaped by \"\\\\\";");

		FileSystem::delete($tmpFileName);

//      Debugger::dump('Insert to DB: ' . Debugger::timer('loadDataInfile'));
	}

	protected function getLink(): \PDO
	{
		if ($this->link !== false) {
			return $this->link;
		}

		return $this->link = $this->getConnection()->getLink();
	}

	protected function getDbName(): string
	{
		if ($this->dbName !== false) {
			return $this->dbName;
		}

		return $this->dbName = $this->getConnection()->getDatabaseName();
	}

	protected function getMutationSuffix(): string
	{
		if ($this->mutationSuffix !== false) {
			return $this->mutationSuffix;
		}

		return $this->mutationSuffix = $this->connection->getMutationSuffix();
	}

	/**
	 * @param string $category
	 * @param array<object{ancestor: string}> $allCategories
	 * @return array<string>
	 */
	protected function getAncestorsOfCategory(string $category, array $allCategories): array
	{
		$categories = [];

		while ($ancestor = $allCategories[$category]->ancestor) {
			$categories[] = $ancestor;
			$category = $ancestor;
		}

		return $categories;
	}

	/**
	 * @return array{
	 *     0: array<object{id: int}>,
	 *     1: array<object{id: int, isSold: bool}>,
	 *     2: array<object{id: int, ancestor: string, showDescendantProducts: bool, showProductsInAncestors: bool, path: string, uuid: string, descendants: array<string>, fk_type: string}>,
	 *     3: array<object{category: string|null, categoryType: string}>,
	 *     4: array<object{id: int, groupedValues: string}>,
	 *     5: array<object{id: int, groupedValues: string}>,
	 *     6: array<object{id: int, groupedValues: string}>
	 * }
	 */
	protected function getPrefetchedArrays(): array
	{
		/** @var array<object{id: int}> $allCategoryTypes */
		$allCategoryTypes = $this->categoryTypeRepository->many()
			->select(['this.id'])
			->setOrderBy(['this.id'])
			->fetchArray(\stdClass::class);


		/** @var array<object{id: int, isSold: bool}> $allDisplayAmounts */
		$allDisplayAmounts = $this->displayAmountRepository->many()
			->select(['this.id'])
			->setIndex('id')
			->fetchArray(\stdClass::class);

		/** @var array<\stdClass&object{
		 *     id: int,
		 *     ancestor: string,
		 *     showDescendantProducts: bool,
		 *     showProductsInAncestors: bool,
		 *     path: string,
		 *     uuid: string,
		 *     descendants: array<string>,
		 *     fk_type: string
		 * }> $allCategories
		 */
		$allCategories = $this->categoryRepository->many()
			->setSelect([
				'this.id',
				'this.uuid',
				'this.path',
				'this.fk_type',
				'ancestor' => 'this.fk_ancestor',
				'showDescendantProducts' => 'this.showDescendantProducts',
				'showProductsInAncestors' => 'this.showProductsInAncestors',
			], keepIndex: true)
			->fetchArray(\stdClass::class);

		Debugger::timer('prefetch_descendants');

		foreach ($allCategories as $category) {
			$category->descendants = $this->categoryRepository->many()
				->where('this.path LIKE :path', ['path' => $category->path . '%'])
				->whereNot('this.uuid', $category->uuid)
				->where('this.fk_type', $category->fk_type)
				->setSelect(['this.uuid'], keepIndex: true)
				->toArrayOf('uuid', toArrayValues: true);
		}

		Debugger::log(\sprintf(
			'prefetch_descendants: %.3fs (%d categories, N+1 queries)',
			Debugger::timer('prefetch_descendants'),
			\count($allCategories),
		), $this->logName);

		/** @var array<object{category: string|null, categoryType: string}> $allProductPrimaryCategories */
		$allProductPrimaryCategories = $this->productPrimaryCategoryRepository->many()
			->join(['eshop_categorytype'], 'this.fk_categoryType = eshop_categorytype.uuid')
			->join(['eshop_category'], 'this.fk_category = eshop_category.uuid')
			->setSelect(['category' => 'eshop_category.id', 'categoryType' => 'eshop_categorytype.id'], keepIndex: true)
			->fetchArray(\stdClass::class);

		/** @var array<object{id: int, groupedValues: string}> $productPrimaryCategories */
		$productPrimaryCategories = $this->productRepository->many()
			->join(['joinedTable' => 'eshop_productprimarycategory'], 'this.uuid = joinedTable.fk_product', type: 'INNER')
			->join(['categoryType' => 'eshop_category'], 'joinedTable.fk_category = categoryType.uuid', type: 'INNER')
			->setSelect([
				'id' => 'this.id',
				'groupedValues' => 'GROUP_CONCAT(DISTINCT joinedTable.uuid)',
			])
			->setGroupBy(['this.id'])
			->setIndex('id')
			->setOrderBy(['categoryType.id'])
			->fetchArray(\stdClass::class);

		/** @var array<object{id: int, groupedValues: string}> $productAttributeValues */
		$productAttributeValues = $this->productRepository->many()
			->join(['assign' => 'eshop_attributeassign'], 'this.uuid = assign.fk_product', type: 'INNER')
			->join(['joinedTable' => 'eshop_attributevalue'], 'assign.fk_value = joinedTable.uuid', type: 'INNER')
			->setSelect([
				'id' => 'this.id',
				'groupedValues' => 'GROUP_CONCAT(DISTINCT joinedTable.id)',
			])
			->setGroupBy(['this.id'])
			->setIndex('id')
			->fetchArray(\stdClass::class);

		/** @var array<object{id: int, groupedValues: string}> $productCategories */
		$productCategories = $this->productRepository->many()
			->join(['joinedTable' => 'eshop_product_nxn_eshop_category'], 'this.uuid = joinedTable.fk_product', type: 'INNER')
			->setSelect([
				'id' => 'this.id',
				'groupedValues' => 'GROUP_CONCAT(DISTINCT joinedTable.fk_category)',
			])
			->setGroupBy(['this.id'])
			->setIndex('id')
			->fetchArray(\stdClass::class);

		return [$allCategoryTypes, $allDisplayAmounts, $allCategories, $allProductPrimaryCategories, $productPrimaryCategories, $productAttributeValues, $productCategories];
	}

	protected function isCacheDeduplicationEnabled(): bool
	{
		try {
			/** @var \Base\Application $application */
			$application = $this->container->getByType(Application::class);

			return $application->getEnvironment() !== 'production';
		} catch (\Throwable) {
			return false;
		}
	}

	/**
	 * Returns set of pricelist PKs that have 0 prices (empty pricelists).
	 * @param array<string|int, \Eshop\DB\Pricelist> $prefetchedPriceLists
	 * @return array<string|int, true>
	 */
	private function getEmptyPriceListPKs(array $prefetchedPriceLists): array
	{
		$priceCountsByPriceList = $this->priceRepository->many()
			->join(['priceList' => 'eshop_pricelist'], 'this.fk_pricelist = priceList.uuid', type: 'INNER')
			->where('priceList.isActive', true)
			->setSelect([
				'priceListPK' => 'priceList.uuid',
				'cnt' => 'COUNT(*)',
			])
			->setGroupBy(['priceList.uuid'])
			->fetchArray(\stdClass::class);

		$nonEmptyPKs = [];

		foreach ($priceCountsByPriceList as $row) {
			$nonEmptyPKs[$row->priceListPK] = true;
		}

		return \array_diff_key(\array_fill_keys(\array_keys($prefetchedPriceLists), true), $nonEmptyPKs);
	}

	/**
	 * @param array<string|int> $customers
	 * @param array<string|int> $customerGroups
	 * @param array<string|int> $merchants
	 * @return array{0: array<string, true>, 1: list<int>, 2: list<int>, 3: array<string, true>}
	 */
	private function getAllPossibleVisibilityAndPriceListOptionsHelper(array $customers = [], array $customerGroups = [], array $merchants = [], Shop|null $shop = null): array
	{
		Debugger::timer('optionsHelper');

		/** @var array<string|int, \Eshop\DB\Pricelist> $prefetchedPriceLists */
		$prefetchedPriceLists = $this->pricelistRepository->many()->select(['this.id'])->toArray();

		$existingOptions = [];
		$allVisibilityLists = [];
		$allPriceLists = [];
		/** @var array<string, true> $merchantIndexes */
		$merchantIndexes = [];
		$discountQueryCount = 0;

		$filterEmptyPriceLists = $this->isCacheDeduplicationEnabled();
		/** @var array<string|int, true> $emptyPriceListPKs */
		$emptyPriceListPKs = $filterEmptyPriceLists ? $this->getEmptyPriceListPKs($prefetchedPriceLists) : [];

		$customerGroupsQuery = $this->customerGroupRepository->many();

		if ($customerGroups) {
			$customerGroupsQuery->where('this.uuid', $customerGroups);
		} else {
			// Only customer groups marked as defaultUnregisteredGroup are used
			if ($unregisteredGroups = $this->settingsService->getAllDefaultUnregisteredGroups()) {
				$customerGroupsQuery->where('this.uuid', $unregisteredGroups);
			} else {
				$customerGroupsQuery->where('this.uuid', CustomerGroupRepository::UNREGISTERED_PK);
			}
		}

		if (!$customerGroups && ($customers || $merchants)) {
			$customerGroupsQuery->where('1=0');
		}

		foreach ($customerGroupsQuery as $customerGroup) {
			$visibilityLists = $customerGroup->getDefaultVisibilityLists()
				->where('hidden', false)
				->setSelect(['id'])
				->setOrderBy(['priority', 'uuid'])
				->where('this.fk_shop = :shop OR this.fk_shop IS NULL', ['shop' => $shop?->getPK()])
				->toArrayOf('id', toArrayValues: true);

			$priceLists = $customerGroup->getDefaultPricelists()
				->where('isActive', true)
				->setSelect(['id'], keepIndex: true)
				->setOrderBy(['priority', 'uuid'])
				->where('this.fk_shop = :shop OR this.fk_shop IS NULL', ['shop' => $shop?->getPK()])
				->toArrayOf('id');

			foreach ($visibilityLists as $visibilityList) {
				$allVisibilityLists[$visibilityList] = true;
			}

			foreach ($priceLists as $priceList) {
				$allPriceLists[$priceList] = true;
			}

			// If PriceList has discount, his validity is dynamic
			// Because of that, we need to create unique index for each combination of dynamic PriceLists

			$fixedPriceLists = [];
			$dynamicPriceLists = [];

			foreach ($priceLists as $PK => $id) {
				$discountQueryCount++;

				if ($prefetchedPriceLists[$PK]->getDiscounts()->count() === 0) {
					$fixedPriceLists[$PK] = $id;
				} else {
					$dynamicPriceLists[$PK] = $id;
				}
			}

			// Filter out empty dynamic price lists (non-production only)
			if ($filterEmptyPriceLists) {
				$dynamicPriceLists = \array_diff_key($dynamicPriceLists, $emptyPriceListPKs);
			}

			// Generate all possible combinations of dynamic price lists
			$possibleCombinations = $this->generateCombinations($dynamicPriceLists);

			// Generate all valid price list sequences with preserved order
			$finalCombinations = [];

			foreach ($possibleCombinations as $combination) {
				$newCombination = [];

				foreach ($priceLists as $id) {
					if (\Nette\Utils\Arrays::contains($fixedPriceLists, $id) || \Nette\Utils\Arrays::contains($combination, $id)) {
						$newCombination[] = $id;
					}
				}

				$finalCombinations[] = $newCombination;
			}

			foreach ($finalCombinations as $combination) {
				$index =
					\implode(',', $visibilityLists) .
					'-' .
					\implode(',', $combination);

				$existingOptions[$index] = true;
			}
		}

		foreach (['eshop_customer_nxn_eshop_pricelist', 'eshop_customer_nxn_eshop_pricelist_favourite'] as $table) {
			$customersQuery = $this->customerRepository->many()
				->join(['customerXpriceList' => $table], 'this.uuid = customerXpriceList.fk_customer')
				->join(['priceList' => 'eshop_pricelist'], 'customerXpriceList.fk_pricelist = priceList.uuid')
				->join(['customerXvisibilityList' => 'eshop_customer_nxn_eshop_visibilitylist'], 'this.uuid = customerXvisibilityList.fk_customer')
				->join(['visibilityList' => 'eshop_visibilitylist'], 'customerXvisibilityList.fk_visibilitylist = visibilityList.uuid')
				->setSelect([
					'visibilityPriceIndex' => 'DISTINCT(CONCAT(
                    GROUP_CONCAT(DISTINCT visibilityList.id ORDER BY visibilityList.priority, visibilityList.uuid),
                    "-",
                    GROUP_CONCAT(DISTINCT priceList.uuid ORDER BY priceList.priority, priceList.uuid)
                ))',
				])
				->where('priceList.isActive', true)
				->where('visibilityList.hidden', false)
				->where('(priceList.fk_shop = :shop OR priceList.fk_shop IS NULL) AND (visibilityList.fk_shop = :shop OR visibilityList.fk_shop IS NULL)', ['shop' => $shop?->getPK()])
				->setGroupBy(['this.uuid']);

			if ($customers) {
				$customersQuery->where('this.uuid', $customers);
			} else {
				if ($customerGroups || $merchants) {
					$customersQuery->where('1=0');
				}
			}

			$indexes = $customersQuery->toArrayOf('visibilityPriceIndex');

			foreach ($indexes as $index) {
				if (!$index) {
					continue;
				}

				$exploded = \explode('-', $index);

				if (\count($exploded) !== 2) {
					continue;
				}

				$visibilityLists = \explode(',', $exploded[0]);

				foreach ($visibilityLists as $visibilityList) {
					$allVisibilityLists[$visibilityList] = true;
				}

				$priceListsPKs = \explode(',', $exploded[1]);
				$priceLists = [];

				foreach ($priceListsPKs as $priceList) {
					$allPriceLists[$prefetchedPriceLists[$priceList]->id] = true;
					$priceLists[$priceList] = $prefetchedPriceLists[$priceList]->id;
				}

				// If PriceList has discount, his validity is dynamic
				// Because of that, we need to create unique index for each combination of dynamic PriceLists

				$fixedPriceLists = [];
				$dynamicPriceLists = [];

				foreach ($priceLists as $PK => $id) {
					$discountQueryCount++;

					if ($prefetchedPriceLists[$PK]->getDiscounts()->count() === 0) {
						$fixedPriceLists[$PK] = $id;
					} else {
						$dynamicPriceLists[$PK] = $id;
					}
				}

				// Filter out empty dynamic price lists (non-production only)
				if ($filterEmptyPriceLists) {
					$dynamicPriceLists = \array_diff_key($dynamicPriceLists, $emptyPriceListPKs);
				}

				// Generate all possible combinations of dynamic price lists
				$possibleCombinations = $this->generateCombinations($dynamicPriceLists);

				// Generate all valid price list sequences with preserved order
				$finalCombinations = [];

				foreach ($possibleCombinations as $combination) {
					$newCombination = [];

					foreach ($priceLists as $id) {
						if (\Nette\Utils\Arrays::contains($fixedPriceLists, $id) || \Nette\Utils\Arrays::contains($combination, $id)) {
							$newCombination[] = $id;
						}
					}

					$finalCombinations[] = $newCombination;
				}

				foreach ($finalCombinations as $combination) {
					$index =
						\implode(',', $visibilityLists) .
						'-' .
						\implode(',', $combination);

					$existingOptions[$index] = true;
				}
			}
		}

		foreach (['eshop_merchant_nxn_eshop_pricelist'] as $table) {
			$merchantsQuery = $this->merchantRepository->many()
				->join(['merchantXpriceList' => $table], 'this.uuid = merchantXpriceList.fk_merchant')
				->join(['priceList' => 'eshop_pricelist'], 'merchantXpriceList.fk_pricelist = priceList.uuid')
				->join(['merchantXvisibilityList' => 'eshop_merchant_nxn_eshop_visibilitylist'], 'this.uuid = merchantXvisibilityList.fk_merchant')
				->join(['visibilityList' => 'eshop_visibilitylist'], 'merchantXvisibilityList.fk_visibilitylist = visibilityList.uuid')
				->setSelect([
					'visibilityPriceIndex' => 'DISTINCT(CONCAT(
                    GROUP_CONCAT(DISTINCT visibilityList.id ORDER BY visibilityList.priority, visibilityList.uuid),
                    "-",
                    GROUP_CONCAT(DISTINCT priceList.uuid ORDER BY priceList.priority, priceList.uuid)
                ))',
				])
				->where('priceList.isActive', true)
				->where('visibilityList.hidden', false)
				->where('(priceList.fk_shop = :shop OR priceList.fk_shop IS NULL) AND (visibilityList.fk_shop = :shop OR visibilityList.fk_shop IS NULL)', ['shop' => $shop?->getPK()])
				->setGroupBy(['this.uuid']);

			if ($merchants) {
				$merchantsQuery->where('this.uuid', $merchants);
			} else {
				if ($customerGroups || $customers) {
					$merchantsQuery->where('1=0');
				}
			}

			$indexes = $merchantsQuery->toArrayOf('visibilityPriceIndex');

			foreach ($indexes as $index) {
				if (!$index) {
					continue;
				}

				$exploded = \explode('-', $index);

				if (\count($exploded) !== 2) {
					continue;
				}

				$visibilityLists = \explode(',', $exploded[0]);

				foreach ($visibilityLists as $visibilityList) {
					$allVisibilityLists[$visibilityList] = true;
				}

				$priceListsPKs = \explode(',', $exploded[1]);
				$priceLists = [];

				foreach ($priceListsPKs as $priceList) {
					$allPriceLists[$prefetchedPriceLists[$priceList]->id] = true;
					$priceLists[$priceList] = $prefetchedPriceLists[$priceList]->id;
				}

				// If PriceList has discount, his validity is dynamic
				// Because of that, we need to create unique index for each combination of dynamic PriceLists

				$fixedPriceLists = [];
				$dynamicPriceLists = [];

				foreach ($priceLists as $PK => $id) {
					$discountQueryCount++;

					if ($prefetchedPriceLists[$PK]->getDiscounts()->count() === 0) {
						$fixedPriceLists[$PK] = $id;
					} else {
						$dynamicPriceLists[$PK] = $id;
					}
				}

				// Filter out empty dynamic price lists (non-production only)
				if ($filterEmptyPriceLists) {
					$dynamicPriceLists = \array_diff_key($dynamicPriceLists, $emptyPriceListPKs);
				}

				// Generate all possible combinations of dynamic price lists
				$possibleCombinations = $this->generateCombinations($dynamicPriceLists);

				// Generate all valid price list sequences with preserved order
				$finalCombinations = [];

				foreach ($possibleCombinations as $combination) {
					$newCombination = [];

					foreach ($priceLists as $id) {
						if (\Nette\Utils\Arrays::contains($fixedPriceLists, $id) || \Nette\Utils\Arrays::contains($combination, $id)) {
							$newCombination[] = $id;
						}
					}

					$finalCombinations[] = $newCombination;
				}

				foreach ($finalCombinations as $combination) {
					$index =
						\implode(',', $visibilityLists) .
						'-' .
						\implode(',', $combination);

					$existingOptions[$index] = true;
					$merchantIndexes[$index] = true;
				}
			}
		}

		Debugger::log(\sprintf(
			'optionsHelper: %.3fs (%d combinations, %d VLs, %d PLs, %d discount queries, shop=%s)',
			Debugger::timer('optionsHelper'),
			\count($existingOptions),
			\count($allVisibilityLists),
			\count($allPriceLists),
			$discountQueryCount,
			$shop?->getPK() ?? 'null',
		), $this->logName);

		return [$existingOptions, \array_keys($allVisibilityLists), \array_keys($allPriceLists), $merchantIndexes];
	}
}
