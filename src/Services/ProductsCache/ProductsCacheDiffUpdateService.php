<?php

namespace Eshop\Services\ProductsCache;

use Carbon\Carbon;
use Eshop\DB\Customer;
use Eshop\DevelTools;
use Nette\DI\MissingServiceException;
use Nette\Utils\Arrays;
use Nette\Utils\FileSystem;
use Nette\Utils\Strings;
use StORM\DIConnection;
use Tracy\Debugger;
use Tracy\ILogger;

/**
 * @deprecated Use GoProductsCacheDiffUpdateService instead
 */
class ProductsCacheDiffUpdateService extends ProductsCacheBaseWarmUpService
{
	private DIConnection $cacheConnection;

	public function getConnection(): \StORM\DIConnection
	{
		if (isset($this->cacheConnection)) {
			return $this->cacheConnection;
		}

		try {
			/** @var \StORM\DIConnection $cacheConnection */
			$cacheConnection = $this->container->getByName('storm.cache');
			$cacheConnection->setDebug(false);

			return $this->cacheConnection = $cacheConnection;
		} catch (MissingServiceException $e) {
			Debugger::log('Storm connection for products cache service was not found.', ILogger::EXCEPTION);

			throw $e;
		}
	}

	/**
	 * Works like warmUpCacheTable, but don't erase all data.
	 * @param array<string|\Eshop\DB\Customer> $customers
	 * @param array<string|int> $customerGroups
	 * @param array<string|int> $merchants
	 */
	public function warmUpCacheTableDiff(array $customers = [], array $customerGroups = [], array $merchants = []): void
	{
		$this->logName = 'ProductsCacheDiffUpdateService-warmUpCacheTableDiff--' . Carbon::now()->format('Y-m-d-H-i-s');

		try {
			$this->getConnection()->exec('SET SESSION group_concat_max_len=4294967295');

			$productsCacheTableName = $this::PRODUCTS_TABLE_NAME;
			$categoriesTableName = $this::CATEGORIES_TABLE_NAME;
			$relationsCacheTableName = $this::RELATIONS_TABLE_NAME;
			$visibilityPricesCacheTableName = $this::PRICES_TABLE_NAME;

			// Start tracking
			Debugger::timer();
			Debugger::timer('warmup_total');

			[
				$allCategoryTypes,
				$allDisplayAmounts,
				$allCategories,
				$allProductPrimaryCategories,
				$productPrimaryCategories,
				$productAttributeValues,
				$productCategories,
			] = $this->getPrefetchedArrays();
			Debugger::log('Prefetch before main table: ' . Debugger::timer() . ', ' . DevelTools::getPeakMemoryUsage(), $this->logName);

			$this->createProductsTable($productsCacheTableName, $allCategoryTypes);

			// update products table - compare cache vs live data
			Debugger::timer();
			[$productsByCategories] = $this->diffUpdateMainTable(
				$productsCacheTableName,
				$allCategoryTypes,
				$allDisplayAmounts,
				$allCategories,
				$allProductPrimaryCategories,
				$productPrimaryCategories,
				$productAttributeValues,
				$productCategories,
			);


			Debugger::log('diffUpdateMainTable: ' . Debugger::timer() . ', ' . DevelTools::getPeakMemoryUsage(), $this->logName);
			$this->diffUpdateRelations($relationsCacheTableName, $productsCacheTableName);
			Debugger::log('diffUpdateRelations: ' . Debugger::timer() . ', ' . DevelTools::getPeakMemoryUsage(), $this->logName);

			$this->diffUpdateCategories($categoriesTableName, $productsCacheTableName, $productsByCategories, $allCategories);
			Debugger::log('createCategoriesTable: ' . Debugger::timer() . ', ' . DevelTools::getPeakMemoryUsage(), $this->logName);

			$this->diffUpdateVisibilityPriceTable($visibilityPricesCacheTableName, $customers, $customerGroups, $merchants);
			Debugger::log('diffUpdateVisibilityPriceTable: ' . Debugger::timer() . ', ' . DevelTools::getPeakMemoryUsage(), $this->logName);

			Debugger::log(\sprintf(
				'=== WARMUP TOTAL: %.3fs, peak memory: %s ===',
				Debugger::timer('warmup_total'),
				DevelTools::getPeakMemoryUsage(),
			), $this->logName);

			$this->cleanProductsProviderCache();
		} catch (\Throwable $e) {
			Debugger::log($e, ILogger::EXCEPTION);
			Debugger::dump($e);

			throw $e;
		}
	}

	/**
	 * @param array<string|\Eshop\DB\Customer> $customers
	 * @param array<string|int> $customerGroups
	 * @param array<string|int> $merchants
	 */
	public function updatePricesTableDiff(array $customers = [], array $customerGroups = [], array $merchants = []): void
	{
		$this->logName = 'ProductsCacheDiffUpdateService-updatePricesTableDiff--' . Carbon::now()->format('Y-m-d-H-i-s');

		try {
			$this->getConnection()->exec('SET SESSION group_concat_max_len=4294967295');

			$visibilityPricesCacheTableName = $this::PRICES_TABLE_NAME;

			// Start tracking
			Debugger::timer();

			$this->diffUpdateVisibilityPriceTable($visibilityPricesCacheTableName, $customers, $customerGroups, $merchants);
			Debugger::log('diffUpdateVisibilityPriceTable: ' . Debugger::timer() . ', ' . DevelTools::getPeakMemoryUsage(), $this->logName);

			$this->cleanProductsProviderCache();
		} catch (\Throwable $e) {
			Debugger::log($e, ILogger::EXCEPTION);
			Debugger::dump($e);

			throw $e;
		}
	}

	/**
	 * @param string $categoriesTableName
	 * @param string $productsCacheTableName
	 * @param array<string, array<int, object{showDescendantProducts: bool, showProductsInAncestors: bool}>> $productsByCategories
	 * @param array<string|int, object{id: int}> $allCategories
	 * @throws \Exception
	 */
	protected function diffUpdateCategories(string $categoriesTableName, string $productsCacheTableName, array $productsByCategories, array $allCategories): void
	{
		$this->getConnection()->exec("
CREATE TABLE IF NOT EXISTS `$categoriesTableName` (
  product BIGINT UNSIGNED NOT NULL,
  category INT UNSIGNED NOT NULL,
  showDescendantProducts BOOL NOT NULL,
  showProductsInAncestors BOOL NOT NULL,
  PRIMARY KEY (product, category),
  CONSTRAINT FOREIGN KEY (product) REFERENCES $productsCacheTableName(product) ON UPDATE CASCADE ON DELETE CASCADE,
  INDEX (category)
);");

		$query = $this->getConnection()->query("
	SELECT COLUMN_NAME
	FROM INFORMATION_SCHEMA.COLUMNS 
	WHERE TABLE_SCHEMA = DATABASE() 
	  AND TABLE_NAME = '$categoriesTableName'
	  AND COLUMN_NAME IN ('showInCategory', 'showDescendantProducts', 'showProductsInAncestors')
");

		$columns = $query->fetchAll(\PDO::FETCH_ASSOC);

		$columns = \array_combine(\array_column($columns, 'COLUMN_NAME'), \array_column($columns, 'COLUMN_NAME'));

		if (isset($columns['showInCategory'])) {
			$this->getConnection()->exec("ALTER TABLE `$categoriesTableName` DROP COLUMN `showInCategory`;");
		}

		if (!isset($columns['showDescendantProducts'])) {
			$this->getConnection()->exec("ALTER TABLE `$categoriesTableName` ADD COLUMN `showDescendantProducts` BOOL NOT NULL;");
		}

		if (!isset($columns['showProductsInAncestors'])) {
			$this->getConnection()->exec("ALTER TABLE `$categoriesTableName` ADD COLUMN `showProductsInAncestors` BOOL NOT NULL;");
		}

		$categoriesInCache = $this->getConnection()->rows([$categoriesTableName])
			->fetchArray(\stdClass::class);

		$categoriesWithProductsInCache = [];

		foreach ($categoriesInCache as $index => $categoryInCache) {
			$categoriesWithProductsInCache[$categoryInCache->category][$categoryInCache->product] = $categoryInCache;

			unset($categoriesInCache[$index]);
		}

		unset($categoriesInCache);

		$categoriesToInsert = [];

		foreach ($productsByCategories as $category => $products) {
			$categoryId = $allCategories[$category]->id;

			foreach ($products as $product => $data) {
				if (isset($categoriesWithProductsInCache[$categoryId][$product])) {
					$categoryInCache = $categoriesWithProductsInCache[$categoryId][$product];

					if ($categoryInCache->showDescendantProducts === $data->showDescendantProducts && $categoryInCache->showProductsInAncestors === $data->showProductsInAncestors) {
						unset($categoriesWithProductsInCache[$categoryId][$product]);

						continue;
					}
				}

				$categoriesToInsert[] = [
					$product,
					$categoryId,
					$data->showDescendantProducts,
					$data->showProductsInAncestors,
				];
			}
		}

		if ($categoriesWithProductsInCache) {
			foreach ($categoriesWithProductsInCache as $category => $products) {
				$this->getConnection()->rows([$categoriesTableName])
					->where('category', $category)
					->where('product', \array_keys($products))
					->delete();
			}
		}

		$this->loadDataInfile($categoriesTableName, $categoriesToInsert);
	}

	/**
	 * @param string $productsCacheTableName
	 * @param array<object{id: int}> $allCategoryTypes
	 * @param array<object{isSold: bool}> $allDisplayAmounts
	 * @param array<object{ancestor: string, showDescendantProducts: bool, showProductsInAncestors: bool, descendants: array<string>}> $allCategories
	 * @param array<object{category: string|null, categoryType: string}> $allProductPrimaryCategories
	 * @param array<object{groupedValues: string}> $productPrimaryCategories
	 * @param array<object{groupedValues: string}> $productAttributeValues
	 * @param array<object{groupedValues: string}> $productCategories
	 * @return array{
	 *     0: array<string, array<int, object{showDescendantProducts: bool, showProductsInAncestors: bool}>>,
	 *     1: array<int, true>
	 * }
	 * @throws \Exception
	 */
	protected function diffUpdateMainTable(
		string $productsCacheTableName,
		array $allCategoryTypes,
		array $allDisplayAmounts,
		array $allCategories,
		array $allProductPrimaryCategories,
		array $productPrimaryCategories,
		array $productAttributeValues,
		array $productCategories,
	): array {
		$statement = $this->getConnection()->query("SHOW COLUMNS FROM `$productsCacheTableName` LIKE 'primaryCategory_%'");
		$currentColumns = $statement->fetchAll(\PDO::FETCH_COLUMN);

		foreach ($allCategoryTypes as $categoryType) {
			if (($key = \array_search("primaryCategory_$categoryType->id", $currentColumns)) !== false) {
				unset($currentColumns[$key]);

				continue;
			}

			$this->getConnection()->exec("ALTER TABLE $productsCacheTableName ADD primaryCategory_{$categoryType->id} INT UNSIGNED ALGORITHM=INSTANT;");
			$this->getConnection()->exec("ALTER TABLE `$productsCacheTableName` ADD INDEX idx_primaryCategory_{$categoryType->id} (primaryCategory_{$categoryType->id}) ALGORITHM=INPLACE;");
		}

		foreach ($currentColumns as $currentColumn) {
			$this->getConnection()->exec("ALTER TABLE `$productsCacheTableName` DROP INDEX `idx_$currentColumn` ALGORITHM=INSTANT;");
			$this->getConnection()->exec("ALTER TABLE `$productsCacheTableName` DROP COLUMN `$currentColumn` ALGORITHM=INPLACE;");
		}

		$mutationSuffix = $this->getMutationSuffix();
//      $this->connection->setDebug(true);
//      $this->getConnection()->setDebug(true);

		$productsCollection = $this->productRepository->many()
			->join(['masterProduct' => 'eshop_product'], 'this.fk_masterProduct = masterProduct.uuid')
			->join(['price' => 'eshop_price'], 'this.uuid = price.fk_product')
			->join(['eshop_displayamount'], 'this.fk_displayAmount = eshop_displayamount.uuid')
			->join(['eshop_displaydelivery'], 'this.fk_displayDelivery = eshop_displaydelivery.uuid')
			->join(['eshop_producer'], 'this.fk_producer = eshop_producer.uuid')
			->join(['eshop_product_nxn_eshop_ribbon'], 'this.uuid = eshop_product_nxn_eshop_ribbon.fk_product')
			->join(['eshop_ribbon'], 'eshop_product_nxn_eshop_ribbon.fk_ribbon = eshop_ribbon.uuid')
			->join(['eshop_product_nxn_eshop_internalribbon'], 'this.uuid = eshop_product_nxn_eshop_internalribbon.fk_product')
			->join(['eshop_internalribbon'], 'eshop_product_nxn_eshop_internalribbon.fk_internalribbon = eshop_internalribbon.uuid')
			->setSelect([
				'id' => 'this.id',
				'fkDisplayAmount' => 'eshop_displayamount.id',
				'fkDisplayDelivery' => 'eshop_displaydelivery.id',
				'fkProducer' => 'eshop_producer.id',
				'fkMasterProduct' => 'masterProduct.id',
				'name' => "this.name$mutationSuffix",
				'code' => 'this.code',
				'subCode' => 'this.subCode',
				'externalCode' => 'this.externalCode',
				'ean' => 'COALESCE(this.secondaryEan, this.ean)',
				'ribbons' => 'GROUP_CONCAT(DISTINCT eshop_ribbon.uuid SEPARATOR ",")',
				'internalRibbons' => 'GROUP_CONCAT(DISTINCT eshop_internalribbon.uuid SEPARATOR ",")',
				'published' => 'this.published',
				'buyCount' => 'this.buyCount',
			])
			->setTake(1000000)
			->setGroupBy(['this.id']);

		$productsInCache = $this->getConnection()
			->rows([$productsCacheTableName])
			->setIndex('product')
			->fetchArray(\stdClass::class);

		$productsToCreate = [];
		$productsToUpdate = [];
		$productsByCategories = [];
		$productsToBeInCache = [];

		Debugger::timer('diffUpdateMainTable -- main query');
		$fetchedProducts = $productsCollection->fetchArray(\stdClass::class);
		Debugger::log('diffUpdateMainTable -- main query: ' . Debugger::timer('diffUpdateMainTable -- main query'), $this->logName);

		foreach ($fetchedProducts as $product) {
			$productData = [
				'product' => $product->id,
				'producer' => $product->fkProducer,
				'displayAmount' => $product->fkDisplayAmount,
				'displayDelivery' => $product->fkDisplayDelivery,
				'displayAmount_isSold' => $product->fkDisplayAmount ? ((int) $allDisplayAmounts[$product->fkDisplayAmount]->isSold) : null,
				'attributeValues' => isset($productAttributeValues[$product->id]) ? $productAttributeValues[$product->id]->groupedValues : null,
				'name' => $product->name,
				'code' => $product->code ? (string) $product->code : null,
				'subCode' => $product->subCode ? (string) $product->subCode : null,
				'externalCode' => $product->externalCode ? (string) $product->externalCode : null,
				'ean' => $product->ean ? (string) $product->ean : null,
				'masterProduct' => $product->fkMasterProduct,
				'ribbons' => $product->ribbons ?: null,
				'internalRibbons' => $product->internalRibbons ?: null,
				'published' => $product->published ?: null,
				'buyCount' => $product->buyCount ?: null,
			];

			$primaryCategories = isset($productPrimaryCategories[$product->id]) ? \explode(',', $productPrimaryCategories[$product->id]->groupedValues) : [];

			foreach ($primaryCategories as $primaryCategory) {
				$primaryCategory = $allProductPrimaryCategories[$primaryCategory];

				$productData["primaryCategory_$primaryCategory->categoryType"] = $primaryCategory->category;
			}

			foreach ($allCategoryTypes as $categoryType) {
				$primaryCategoryTypeIndex = "primaryCategory_$categoryType->id";

				if (isset($productData[$primaryCategoryTypeIndex])) {
					continue;
				}

				$productData[$primaryCategoryTypeIndex] = null;
			}

			if ($categories = ($productCategories[$product->id] ?? null)) {
				$categories = \explode(',', $categories->groupedValues);

				foreach ($categories as $category) {
					$categoryEntity = $allCategories[$category];

					$productsByCategories[$category][$product->id] = (object) [
						'showProductsInAncestors' => $categoryEntity->showProductsInAncestors,
						'showDescendantProducts' => $categoryEntity->showDescendantProducts,
					];
				}
			}

			unset($productData['categories']);

			$cacheProductData = $productsInCache[$product->id] ?? null;

			if ($cacheProductData) {
				$diff = \array_diff_assoc($productData, (array) $cacheProductData);

				if ($diff) {
					$productsToUpdate[$product->id] = $diff;
				}
			} else {
				$productsToCreate[$product->id] = $productData;
			}

			$productsToBeInCache[$product->id] = true;

			unset($productsInCache[$product->id]);
		}

		Debugger::log(\sprintf(
			'diffUpdateMainTable -- diff: %d to create, %d to update, %d to delete, %d total products',
			\count($productsToCreate),
			\count($productsToUpdate),
			\count($productsInCache),
			\count($productsToBeInCache),
		), $this->logName);

		Debugger::timer('mainTable_creates');

		if ($productsToCreate) {
			Debugger::log(
				'diffUpdateMainTable -- created: ' . $this->getConnection()->createRows($productsCacheTableName, \array_values($productsToCreate), ignore: true, chunkSize: 1000)->getRowCount(),
				$this->logName,
			);
		}

		Debugger::log(\sprintf('diffUpdateMainTable -- creates: %.3fs', Debugger::timer('mainTable_creates')), $this->logName);

		$updatedCount = 0;
		Debugger::timer('mainTable_updates');

		foreach (\array_chunk($productsToUpdate, 1000, true) as $chunk) {
			$this->getConnection()->beginTransaction();

			foreach ($chunk as $product => $row) {
				try {
					$updatedCount += $this->getConnection()->rows([$productsCacheTableName])->where('product', $product)->update($row);
				} catch (\Exception $e) {
					Debugger::log($e, ILogger::EXCEPTION);
				}
			}

			$this->getConnection()->commit();
		}

		Debugger::log(\sprintf('diffUpdateMainTable -- updated: %d in %.3fs', $updatedCount, Debugger::timer('mainTable_updates')), $this->logName);

		Debugger::timer('mainTable_deletes');

		if ($productsInCache) {
			Debugger::log(
				'diffUpdateMainTable -- deleted: ' .
				$this->getConnection()->rows([$productsCacheTableName])
					->where('product', \array_keys($productsInCache))
					->delete(),
				$this->logName
			);
		}

		Debugger::log(\sprintf('diffUpdateMainTable -- deletes: %.3fs', Debugger::timer('mainTable_deletes')), $this->logName);

		return [$productsByCategories, $productsToBeInCache];
	}

	/**
	 * @param array<int> $allVisibilityLists
	 * @param array<int> $allPriceLists
	 * @return array{0: array<mixed>, 1: array<mixed>}
	 */
	protected function getPrefetchedArraysForPriceTable(array $allVisibilityLists, array $allPriceLists): array
	{
		Debugger::timer('diffUpdateVisibilityPriceTable -- prefetch -- vli');

		/** @var array<int, array<int, \stdClass>> $allProductsWithVLI */
		$allProductsWithVLI = [];
		$allProductsWithVLIQuery = $this->visibilityListItemRepository->many()
			->join(['visibilityList' => 'eshop_visibilitylist'], 'this.fk_visibilityList = visibilityList.uuid', type: 'INNER')
			->join(['product' => 'eshop_product'], 'this.fk_product = product.uuid', type: 'INNER')
			->where('visibilityList.id', $allVisibilityLists)
			->setSelect([
				'this.hidden',
				'this.hiddenInMenu',
				'this.priority',
				'this.unavailable',
				'this.recommended',
				'productId' => 'product.id',
				'visibilityListId' => 'visibilityList.id',
			])
			->setIndex('product.id')
			->orderBy(['product.id' => 'ASC', 'visibilityList.priority' => 'ASC']);

		while ($item = $allProductsWithVLIQuery->fetch(\stdClass::class)) {
			/** @var \stdClass $item */

			$allProductsWithVLI[$item->productId][$item->visibilityListId] = $item;
		}

		$allProductsWithVLIQuery->__destruct();
		unset($allProductsWithVLIQuery);

		Debugger::log('diffUpdateVisibilityPriceTable -- prefetch -- vli: ' .
			Debugger::timer('diffUpdateVisibilityPriceTable -- prefetch -- vli') . ', ' . DevelTools::getPeakMemoryUsage(), $this->logName);

		Debugger::timer('diffUpdateVisibilityPriceTable -- prefetch -- price');

		/** @var array<int, array<int, \stdClass>> $allProductsWithPrice */
		$allProductsWithPrice = [];

		$allProductsWithPriceQuery = $this->priceRepository->many()
			->join(['priceList' => 'eshop_pricelist'], 'this.fk_pricelist = priceList.uuid', type: 'INNER')
			->join(['product' => 'eshop_product'], 'this.fk_product = product.uuid', type: 'INNER')
			->where('priceList.id', $allPriceLists)
			->setSelect([
				'this.price',
				'this.priceVat',
				'this.priceBefore',
				'this.priceVatBefore',
				'priceHidden' => 'this.hidden',
				'productId' => 'product.id',
				'priceListId' => 'priceList.id',
				'priceListPriority' => 'priceList.priority',
			]);

		while ($item = $allProductsWithPriceQuery->fetch(\stdClass::class)) {
			/** @var \stdClass $item */

			$allProductsWithPrice[$item->productId][$item->priceListId] = $item;
		}

		$allProductsWithPriceQuery->__destruct();
		unset($allProductsWithPriceQuery);

		Debugger::log('diffUpdateVisibilityPriceTable -- prefetch -- price: ' .
			Debugger::timer('diffUpdateVisibilityPriceTable -- prefetch -- price') . ', ' . DevelTools::getPeakMemoryUsage(), $this->logName);

		Debugger::timer('diffUpdateVisibilityPriceTable -- prefetch -- price sort');

		foreach ($allProductsWithPrice as &$priceListItems) {
			// sort by priority, then by priceListId for deterministic order
			\uasort($priceListItems, static function ($a, $b) {
				return $a->priceListPriority <=> $b->priceListPriority ?: $a->priceListId <=> $b->priceListId;
			});
		}

		Debugger::log('diffUpdateVisibilityPriceTable -- prefetch -- price sort: ' .
			Debugger::timer('diffUpdateVisibilityPriceTable -- prefetch -- price sort') . ', ' . DevelTools::getPeakMemoryUsage(), $this->logName);

		return [$allProductsWithVLI, $allProductsWithPrice];
	}

	/**
	 * @param string $pricesCacheTableName
	 * @param array<string|\Eshop\DB\Customer> $customers
	 * @param array<string|int> $customerGroups
	 * @param array<string|int> $merchants
	 * @throws \StORM\Exception\GeneralException
	 */
	protected function diffUpdateVisibilityPriceTable(string $pricesCacheTableName, array $customers = [], array $customerGroups = [], array $merchants = []): void
	{
		foreach ($customers as &$customer) {
			if ($customer instanceof Customer) {
				$customer = $customer->getPK();
			}
		}

		Debugger::timer('getAllPossibleVisibilityAndPriceListOptions');
		[$visibilityPriceListsOptions, $allVisibilityLists, $allPriceLists, $merchantIndexes] = $this->getAllPossibleVisibilityAndPriceListOptions($customers, $customerGroups, $merchants);

		Debugger::log(
			'diffUpdateVisibilityPriceTable -- getAllPossibleVisibilityAndPriceListOptions: ' . Debugger::timer('getAllPossibleVisibilityAndPriceListOptions') .
			', ' . DevelTools::getPeakMemoryUsage(),
			$this->logName,
		);

		Debugger::timer('diffUpdateVisibilityPriceTable -- prefetch');

		[$allProductsWithVLI, $allProductsWithPrice] = $this->getPrefetchedArraysForPriceTable($allVisibilityLists, $allPriceLists);

		Debugger::log('diffUpdateVisibilityPriceTable -- prefetch: ' . Debugger::timer('diffUpdateVisibilityPriceTable -- prefetch') . ', ' . DevelTools::getPeakMemoryUsage(), $this->logName);

		Debugger::timer('diffUpdateVisibilityPriceTable -- main while');

		$cacheSelectTime = 0;
		$rowComputeTime = 0.0;
		$hashComputeTime = 0.0;
		$dbWriteTime = 0.0;
		$tablesCreated = 0;
		$tablesDeduped = 0;
		$tablesUpdated = 0;
		$tablesUnchanged = 0;
		$totalIndexes = \count($visibilityPriceListsOptions);

		Debugger::timer('show_tables');
		$existingPricesCacheTables = $this->getConnection()
			->query("SHOW TABLES LIKE 'prices\\_%';")
			->fetchAll(\PDO::FETCH_COLUMN);

		$existingPricesCacheTables = \array_combine($existingPricesCacheTables, $existingPricesCacheTables);
		Debugger::log(\sprintf(
			'SHOW TABLES: %.3fs (%d existing price tables)',
			Debugger::timer('show_tables'),
			\count($existingPricesCacheTables),
		), $this->logName);

		// Deduplication only for full warmup (no specific customers/groups/merchants)
		// Partial updates can't use dedup because shared physical tables could be corrupted
		$isPartialUpdate = $customers || $customerGroups || $merchants;
		$dedup = $this->isCacheDeduplicationEnabled() && !$isPartialUpdate;

		Debugger::timer('dedup_setup');

		/** @var array<string, array{physical_table: string, content_hash: string}> $existingMappings */
		$existingMappings = [];
		/** @var array<string, true> $touchedIndexes */
		$touchedIndexes = [];

		if ($dedup) {
			$this->ensureMappingTable();

			// Load existing mappings for hash comparison (skip unchanged indexes)
			$mappingRows = $this->getConnection()
				->query('SELECT price_index, physical_table, content_hash FROM `price_table_map`')
				->fetchAll(\PDO::FETCH_ASSOC);

			foreach ($mappingRows as $row) {
				$existingMappings[$row['price_index']] = [
					'physical_table' => $row['physical_table'],
					'content_hash' => $row['content_hash'],
				];
			}
		} elseif ($isPartialUpdate && $this->isCacheDeduplicationEnabled()) {
			// Remove stale mappings for indexes being recalculated in partial update
			// so the getter falls back to canonical table names
			$this->ensureMappingTable();

			foreach (\array_keys($visibilityPriceListsOptions) as $partialIndex) {
				$this->getConnection()->exec(
					'DELETE FROM `price_table_map` WHERE price_index = ' .
					$this->getConnection()->getLink()->quote($partialIndex),
				);
			}
		}

		Debugger::log(\sprintf(
			'dedup setup: %.3fs (dedup=%s, partial=%s)',
			Debugger::timer('dedup_setup'),
			$dedup ? 'yes' : 'no',
			$isPartialUpdate ? 'yes' : 'no',
		), $this->logName);

		// Group indexes by visibility list prefix to pre-resolve VLI once per group
		/** @var array<string, list<string>> $indexesByVL */
		$indexesByVL = [];

		foreach (\array_keys($visibilityPriceListsOptions) as $index) {
			$explodedIndex = \explode('-', $index);

			if (\count($explodedIndex) !== 2) {
				continue;
			}

			$indexesByVL[$explodedIndex[0]][] = $index;
		}

		Debugger::log(\sprintf('>>> Starting prices loop: %d indexes in %d VL groups', $totalIndexes, \count($indexesByVL)), $this->logName);

		$loopIteration = 0;
		$loopStartTime = \microtime(true);
		$vlResolveTime = 0.0;

		foreach ($indexesByVL as $vlKey => $groupIndexes) {
			/** @var array<int> $visibilityLists */
			$visibilityLists = \explode(',', $vlKey);

			// Pre-resolve VLI for all products once per VL group
			Debugger::timer('vl_resolve');

			/** @var array<int, \stdClass> $productVLI */
			$productVLI = [];

			foreach ($allProductsWithVLI as $product => $vliItems) {
				foreach ($visibilityLists as $visibilityListId) {
					if (isset($vliItems[$visibilityListId])) {
						$productVLI[$product] = $vliItems[$visibilityListId];

						break;
					}
				}
			}

			$vlResolveTime += Debugger::timer('vl_resolve');

			// Base/variant optimization: pre-compute stable rows once per VL group
			/** @var array<int, array<mixed>> $baseRows */
			$baseRows = [];
			/** @var array<int, true> $variantProducts */
			$variantProducts = [];
			$baseHashCtx = null;

			if ($dedup) {
				Debugger::timer('base_variant_setup');

				// Find base PLs (intersection of all PL sets) and variant PLs
				$basePLSet = null;
				$allPLUnion = [];

				foreach ($groupIndexes as $idx) {
					$plSet = \array_flip(\explode(',', \explode('-', $idx)[1]));
					$allPLUnion += $plSet;
					$basePLSet = $basePLSet === null ? $plSet : \array_intersect_key($basePLSet, $plSet);
				}

				$basePLSet ??= [];
				$variantPLSet = \array_diff_key($allPLUnion, $basePLSet);
				$basePLIds = \array_keys($basePLSet);

				// Check if merchant status varies within group
				$hasMerchant = false;
				$hasNonMerchant = false;

				foreach ($groupIndexes as $idx) {
					if (isset($merchantIndexes[$idx])) {
						$hasMerchant = true;
					} else {
						$hasNonMerchant = true;
					}

					if ($hasMerchant && $hasNonMerchant) {
						break;
					}
				}

				$merchantVaries = $hasMerchant && $hasNonMerchant;
				$isMerchantOnly = $hasMerchant && !$hasNonMerchant;

				// Find variant products (prices in variant PLs or priceHidden with mixed merchant status)
				foreach ($productVLI as $product => $vli) {
					if (!isset($allProductsWithPrice[$product])) {
						continue;
					}

					$priceItems = $allProductsWithPrice[$product];

					foreach (\array_keys($variantPLSet) as $vpl) {
						if (isset($priceItems[$vpl])) {
							$variantProducts[$product] = true;

							break;
						}
					}

					if (isset($variantProducts[$product]) || !$merchantVaries) {
						continue;
					}

					foreach ($basePLIds as $plId) {
						if (isset($priceItems[$plId]) && $priceItems[$plId]->priceHidden) {
							$variantProducts[$product] = true;

							break;
						}
					}
				}

				// Compute base rows (stable products only, using base PLs)
				foreach ($productVLI as $product => $vli) {
					if (isset($variantProducts[$product])) {
						continue;
					}

					if (!isset($allProductsWithPrice[$product])) {
						continue;
					}

					$priceItems = $allProductsWithPrice[$product];

					foreach ($basePLIds as $plId) {
						if (!isset($priceItems[$plId])) {
							continue;
						}

						$price = $priceItems[$plId];

						if (!$isMerchantOnly && $price->priceHidden) {
							continue;
						}

						$baseRows[$product] = [
							'product' => $product,
							'price' => $price->price,
							'priceVat' => $price->priceVat,
							'priceBefore' => $price->priceBefore ?: null,
							'priceVatBefore' => $price->priceVatBefore ?: null,
							'priceList' => $plId,
							'hidden' => $vli->hidden,
							'hiddenInMenu' => $vli->hiddenInMenu,
							'priority' => $vli->priority,
							'unavailable' => $vli->unavailable,
							'recommended' => $vli->recommended,
						];

						break;
					}
				}

				// Pre-compute base hash context
				$baseHashCtx = \hash_init('sha256');

				foreach ($baseRows as $product => $row) {
					\hash_update($baseHashCtx, $product . ':' . \implode(',', \array_map('strval', $row)) . "\n");
				}

				Debugger::log(\sprintf(
					'VL group %s: %d indexes, %d base products, %d variant products, %d base PLs, %d variant PLs (setup: %.3fs)',
					$vlKey,
					\count($groupIndexes),
					\count($baseRows),
					\count($variantProducts),
					\count($basePLIds),
					\count($variantPLSet),
					Debugger::timer('base_variant_setup'),
				), $this->logName);
			}

			// Pre-compute hash strings per product per PL (avoids string formatting in per-index loop)
			/** @var array<int, array<int, array{hashStr: string, priceHidden: bool}>> $productHashData */
			$productHashData = [];

			foreach ($productVLI as $product => $vli) {
				if (!isset($allProductsWithPrice[$product])) {
					continue;
				}

				$priceItems = $allProductsWithPrice[$product];

				foreach ($priceItems as $plId => $price) {
					$pb = $price->priceBefore ?: null;
					$pvb = $price->priceVatBefore ?: null;

					$productHashData[$product][$plId] = [
						'hashStr' => "$product:$product,$price->price,$price->priceVat,$pb,$pvb,$plId,$vli->hidden,$vli->hiddenInMenu,$vli->priority,$vli->unavailable,$vli->recommended\n",
						'priceHidden' => (bool) $price->priceHidden,
					];
				}
			}

			// Group-level hash: skip entire VL group if input data unchanged
			$groupHash = '';

			if ($dedup) {
				Debugger::timer('group_hash');
				$groupCtx = \hash_init('sha256');

				// Hash all product price data (one pass for entire group)
				foreach ($productHashData as $plData) {
					foreach ($plData as $data) {
						\hash_update($groupCtx, $data['hashStr']);
					}
				}

				// Hash index configuration (PL sets + merchant status)
				foreach ($groupIndexes as $idx) {
					\hash_update($groupCtx, $idx . (isset($merchantIndexes[$idx]) ? 'M' : 'N'));
				}

				$groupHash = \hash_final($groupCtx);
				$groupHashKey = "__group_$vlKey";
				$storedGroupHash = $existingMappings[$groupHashKey]['content_hash'] ?? null;
				$groupHashTime = Debugger::timer('group_hash');
				$hashComputeTime += $groupHashTime;

				if ($storedGroupHash === $groupHash) {
					// All indexes in this group are unchanged — skip entire group
					$skipped = 0;

					foreach ($groupIndexes as $idx) {
						if (isset($existingMappings[$idx])) {
							unset($existingPricesCacheTables[$existingMappings[$idx]['physical_table']]);
							$touchedIndexes[$idx] = true;
							$tablesUnchanged++;
							$skipped++;
						}
					}

					$touchedIndexes[$groupHashKey] = true;
					$loopIteration += $skipped;

					Debugger::log(\sprintf(
						'VL group %s: group hash MATCH, skipped %d indexes (%.3fs)',
						$vlKey,
						$skipped,
						$groupHashTime,
					), $this->logName);

					continue;
				}

				Debugger::log(\sprintf(
					'VL group %s: group hash CHANGED, processing %d indexes (%.3fs)',
					$vlKey,
					\count($groupIndexes),
					$groupHashTime,
				), $this->logName);
			}

			foreach ($groupIndexes as $index) {
				$loopIteration++;

				if ($loopIteration === 1 || $loopIteration % 100 === 0 || $loopIteration === $totalIndexes) {
					$pct = (int) \round($loopIteration / $totalIndexes * 100);
					Debugger::log(\sprintf(
						'prices loop: %d/%d (%d%%) | c:%d d:%d unch:%d upd:%d | %.1fs | vl:%.1f comp:%.1f hash:%.1f sel:%.1f wr:%.1f',
						$loopIteration,
						$totalIndexes,
						$pct,
						$tablesCreated,
						$tablesDeduped,
						$tablesUnchanged,
						$tablesUpdated,
						\microtime(true) - $loopStartTime,
						$vlResolveTime,
						$rowComputeTime,
						$hashComputeTime,
						$cacheSelectTime,
						$dbWriteTime,
					), $this->logName);
				}

				$currentIndexTableName = "$pricesCacheTableName$index";

				if (Strings::length($currentIndexTableName) > 63) {
					$currentIndexTableName = DIConnection::generateUuid7('cache_prices', $currentIndexTableName);
				}

				/** @var array<int> $priceLists */
				$priceLists = \explode(',', \explode('-', $index)[1]);

				/** @var array<int, array<mixed>> $priceRows */
				$priceRows = [];
				$hash = '';

				if ($dedup) {
					$isMerchantIndex = isset($merchantIndexes[$index]);

					// Phase 1: Hash-only pass using pre-computed strings
					Debugger::timer('dedup_hash');
					$ctx = \hash_init('sha256');

					foreach ($productHashData as $plData) {
						foreach ($priceLists as $plId) {
							if (!isset($plData[$plId])) {
								continue;
							}

							if (!$isMerchantIndex && $plData[$plId]['priceHidden']) {
								continue;
							}

							\hash_update($ctx, $plData[$plId]['hashStr']);

							break;
						}
					}

					$hash = \hash_final($ctx);
					$hashComputeTime += Debugger::timer('dedup_hash');

					// Check if index data is unchanged from previous warmup
					$storedMapping = $existingMappings[$index] ?? null;

					if ($storedMapping !== null && $storedMapping['content_hash'] === $hash) {
						// Data unchanged → keep existing mapping, skip compute + SELECT + diff-update
						unset($existingPricesCacheTables[$storedMapping['physical_table']]);
						$touchedIndexes[$index] = true;
						$tablesUnchanged++;

						continue;
					}

					$existingTable = $this->findExistingTableByHash($hash);

					if ($existingTable) {
						$this->registerTableMapping($index, $existingTable, $hash);
						unset($existingPricesCacheTables[$currentIndexTableName]);
						$touchedIndexes[$index] = true;
						$tablesDeduped++;

						continue;
					}

					// Phase 2: Hash didn't match → build full rows for INSERT/diff-update
					Debugger::timer('dedup_compute');

					foreach ($productVLI as $product => $vli) {
						if (!isset($allProductsWithPrice[$product])) {
							continue;
						}

						$priceItems = $allProductsWithPrice[$product];

						foreach ($priceLists as $plId) {
							if (!isset($priceItems[$plId])) {
								continue;
							}

							$price = $priceItems[$plId];

							if (!$isMerchantIndex && $price->priceHidden) {
								continue;
							}

							$priceRows[$product] = [
								'product' => $product,
								'price' => $price->price,
								'priceVat' => $price->priceVat,
								'priceBefore' => $price->priceBefore ?: null,
								'priceVatBefore' => $price->priceVatBefore ?: null,
								'priceList' => $plId,
								'hidden' => $vli->hidden,
								'hiddenInMenu' => $vli->hiddenInMenu,
								'priority' => $vli->priority,
								'unavailable' => $vli->unavailable,
								'recommended' => $vli->recommended,
							];

							break;
						}
					}

					$rowComputeTime += Debugger::timer('dedup_compute');

					$isNewTable = !isset($existingPricesCacheTables[$currentIndexTableName]);

					if ($isNewTable) {
						try {
							$this->createVisibilityPriceTable($currentIndexTableName);
						} catch (\Exception $e) {
							Debugger::log($e, ILogger::EXCEPTION);

							continue;
						}

						// New table is empty → direct bulk INSERT (skip SELECT + diff)
						Debugger::timer('prod_write');
						$this->bulkLoadPriceRows($currentIndexTableName, $priceRows);
						$dbWriteTime += Debugger::timer('prod_write');

						$this->registerTableMapping($index, $currentIndexTableName, $hash);
						$touchedIndexes[$index] = true;
					}

					unset($existingPricesCacheTables[$currentIndexTableName]);
					$tablesCreated++;

					if ($isNewTable) {
						continue;
					}

					$touchedIndexes[$index] = true;
				}

				// Diff-update: shared by both dedup and production paths (existing tables only)
				$quotedTableName = "`$currentIndexTableName`";

				$pricesToCreate = [];
				$pricesToUpdate = [];

				Debugger::timer('cacheSelectTime');
				$cachePrices = $this->getConnection()->rows([$quotedTableName])
					->setIndex('product')
					->setSelect([
						'product',
						'price',
						'priceVat',
						'priceBefore',
						'priceVatBefore',
						'priceList',
						'hidden',
						'hiddenInMenu',
						'priority',
						'unavailable',
						'recommended',
					], keepIndex: true)
					->toArray();
				$cacheSelectTime += Debugger::timer('cacheSelectTime');

				Debugger::timer('prod_compute');

				if ($dedup) {
					// Use precomputed rows from hash computation
					foreach ($priceRows as $product => $newPrice) {
						$cachePrice = $cachePrices[$product] ?? null;

						if ($cachePrice) {
							$diff = \array_diff_assoc($newPrice, (array) $cachePrice);

							if ($diff) {
								$pricesToUpdate[$product] = $diff;
							}
						} else {
							$pricesToCreate[$product] = $newPrice;
						}

						unset($cachePrices[$product]);
					}
				} else {
					$isMerchantIndex = isset($merchantIndexes[$index]);

					foreach ($productVLI as $product => $visibilityListItem) {
						if (!isset($allProductsWithPrice[$product])) {
							continue;
						}

						$priceItems = $allProductsWithPrice[$product];

						foreach ($priceLists as $priceListId) {
							if (!isset($priceItems[$priceListId])) {
								continue;
							}

							$price = $priceItems[$priceListId];

							// Skip hidden prices for non-merchant indexes
							if (!$isMerchantIndex && $price->priceHidden) {
								continue;
							}

							$cachePrice = $cachePrices[$product] ?? null;

							$newPrice = [
								'product' => $product,
								'price' => $price->price,
								'priceVat' => $price->priceVat,
								'priceBefore' => $price->priceBefore ?: null,
								'priceVatBefore' => $price->priceVatBefore ?: null,
								'priceList' => $priceListId,
								'hidden' => $visibilityListItem->hidden,
								'hiddenInMenu' => $visibilityListItem->hiddenInMenu,
								'priority' => $visibilityListItem->priority,
								'unavailable' => $visibilityListItem->unavailable,
								'recommended' => $visibilityListItem->recommended,
							];

							if ($cachePrice) {
								$diff = \array_diff_assoc($newPrice, (array) $cachePrice);

								if ($diff) {
									$pricesToUpdate[$product] = $diff;
								}
							} else {
								$pricesToCreate[$product] = $newPrice;
							}

							unset($cachePrices[$product]);

							break;
						}
					}
				}

				$rowComputeTime += Debugger::timer('prod_compute');

				Debugger::timer('prod_write');

				foreach (\array_chunk($pricesToUpdate, 10000, true) as $chunk) {
					$this->getConnection()->beginTransaction();

					foreach ($chunk as $product => $row) {
						$this->getConnection()->rows([$quotedTableName])
							->where('product', $product)
							->update($row);
					}

					$this->getConnection()->commit();
				}

				if ($pricesToCreate) {
					$this->getConnection()->createRows($quotedTableName, $pricesToCreate, ignore: true, chunkSize: 10000);
				}

				if (!$cachePrices || $customers) {
					$dbWriteTime += Debugger::timer('prod_write');

					if ($dedup) {
						$this->registerTableMapping($index, $currentIndexTableName, $hash);
						$touchedIndexes[$index] = true;
					}

					continue;
				}

				$this->getConnection()->rows([$quotedTableName])
					->where('product', \array_keys($cachePrices))
					->delete();

				$dbWriteTime += Debugger::timer('prod_write');

				if ($dedup) {
					$this->registerTableMapping($index, $currentIndexTableName, $hash);
					$touchedIndexes[$index] = true;
				}

				$tablesUpdated++;
			}

			// Store group hash for next warmup
			if (!$dedup || $groupHash === '') {
				continue;
			}

			$groupHashKey = "__group_$vlKey";
			$this->registerTableMapping($groupHashKey, '__group', $groupHash);
			$touchedIndexes[$groupHashKey] = true;
		}

		if ($dedup) {
			// Cleanup: remove stale mappings + orphaned physical tables
			if (!$customers && !$customerGroups && !$merchants) {
				// Remove mappings for indexes that were not touched during this warmup
				$staleMappings = \array_diff_key($existingMappings, $touchedIndexes);

				if ($staleMappings) {
					$staleIndexes = \array_map(
						fn(string $idx): string => $this->getConnection()->getLink()->quote($idx),
						\array_keys($staleMappings),
					);
					$this->getConnection()->exec(
						'DELETE FROM `price_table_map` WHERE price_index IN (' . \implode(',', $staleIndexes) . ')',
					);
				}

				// Drop physical tables not referenced by any mapping
				$referencedTables = $this->getConnection()
					->query('SELECT DISTINCT physical_table FROM `price_table_map`')
					->fetchAll(\PDO::FETCH_COLUMN);

				$referencedTablesMap = \array_combine($referencedTables, $referencedTables);

				foreach ($existingPricesCacheTables as $tableName) {
					if (!isset($referencedTablesMap[$tableName])) {
						$this->getConnection()->exec("DROP TABLE IF EXISTS `$tableName`;");
					}
				}
			}
		} elseif ($existingPricesCacheTables && (!$customers && !$customerGroups && !$merchants)) {
			foreach ($existingPricesCacheTables as $tableName) {
				$this->getConnection()->exec("DROP TABLE IF EXISTS `$tableName`;");
			}
		}

		$mainWhileTime = Debugger::timer('diffUpdateVisibilityPriceTable -- main while');

		Debugger::log(\sprintf(
			"--- Prices loop summary ---\n" .
			"  Total indexes: %d in %d VL groups | Main loop: %.3fs\n" .
			"  VL resolve: %.3fs | Cache selects: %.3fs | Row computation: %.3fs | Hash computation: %.3fs | DB writes: %.3fs\n" .
			"  Tables created: %d | Tables deduped: %d | Tables unchanged: %d | Tables diff-updated: %d\n" .
			'  Memory: %s',
			$totalIndexes,
			\count($indexesByVL),
			$mainWhileTime,
			$vlResolveTime,
			$cacheSelectTime,
			$rowComputeTime,
			$hashComputeTime,
			$dbWriteTime,
			$tablesCreated,
			$tablesDeduped,
			$tablesUnchanged,
			$tablesUpdated,
			DevelTools::getPeakMemoryUsage(),
		), $this->logName);
	}

	protected function diffUpdateRelations(string $relationsCacheTableName, string $productsCacheTableName): void
	{
		$this->getConnection()->exec("
CREATE TABLE IF NOT EXISTS `$relationsCacheTableName` (
	uuid VARCHAR(32) PRIMARY KEY,
	master BIGINT UNSIGNED NOT NULL,
	slave BIGINT UNSIGNED NOT NULL,
	priority SMALLINT NOT NULL,
	amount SMALLINT NOT NULL,
	hidden BOOL NOT NULL,
	systemic BOOL NOT NULL,
	discountPct DOUBLE,
	masterPct DOUBLE,
	type INT UNSIGNED NOT NULL,
	CONSTRAINT FOREIGN KEY (master) REFERENCES $productsCacheTableName(product) ON UPDATE CASCADE ON DELETE CASCADE,
	CONSTRAINT FOREIGN KEY (slave) REFERENCES $productsCacheTableName(product) ON UPDATE CASCADE ON DELETE CASCADE,
	INDEX idx_related_master (master, type),
	INDEX idx_related_slave (slave, type),
	INDEX idx_products_related_unique (master, slave),
	UNIQUE INDEX idx_related_code (master, slave, amount, discountPct, masterPct, type)
);");

		// if idx_related_code has no type column, refresh it
		$indexQuery = $this->getConnection()->query("
			SELECT COLUMN_NAME 
			FROM INFORMATION_SCHEMA.STATISTICS 
			WHERE TABLE_SCHEMA = DATABASE() 
			AND TABLE_NAME = '$relationsCacheTableName' 
			AND INDEX_NAME = 'idx_related_code' 
			ORDER BY SEQ_IN_INDEX
		");

		if ($indexQuery !== false) {
			$indexColumns = $indexQuery->fetchAll(\PDO::FETCH_COLUMN);

			// Check if 'type' is in the index columns
			if ($indexColumns && !Arrays::contains($indexColumns, 'type')) {
				// Drop the old index and create a new one with 'type' column
				$this->getConnection()->exec("ALTER TABLE `$relationsCacheTableName` DROP INDEX idx_related_code");
				$this->getConnection()->exec("ALTER TABLE `$relationsCacheTableName` ADD UNIQUE INDEX idx_related_code (master, slave, amount, discountPct, masterPct, type)");
			}
		}

		$relations = $this->relatedRepository->many()
			->where('this.fk_slave IS NOT NULL')
			->join(['type' => 'eshop_relatedtype'], 'this.fk_type = type.uuid')
			->join(['masterProduct' => 'eshop_product'], 'this.fk_master = masterProduct.uuid')
			->join(['slaveProduct' => 'eshop_product'], 'this.fk_slave = slaveProduct.uuid')
			->select([
				'typeId' => 'type.id',
				'masterId' => 'masterProduct.id',
				'slaveId' => 'slaveProduct.id',
			]);

		$rowsToInsert = [];

		foreach ($relations as $relation) {
			$row = [
				'uuid' => $relation->getPK(),
				'master' => $relation->getValue('masterId'),
				'slave' => $relation->getValue('slaveId'),
				'type' => $relation->getValue('typeId'),
				'priority' => $relation->priority,
				'amount' => $relation->amount,
				'hidden' => $relation->hidden ? 1 : 0,
				'systemic' => $relation->isSystemic() ? 1 : 0,
				'discountPct' => $relation->discountPct,
				'masterPct' => $relation->masterPct,
			];

			$rowsToInsert[$relation->getPK()] = $row;
		}

		$this->getConnection()->beginTransaction();

		$this->getConnection()->rows([$relationsCacheTableName])->delete();

		if ($rowsToInsert) {
			$this->getConnection()->createRows($relationsCacheTableName, \array_values($rowsToInsert), chunkSize: 1000);
		}

		$this->getConnection()->commit();
	}

	protected function createVisibilityPriceTable(string $pricesCacheTableName): void
	{
		$this->getConnection()->exec("
CREATE TABLE IF NOT EXISTS `$pricesCacheTableName` (
  product BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  price DOUBLE NOT NULL,
  priceVat DOUBLE,
  priceBefore DOUBLE,
  priceVatBefore DOUBLE,
  priceList INT NOT NULL,
  hidden BOOL NOT NULL,
  hiddenInMenu BOOL NOT NULL,
  priority SMALLINT NOT NULL,
  unavailable BOOL NOT NULL,
  recommended BOOL NOT NULL
);");
	}

	/**
	 * @param string $productsCacheTableName
	 * @param array<object{id: int}> $allCategoryTypes
	 */
	protected function createProductsTable(string $productsCacheTableName, array $allCategoryTypes): void
	{
		// pokud tabulka existuje, proved alter na změnu indexu code
		$statement = $this->getConnection()->query("SHOW TABLES LIKE '$productsCacheTableName'");
		$exists = $statement->fetch(\PDO::FETCH_NUM);

		if ($exists) {
			// pokud tabulka existuje, proved alter na změnu indexu code
			// provést jen pokud exituje index
			$statement = $this->getConnection()->query("SHOW INDEX FROM `$productsCacheTableName` WHERE Key_name = 'idx_unique_code'");

			$exists = $statement->fetch(\PDO::FETCH_NUM);

			if ($exists) {
				// pokud existuje, tak ho smazat a přidat nový
				$this->getConnection()->exec("ALTER TABLE `$productsCacheTableName` DROP INDEX idx_unique_code");
				$this->getConnection()->exec("ALTER TABLE `$productsCacheTableName` ADD INDEX idx_code (code)");
			}
		}

		// Základní dotaz pro vytvoření tabulky se sloupci a indexy (indexy definované v rámci CREATE TABLE)
		$query = "
CREATE TABLE IF NOT EXISTS `$productsCacheTableName` (
  product BIGINT UNSIGNED NOT NULL,
  producer INT UNSIGNED,
  displayAmount INT UNSIGNED,
  displayDelivery INT UNSIGNED,
  displayAmount_isSold BOOL,
  attributeValues TEXT,
  name VARCHAR(255),
  code VARCHAR(255),
  subCode VARCHAR(255),
  externalCode VARCHAR(255),
  ean VARCHAR(255),
  masterProduct BIGINT UNSIGNED,
  PRIMARY KEY (product),
  INDEX idx_producer (producer),
  INDEX idx_displayAmount (displayAmount),
  INDEX idx_displayDelivery (displayDelivery),
  INDEX idx_displayAmount_isSold (displayAmount_isSold),
  INDEX idx_subCode (subCode),
  INDEX idx_externalCode (externalCode),
  FULLTEXT INDEX idx_name (name),
  INDEX idx_code (code),
  INDEX idx_ean (ean),
  INDEX idx_masterProduct (masterProduct)";

		// Přidání indexů pro sloupce primárních kategorií
		foreach ($allCategoryTypes as $categoryType) {
			$query .= ", primaryCategory_{$categoryType->id} INT UNSIGNED, KEY idx_primaryCategory_{$categoryType->id} (primaryCategory_{$categoryType->id})";
		}

		// Uzavření definice tabulky a volba engine/charset dle potřeby
		$query .= '
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;';

		// Provedení celého dotazu najednou
		$this->getConnection()->exec($query);

		// Check if columns exist before adding them
		$query = $this->getConnection()->query("
			SELECT COLUMN_NAME
			FROM INFORMATION_SCHEMA.COLUMNS 
			WHERE TABLE_SCHEMA = DATABASE() 
			AND TABLE_NAME = '$productsCacheTableName' 
			AND COLUMN_NAME IN ('ribbons', 'internalRibbons', 'published', 'buyCount')
		");

		$columns = $query->fetchAll(\PDO::FETCH_ASSOC);
		$columns = \array_combine(\array_column($columns, 'COLUMN_NAME'), \array_column($columns, 'COLUMN_NAME'));

		if (!isset($columns['ribbons'])) {
			$this->getConnection()->exec("ALTER TABLE `$productsCacheTableName` ADD COLUMN `ribbons` TEXT");
		}

		if (!isset($columns['internalRibbons'])) {
			$this->getConnection()->exec("ALTER TABLE `$productsCacheTableName` ADD COLUMN `internalRibbons` TEXT");
		}

		if (!isset($columns['published'])) {
			$this->getConnection()->exec("ALTER TABLE `$productsCacheTableName` ADD COLUMN `published` DATE");
		}

		if (!isset($columns['buyCount'])) {
			$this->getConnection()->exec("ALTER TABLE `$productsCacheTableName` ADD COLUMN `buyCount` INT");
		}

		return;
	}

	private function ensureMappingTable(): void
	{
		$this->getConnection()->exec('
CREATE TABLE IF NOT EXISTS `price_table_map` (
  price_index VARCHAR(255) NOT NULL PRIMARY KEY,
  physical_table VARCHAR(64) NOT NULL,
  content_hash VARCHAR(64) NOT NULL,
  INDEX idx_content_hash (content_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;');
	}

	private function findExistingTableByHash(string $hash): string|null
	{
		$result = $this->getConnection()->query(
			"SELECT physical_table FROM `price_table_map` WHERE content_hash = :hash AND physical_table != '__group' LIMIT 1",
			['hash' => $hash],
		);

		$row = $result->fetch(\PDO::FETCH_ASSOC);

		return $row !== false ? $row['physical_table'] : null;
	}

	private function registerTableMapping(string $index, string $physicalTable, string $hash): void
	{
		$this->getConnection()->exec(
			'INSERT INTO `price_table_map` (price_index, physical_table, content_hash) VALUES (' .
			$this->getConnection()->getLink()->quote($index) . ', ' .
			$this->getConnection()->getLink()->quote($physicalTable) . ', ' .
			$this->getConnection()->getLink()->quote($hash) .
			') ON DUPLICATE KEY UPDATE physical_table = VALUES(physical_table), content_hash = VALUES(content_hash)',
		);
	}

	/**
	 * Bulk load price rows into table using LOAD DATA LOCAL INFILE (10-20x faster than INSERT).
	 * @param array<int, array<mixed>> $priceRows
	 */
	private function bulkLoadPriceRows(string $tableName, array $priceRows): void
	{
		if ($priceRows === []) {
			return;
		}

		$tmpFile = \tempnam(\sys_get_temp_dir(), 'prices_');

		if ($tmpFile === false) {
			// Fallback to createRows if temp file cannot be created
			$this->getConnection()->createRows("`$tableName`", $priceRows, ignore: true, chunkSize: 10000);

			return;
		}

		$fp = \fopen($tmpFile, 'w');
		\assert($fp !== false);

		foreach ($priceRows as $row) {
			$pb = $row['priceBefore'] ?? '\\N';
			$pvb = $row['priceVatBefore'] ?? '\\N';
			\fwrite(
				$fp,
				"{$row['product']}\t{$row['price']}\t{$row['priceVat']}\t$pb\t$pvb\t" .
				"{$row['priceList']}\t{$row['hidden']}\t{$row['hiddenInMenu']}\t" .
				"{$row['priority']}\t{$row['unavailable']}\t{$row['recommended']}\n",
			);
		}

		\fclose($fp);

		$this->getConnection()->getLink()->exec(
			'LOAD DATA LOCAL INFILE ' . $this->getConnection()->getLink()->quote($tmpFile) .
			' INTO TABLE `' . $tableName . '`' .
			" FIELDS TERMINATED BY '\\t'" .
			" LINES TERMINATED BY '\\n'" .
			' (product, price, priceVat, priceBefore, priceVatBefore, priceList, hidden, hiddenInMenu, priority, unavailable, recommended)',
		);

		FileSystem::delete($tmpFile);
	}
}
