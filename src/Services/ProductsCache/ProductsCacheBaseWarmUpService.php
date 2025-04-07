<?php

namespace Eshop\Services\ProductsCache;

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
use Eshop\DB\ProductsCacheStateRepository;
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
		protected readonly ProductsCacheStateRepository $productsCacheStateRepository,
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
	 * @param array<string|int>|null $customerGroups
	 * @param array<string|int> $merchants
	 * @return array{0: array<string, true>, 1: list<int>, 2: list<int>}
	 */
	public function getAllPossibleVisibilityAndPriceListOptions(array $customers = [], array|null $customerGroups = null, array $merchants = []): array
	{
		/** @var array<string|int, true> $existingOptions */
		$existingOptions = [];
		$allVisibilityLists = [];
		$allPriceLists = [];

		if (!$this->shopsConfig->getAvailableShops()) {
			return $this->getAllPossibleVisibilityAndPriceListOptionsHelper($customers, $customerGroups, $merchants);
		}

		foreach ($this->shopsConfig->getAvailableShops() as $shop) {
			[$existingOptionsShop, $allVisibilityListsShop, $allPriceListsShop] = $this->getAllPossibleVisibilityAndPriceListOptionsHelper($customers, $customerGroups, $merchants, $shop);
			/** @var array<string, true> $existingOptions */
			$existingOptions = Arrays::mergeTree($existingOptions, $existingOptionsShop);

			// merge only new values
			$allVisibilityLists = \array_merge($allVisibilityLists, \array_diff($allVisibilityListsShop, $allVisibilityLists));
			$allPriceLists = \array_merge($allPriceLists, \array_diff($allPriceListsShop, $allPriceLists));
		}

		return [$existingOptions, $allVisibilityLists, $allPriceLists];
	}

	/**
	 * @return int<0, 2>
	 * @throws \StORM\Exception\NotFoundException
	 */
	protected function getCacheIndexToBeUsed(): int
	{
		$readyState = $this->productsCacheStateRepository->many()->where('this.state', 'ready')->first();

		if (!$readyState) {
			return 0;
		}

		$state = (int) $readyState->getPK();

		if ($state < 0 || $state > 2) {
			throw new \Exception("State '$state' out of allowed range!");
		}

		return $state;
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
	 * @param array<object{ancestor: string, showDescendantProducts: bool, showProductsInAncestors: bool}> $allCategories
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
	 *     2: array<object{id: int, ancestor: string, showDescendantProducts: bool, showProductsInAncestors: bool}>,
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

		/** @var array<object{id: int, ancestor: string, showDescendantProducts: bool, showProductsInAncestors: bool}> $allCategories */
		$allCategories = $this->categoryRepository->many()
			->setSelect([
				'this.id',
				'ancestor' => 'this.fk_ancestor',
				'showDescendantProducts' => 'this.showDescendantProducts',
				'showProductsInAncestors' => 'this.showProductsInAncestors',
			], keepIndex: true)
			->fetchArray(\stdClass::class);

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

	/**
	 * @param array<string|int> $customers
	 * @param array<string|int>|null $customerGroups
	 * @param array<string|int> $merchants
	 * @return array{0: array<string, true>, 1: list<int>, 2: list<int>}
	 */
	private function getAllPossibleVisibilityAndPriceListOptionsHelper(array $customers = [], array|null $customerGroups = null, array $merchants = [], Shop|null $shop = null): array
	{
		/** @var array<string|int, \Eshop\DB\Pricelist> $prefetchedPriceLists */
		$prefetchedPriceLists = $this->pricelistRepository->many()->select(['this.id'])->toArray();

		$existingOptions = [];
		$allVisibilityLists = [];
		$allPriceLists = [];

		$customerGroupsQuery = $this->customerGroupRepository->many();

		if ($customerGroups !== null) {
			$customerGroups ?
				$customerGroupsQuery->where('this.uuid', $customerGroups) :
				$customerGroupsQuery->where('1=0');
		}

		// Only customer groups marked as defaultUnregisteredGroup are used
		if ($unregisteredGroups = $this->settingsService->getAllDefaultUnregisteredGroups()) {
			$customerGroupsQuery->where('this.uuid', $unregisteredGroups);
		} else {
			$customerGroupsQuery->where('this.uuid', CustomerGroupRepository::UNREGISTERED_PK);
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
				if ($prefetchedPriceLists[$PK]->getDiscounts()->count() === 0) {
					$fixedPriceLists[$PK] = $id;
				} else {
					$dynamicPriceLists[$PK] = $id;
				}
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
					if ($prefetchedPriceLists[$PK]->getDiscounts()->count() === 0) {
						$fixedPriceLists[$PK] = $id;
					} else {
						$dynamicPriceLists[$PK] = $id;
					}
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
					if ($prefetchedPriceLists[$PK]->getDiscounts()->count() === 0) {
						$fixedPriceLists[$PK] = $id;
					} else {
						$dynamicPriceLists[$PK] = $id;
					}
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

		return [$existingOptions, \array_keys($allVisibilityLists), \array_keys($allPriceLists)];
	}
}
