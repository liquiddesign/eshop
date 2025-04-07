<?php

namespace Eshop\Services\ProductsCache;

use Base\Bridges\AutoWireService;
use Eshop\DB\Customer;
use Eshop\DevelTools;
use Nette\DI\MissingServiceException;
use StORM\DIConnection;
use Tracy\Debugger;
use Tracy\ILogger;

class ProductsCacheDiffUpdateService extends ProductsCacheBaseWarmUpService implements AutoWireService
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
	 */
	public function warmUpCacheTableDiff(array $customers = []): void
	{
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
			Debugger::dump('Prefetch before main table: ' . Debugger::timer() . ', ' . DevelTools::getPeakMemoryUsage());

			$this->createProductsTable($productsCacheTableName, $allCategoryTypes);

			// update products table - compare cache vs live data
			Debugger::timer();
			$productsByCategories = $this->diffUpdateMainTable(
				$productsCacheTableName,
				$allCategoryTypes,
				$allDisplayAmounts,
				$allCategories,
				$allProductPrimaryCategories,
				$productPrimaryCategories,
				$productAttributeValues,
				$productCategories,
			);
			Debugger::dump('diffUpdateMainTable: ' . Debugger::timer() . ', ' . DevelTools::getPeakMemoryUsage());
			$this->diffUpdateRelations($relationsCacheTableName);
			Debugger::dump('diffUpdateRelations: ' . Debugger::timer() . ', ' . DevelTools::getPeakMemoryUsage());

			$this->diffUpdateCategories($categoriesTableName, $productsCacheTableName, $productsByCategories, $allCategories);
			Debugger::dump('createCategoriesTable: ' . Debugger::timer() . ', ' . DevelTools::getPeakMemoryUsage());

			$this->diffUpdateVisibilityPriceTable($visibilityPricesCacheTableName, $customers);
			Debugger::dump('diffUpdateVisibilityPriceTable: ' . Debugger::timer() . ', ' . DevelTools::getPeakMemoryUsage());

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
	 * @param array<string, array<int, array{0: bool}>> $productsByCategories
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
  showInCategory BOOL NOT NULL,
  PRIMARY KEY (product, category),
  CONSTRAINT FOREIGN KEY (product) REFERENCES $productsCacheTableName(product) ON UPDATE CASCADE ON DELETE CASCADE,
  INDEX (category)
);");

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

					if ($categoryInCache->showInCategory === $data[0]) {
						unset($categoriesWithProductsInCache[$categoryId][$product]);

						continue;
					}
				}

				$categoriesToInsert[] = [
					$product,
					$categoryId,
					$data[0],
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
	 * @param array<object{ancestor: string, showDescendantProducts: bool, showProductsInAncestors: bool}> $allCategories
	 * @param array<object{category: string|null, categoryType: string}> $allProductPrimaryCategories
	 * @param array<object{groupedValues: string}> $productPrimaryCategories
	 * @param array<object{groupedValues: string}> $productAttributeValues
	 * @param array<object{groupedValues: string}> $productCategories
	 * @return array<string, array<int, array{0: bool}>>
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

		$productsCollection = $this->productRepository->many()
			->join(['masterProduct' => 'eshop_product'], 'this.fk_masterProduct = masterProduct.uuid')
			->join(['price' => 'eshop_price'], 'this.uuid = price.fk_product', type: 'INNER')
			->join(['eshop_displayamount'], 'this.fk_displayAmount = eshop_displayamount.uuid')
			->join(['eshop_displaydelivery'], 'this.fk_displayDelivery = eshop_displaydelivery.uuid')
			->join(['eshop_producer'], 'this.fk_producer = eshop_producer.uuid')
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
			])
			->setGroupBy(['this.id']);

		$productsInCache = $this->getConnection()
			->rows([$productsCacheTableName])
			->setIndex('product')
			->fetchArray(\stdClass::class);

		$productsToCreate = [];
		$productsToUpdate = [];
		$productsByCategories = [];

		while ($product = $productsCollection->fetch(\stdClass::class)) {
			/** @var \stdClass $product */

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

			// @TODO Add control of Category showDescendantProducts
			if ($categories = ($productCategories[$product->id] ?? null)) {
				$categories = \explode(',', $categories->groupedValues);

				foreach ($categories as $category) {
					$categoryEntity = $allCategories[$category];

					$ancestors = $this->getAncestorsOfCategory($category, $allCategories);

					foreach ($ancestors as $ancestor) {
						$productsByCategories[$ancestor][$product->id] = [$categoryEntity->showProductsInAncestors];
					}

					$productsByCategories[$category][$product->id] = [true];
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

			unset($productsInCache[$product->id]);
		}

		if ($productsToCreate) {
			Debugger::dump('diffUpdateMainTable -- created: ' . $this->getConnection()->createRows($productsCacheTableName, \array_values($productsToCreate), chunkSize: 1000)->getRowCount());
		}

		$updatedCount = 0;

		foreach (\array_chunk($productsToUpdate, 1000, true) as $chunk) {
			$this->getLink()->beginTransaction();

			foreach ($chunk as $product => $row) {
				$updatedCount += $this->getConnection()->rows([$productsCacheTableName])->where('product', $product)->update($row);
			}

			$this->getLink()->commit();
		}

		Debugger::dump('diffUpdateMainTable -- updated: ' . $updatedCount);

		if ($productsInCache) {
			Debugger::dump('diffUpdateMainTable -- deleted: ' . $this->getConnection()->rows([$productsCacheTableName])
					->where('product', \array_keys($productsInCache))
					->delete());
		}

		return $productsByCategories;
	}

	/**
	 * @param array<int> $allVisibilityLists
	 * @param array<int> $allPriceLists
	 * @return array{0: array<mixed>, 1: array<mixed>}
	 */
	protected function getPrefetchedArraysForPriceTable(array $allVisibilityLists, array $allPriceLists): array
	{
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
			])
			->setIndex('product.id')
			->orderBy(['product.id' => 'ASC', 'priceList.priority' => 'ASC']);

		while ($item = $allProductsWithPriceQuery->fetch(\stdClass::class)) {
			/** @var \stdClass $item */

			$allProductsWithPrice[$item->productId][$item->priceListId] = $item;
		}

		$allProductsWithPriceQuery->__destruct();
		unset($allProductsWithPriceQuery);

		return [$allProductsWithVLI, $allProductsWithPrice];
	}

	/**
	 * @param string $pricesCacheTableName
	 * @param array<string|\Eshop\DB\Customer> $customers
	 * @throws \StORM\Exception\GeneralException
	 */
	protected function diffUpdateVisibilityPriceTable(string $pricesCacheTableName, array $customers = []): void
	{
		foreach ($customers as &$customer) {
			if ($customer instanceof Customer) {
				$customer = $customer->getPK();
			}
		}

		Debugger::timer('getAllPossibleVisibilityAndPriceListOptions');
		[$visibilityPriceListsOptions, $allVisibilityLists, $allPriceLists] = $this->getAllPossibleVisibilityAndPriceListOptions($customers);
		Debugger::dump(
			'diffUpdateVisibilityPriceTable -- getAllPossibleVisibilityAndPriceListOptions: ' . Debugger::timer('getAllPossibleVisibilityAndPriceListOptions') .
			', ' . DevelTools::getPeakMemoryUsage()
		);

		Debugger::timer('diffUpdateVisibilityPriceTable -- prefetch');

		[$allProductsWithVLI, $allProductsWithPrice] = $this->getPrefetchedArraysForPriceTable($allVisibilityLists, $allPriceLists);

		Debugger::dump('diffUpdateVisibilityPriceTable -- prefetch: ' . Debugger::timer('diffUpdateVisibilityPriceTable -- prefetch') . ', ' . DevelTools::getPeakMemoryUsage());

		Debugger::timer('diffUpdateVisibilityPriceTable -- main while');

		$cacheSelectTime = 0;

		$existingPricesCacheTables = $this->getConnection()
			->query("SHOW TABLES LIKE 'prices\\_%';")
			->fetchAll(\PDO::FETCH_COLUMN);

		$existingPricesCacheTables = \array_combine($existingPricesCacheTables, $existingPricesCacheTables);

		foreach (\array_keys($visibilityPriceListsOptions) as $index) {
			$currentIndexTableName = "$pricesCacheTableName$index";

			if (!isset($existingPricesCacheTables[$currentIndexTableName])) {
				$this->createVisibilityPriceTable($currentIndexTableName);
			}

			unset($existingPricesCacheTables[$currentIndexTableName]);

			$currentIndexTableName = "`$pricesCacheTableName$index`";

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

		if ($existingPricesCacheTables) {
			foreach ($existingPricesCacheTables as $tableName) {
				$this->getLink()->exec("DROP TABLE IF EXISTS `$tableName`;");
			}
		}

		Debugger::dump('diffUpdateVisibilityPriceTable -- cache select time: ' . $cacheSelectTime);
		Debugger::dump('diffUpdateVisibilityPriceTable -- main while: ' . Debugger::timer('diffUpdateVisibilityPriceTable -- main while'));
	}

	protected function diffUpdateRelations(string $relationsCacheTableName): void
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
    INDEX idx_related_master (master, type),
    INDEX idx_related_slave (slave, type),
    INDEX idx_products_related_unique (master, slave),
    UNIQUE INDEX idx_related_code (master, slave, amount, discountPct, masterPct)
);");

		$relations = $this->relatedRepository->many()
			->join(['type' => 'eshop_relatedtype'], 'this.fk_type = type.uuid')
			->join(['masterProduct' => 'eshop_product'], 'this.fk_master = masterProduct.uuid')
			->join(['slaveProduct' => 'eshop_product'], 'this.fk_slave = slaveProduct.uuid')
			->select([
				'typeId' => 'type.id',
				'masterId' => 'masterProduct.id',
				'slaveId' => 'slaveProduct.id',
			]);

		$relationsInCache = $this->getConnection()
			->rows([$relationsCacheTableName])
			->setIndex('uuid')
			->fetchArray(\stdClass::class);

		$rowsToInsert = [];
		$rowsToUpdate = [];

		foreach ($relations as $relation) {
			$row = [
				'uuid' => $relation->getPK(),
				'master' => $relation->getValue('masterId'),
				'slave' => $relation->getValue('slaveId'),
				'type' => $relation->getValue('typeId'),
				'priority' => $relation->priority,
				'amount' => $relation->amount,
				'hidden' => $relation->hidden,
				'systemic' => $relation->isSystemic(),
				'discountPct' => $relation->discountPct,
				'masterPct' => $relation->masterPct,
			];

			if (isset($relationsInCache[$relation->getPK()])) {
				$diff = \array_diff_assoc($row, (array) $relationsInCache[$relation->getPK()]);

				if ($diff) {
					$rowsToUpdate[$relation->getPK()] = $diff;
				}
			} else {
				$rowsToInsert[] = $row;
			}

			unset($relationsInCache[$relation->getPK()]);
		}

		if ($rowsToInsert) {
			$this->getConnection()->createRows($relationsCacheTableName, $rowsToInsert, chunkSize: 1000);
		}

		if ($rowsToUpdate) {
			foreach (\array_chunk($rowsToUpdate, 1000, true) as $chunk) {
				$this->getLink()->beginTransaction();

				foreach ($chunk as $uuid => $row) {
					$this->getConnection()->rows([$relationsCacheTableName])
						->where('uuid', $uuid)
						->update($row);
				}

				$this->getLink()->commit();
			}
		}

		if (!$relationsInCache) {
			return;
		}

		$this->getConnection()->rows([$relationsCacheTableName])
			->where('uuid', \array_keys($relationsInCache))
			->delete();
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
  UNIQUE INDEX idx_unique_code (code),
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
	}
}
