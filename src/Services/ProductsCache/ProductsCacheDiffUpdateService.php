<?php

namespace Eshop\Services\ProductsCache;

use Base\Bridges\AutoWireService;
use Carbon\Carbon;
use Eshop\DB\Customer;
use Eshop\DevelTools;
use Nette\DI\MissingServiceException;
use Nette\Utils\Arrays;
use Nette\Utils\Strings;
use StORM\DIConnection;
use Tracy\Debugger;
use Tracy\ILogger;

class ProductsCacheDiffUpdateService extends ProductsCacheBaseWarmUpService implements AutoWireService
{
	private DIConnection $cacheConnection;

	private string $logName;

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
			$link = $this->getLink();
			$link->exec('SET SESSION group_concat_max_len=4294967295');

			$productsCacheTableName = $this::PRODUCTS_TABLE_NAME;
			$categoriesTableName = $this::CATEGORIES_TABLE_NAME;
			$relationsCacheTableName = $this::RELATIONS_TABLE_NAME;
			$visibilityPricesCacheTableName = $this::PRICES_TABLE_NAME;

			// Start tracking
			Debugger::timer();

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
			[$productsByCategories, $productsToBeInCache] = $this->diffUpdateMainTable(
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
			$this->diffUpdateRelations($relationsCacheTableName, $productsCacheTableName, $productsToBeInCache);
			Debugger::log('diffUpdateRelations: ' . Debugger::timer() . ', ' . DevelTools::getPeakMemoryUsage(), $this->logName);

			$this->diffUpdateCategories($categoriesTableName, $productsCacheTableName, $productsByCategories, $allCategories);
			Debugger::log('createCategoriesTable: ' . Debugger::timer() . ', ' . DevelTools::getPeakMemoryUsage(), $this->logName);

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
	 * @param array<string|\Eshop\DB\Customer> $customers
	 * @param array<string|int> $customerGroups
	 * @param array<string|int> $merchants
	 */
	public function updatePricesTableDiff(array $customers = [], array $customerGroups = [], array $merchants = []): void
	{
		$this->logName = 'ProductsCacheDiffUpdateService-updatePricesTableDiff--' . Carbon::now()->format('Y-m-d-H-i-s');

		try {
			$link = $this->getLink();
			$link->exec('SET SESSION group_concat_max_len=4294967295');

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
		$link = $this->getLink();

		$link->exec("
CREATE TABLE IF NOT EXISTS `$categoriesTableName` (
  product BIGINT UNSIGNED NOT NULL,
  category INT UNSIGNED NOT NULL,
  showDescendantProducts BOOL NOT NULL,
  showProductsInAncestors BOOL NOT NULL,
  PRIMARY KEY (product, category),
  CONSTRAINT FOREIGN KEY (product) REFERENCES $productsCacheTableName(product) ON UPDATE CASCADE ON DELETE CASCADE,
  INDEX (category)
);");

		$query = $link->query("
    SELECT COLUMN_NAME
    FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
      AND TABLE_NAME = '$categoriesTableName' 
      AND COLUMN_NAME IN ('showInCategory', 'showDescendantProducts', 'showProductsInAncestors')
");

		if ($query === false) {
			throw new \Exception('Statement creation failed.');
		}

		$columns = $query->fetchAll(\PDO::FETCH_ASSOC);

		$columns = \array_combine(\array_column($columns, 'COLUMN_NAME'), \array_column($columns, 'COLUMN_NAME'));

		if (isset($columns['showInCategory'])) {
			$link->exec("ALTER TABLE `$categoriesTableName` DROP COLUMN `showInCategory`;");
		}

		if (!isset($columns['showDescendantProducts'])) {
			$link->exec("ALTER TABLE `$categoriesTableName` ADD COLUMN `showDescendantProducts` BOOL NOT NULL;");
		}

		if (!isset($columns['showProductsInAncestors'])) {
			$link->exec("ALTER TABLE `$categoriesTableName` ADD COLUMN `showProductsInAncestors` BOOL NOT NULL;");
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
		$statement = $this->getLink()->query("SHOW COLUMNS FROM `$productsCacheTableName` LIKE 'primaryCategory_%'");

		if ($statement === false) {
			throw new \Exception('Statement creation failed.');
		}

		$currentColumns = $statement->fetchAll(\PDO::FETCH_COLUMN);

		foreach ($allCategoryTypes as $categoryType) {
			if (($key = \array_search("primaryCategory_$categoryType->id", $currentColumns)) !== false) {
				unset($currentColumns[$key]);

				continue;
			}

			$this->getLink()->exec("ALTER TABLE $productsCacheTableName ADD primaryCategory_{$categoryType->id} INT UNSIGNED ALGORITHM=INSTANT;");
			$this->getLink()->exec("ALTER TABLE `$productsCacheTableName` ADD INDEX idx_primaryCategory_{$categoryType->id} (primaryCategory_{$categoryType->id}) ALGORITHM=INPLACE;");
		}

		foreach ($currentColumns as $currentColumn) {
			$this->getLink()->exec("ALTER TABLE `$productsCacheTableName` DROP INDEX `idx_$currentColumn` ALGORITHM=INSTANT;");
			$this->getLink()->exec("ALTER TABLE `$productsCacheTableName` DROP COLUMN `$currentColumn` ALGORITHM=INPLACE;");
		}

		$mutationSuffix = $this->getMutationSuffix();
//		$this->connection->setDebug(true);
//		$this->getConnection()->setDebug(true);

		$productsCollection = $this->productRepository->many()
			->join(['masterProduct' => 'eshop_product'], 'this.fk_masterProduct = masterProduct.uuid')
			->join(['price' => 'eshop_price'], 'this.uuid = price.fk_product', type: 'INNER')
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
		$productsToBoInCache = [];

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

			$productsToBoInCache[$product->id] = true;

			unset($productsInCache[$product->id]);
		}

		if ($productsToCreate) {
			Debugger::log(
				'diffUpdateMainTable -- created: ' . $this->getConnection()->createRows($productsCacheTableName, \array_values($productsToCreate), chunkSize: 1000)->getRowCount(),
				$this->logName,
			);
		}

		$updatedCount = 0;

		foreach (\array_chunk($productsToUpdate, 1000, true) as $chunk) {
			$this->getLink()->beginTransaction();

			foreach ($chunk as $product => $row) {
				try {
					$updatedCount += $this->getConnection()->rows([$productsCacheTableName])->where('product', $product)->update($row);
				} catch (\Exception $e) {
					Debugger::log($e, ILogger::EXCEPTION);
				}
			}

			$this->getLink()->commit();
		}

		Debugger::log('diffUpdateMainTable -- updated: ' . $updatedCount, $this->logName);

		if ($productsInCache) {
			Debugger::log(
				'diffUpdateMainTable -- deleted: ' .
				$this->getConnection()->rows([$productsCacheTableName])
					->where('product', \array_keys($productsInCache))
					->delete(),
				$this->logName
			);
		}

		return [$productsByCategories, $productsToBoInCache];
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
			->where('this.hidden', false)
			->setSelect([
				'this.price',
				'this.priceVat',
				'this.priceBefore',
				'this.priceVatBefore',
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
			// sort by priority
			\uasort($priceListItems, static function ($a, $b) {
				return $a->priceListPriority <=> $b->priceListPriority;
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
		[$visibilityPriceListsOptions, $allVisibilityLists, $allPriceLists] = $this->getAllPossibleVisibilityAndPriceListOptions($customers, $customerGroups, $merchants);

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

		$existingPricesCacheTables = $this->getConnection()
			->query("SHOW TABLES LIKE 'prices\\_%';")
			->fetchAll(\PDO::FETCH_COLUMN);

		$existingPricesCacheTables = \array_combine($existingPricesCacheTables, $existingPricesCacheTables);

		foreach (\array_keys($visibilityPriceListsOptions) as $index) {
			$currentIndexTableName = "$pricesCacheTableName$index";

			if (Strings::length($currentIndexTableName) > 63) {
				$currentIndexTableName = DIConnection::generateUuid7('cache_prices', $currentIndexTableName);
			}

			if (!isset($existingPricesCacheTables[$currentIndexTableName])) {
				try {
					$this->createVisibilityPriceTable($currentIndexTableName);
				} catch (\Exception $e) {
					Debugger::log($e, ILogger::EXCEPTION);

					continue;
				}
			}

			unset($existingPricesCacheTables[$currentIndexTableName]);

			$currentIndexTableName = "`$currentIndexTableName`";

			$pricesToCreate = [];
			$pricesToUpdate = [];

			Debugger::timer('cacheSelectTime');
			$cachePrices = $this->getConnection()->rows([$currentIndexTableName])
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

			$explodedIndex = \explode('-', $index);

			if (\count($explodedIndex) !== 2) {
				continue;
			}

			[$visibilityListsString, $priceListsString] = $explodedIndex;
			/** @var array<int> $visibilityLists */
			$visibilityLists = \explode(',', $visibilityListsString);
			/** @var array<int> $priceLists */
			$priceLists = \explode(',', $priceListsString);

			foreach ($allProductsWithVLI as $product => $vliItems) {
				foreach ($visibilityLists as $visibilityListId) {
					if (!isset($vliItems[$visibilityListId])) {
						continue;
					}

					$visibilityListItem = $vliItems[$visibilityListId];

					if (!isset($allProductsWithPrice[$product])) {
						continue;
					}

					$priceItems = $allProductsWithPrice[$product];

					foreach ($priceLists as $priceListId) {
						if (!isset($priceItems[$priceListId])) {
							continue;
						}

						$cachePrice = $cachePrices[$product] ?? null;
						$price = $priceItems[$priceListId];

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

						break 2;
					}
				}
			}

			foreach (\array_chunk($pricesToUpdate, 10000, true) as $chunk) {
				$this->getLink()->beginTransaction();

				foreach ($chunk as $product => $row) {
					$this->getConnection()->rows([$currentIndexTableName])
						->where('product', $product)
						->update($row);
				}

				$this->getLink()->commit();
			}

			if ($pricesToCreate) {
				$this->getConnection()->createRows($currentIndexTableName, $pricesToCreate, chunkSize: 10000);
			}

			if (!$cachePrices || $customers) {
				continue;
			}

			$this->getConnection()->rows([$currentIndexTableName])
				->where('product', \array_keys($cachePrices))
				->delete();
		}

		if ($existingPricesCacheTables && (!$customers && !$customerGroups && !$merchants)) {
			foreach ($existingPricesCacheTables as $tableName) {
				$this->getLink()->exec("DROP TABLE IF EXISTS `$tableName`;");
			}
		}

		Debugger::log('diffUpdateVisibilityPriceTable -- cache select time: ' . $cacheSelectTime, $this->logName);
		Debugger::log('diffUpdateVisibilityPriceTable -- main while: ' . Debugger::timer('diffUpdateVisibilityPriceTable -- main while'), $this->logName);
	}

	/**
	 * @param string $relationsCacheTableName
	 * @param string $productsCacheTableName
	 * @param array<string|int, true> $productsInProductsCacheTable
	 */
	protected function diffUpdateRelations(string $relationsCacheTableName, string $productsCacheTableName, array $productsInProductsCacheTable): void
	{
		$link = $this->getLink();

		$link->exec("
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
		$indexQuery = $link->query("
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
				$link->exec("ALTER TABLE `$relationsCacheTableName` DROP INDEX idx_related_code");
				$link->exec("ALTER TABLE `$relationsCacheTableName` ADD UNIQUE INDEX idx_related_code (master, slave, amount, discountPct, masterPct, type)");
			}
		}

		$relations = $this->relatedRepository->many()
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

			if (!isset($productsInProductsCacheTable[$row['master']]) || !isset($productsInProductsCacheTable[$row['slave']])) {
				continue;
			}

			$rowsToInsert[$relation->getPK()] = $row;
		}

		$link->beginTransaction();

		$this->getConnection()->rows([$relationsCacheTableName])->delete();

		if ($rowsToInsert) {
			$this->getConnection()->createRows($relationsCacheTableName, \array_values($rowsToInsert), chunkSize: 1000);
		}

		$link->commit();
	}

	protected function createVisibilityPriceTable(string $pricesCacheTableName): void
	{
		$link = $this->getLink();

		$link->exec("
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
		$link = $this->getLink();

		// pokud tabulka existuje, proved alter na změnu indexu code
		$statement = $link->query("SHOW TABLES LIKE '$productsCacheTableName'");

		if ($statement === false) {
			throw new \Exception('Statement creation failed.');
		}

		$exists = $statement->fetch(\PDO::FETCH_NUM);

		if ($exists) {
			// pokud tabulka existuje, proved alter na změnu indexu code
			// provést jen pokud exituje index
			$statement = $link->query("SHOW INDEX FROM `$productsCacheTableName` WHERE Key_name = 'idx_unique_code'");

			if ($statement === false) {
				throw new \Exception('Statement creation failed.');
			}

			$exists = $statement->fetch(\PDO::FETCH_NUM);

			if ($exists) {
				// pokud existuje, tak ho smazat a přidat nový
				$link->exec("ALTER TABLE `$productsCacheTableName` DROP INDEX idx_unique_code");
				$link->exec("ALTER TABLE `$productsCacheTableName` ADD INDEX idx_code (code)");
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
		$link->exec($query);

		// Check if columns exist before adding them
		$query = $link->query("
			SELECT COLUMN_NAME
			FROM INFORMATION_SCHEMA.COLUMNS 
			WHERE TABLE_SCHEMA = DATABASE() 
			AND TABLE_NAME = '$productsCacheTableName' 
			AND COLUMN_NAME IN ('ribbons', 'internalRibbons', 'published', 'buyCount')
		");

		if ($query === false) {
			throw new \Exception('Statement creation failed.');
		}

		$columns = $query->fetchAll(\PDO::FETCH_ASSOC);
		$columns = \array_combine(\array_column($columns, 'COLUMN_NAME'), \array_column($columns, 'COLUMN_NAME'));

		if (!isset($columns['ribbons'])) {
			$link->exec("ALTER TABLE `$productsCacheTableName` ADD COLUMN `ribbons` TEXT");
		}

		if (!isset($columns['internalRibbons'])) {
			$link->exec("ALTER TABLE `$productsCacheTableName` ADD COLUMN `internalRibbons` TEXT");
		}

		if (!isset($columns['published'])) {
			$link->exec("ALTER TABLE `$productsCacheTableName` ADD COLUMN `published` DATE");
		}

		if (!isset($columns['buyCount'])) {
			$link->exec("ALTER TABLE `$productsCacheTableName` ADD COLUMN `buyCount` INT");
		}

		return;
	}
}
