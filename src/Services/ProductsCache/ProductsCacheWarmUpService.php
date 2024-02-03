<?php

namespace Eshop\Services\ProductsCache;

use Base\Bridges\AutoWireService;
use Base\ShopsConfig;
use Carbon\Carbon;
use Eshop\Admin\ScriptsPresenter;
use Eshop\DB\AttributeRepository;
use Eshop\DB\AttributeValueRepository;
use Eshop\DB\CategoryRepository;
use Eshop\DB\CategoryTypeRepository;
use Eshop\DB\CustomerGroupRepository;
use Eshop\DB\CustomerRepository;
use Eshop\DB\DisplayAmountRepository;
use Eshop\DB\DisplayDeliveryRepository;
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
use Eshop\DevelTools;
use Eshop\Services\SettingsService;
use Eshop\ShopperUser;
use Nette\Caching\Cache;
use Nette\Caching\Storage;
use Nette\DI\Container;
use Nette\Utils\FileSystem;
use StORM\DIConnection;
use Tracy\Debugger;
use Tracy\ILogger;
use Web\DB\SettingRepository;

class ProductsCacheWarmUpService implements AutoWireService
{
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
		readonly Storage $storage,
	) {
		$this->cache = new Cache($storage);
	}

	public function warmUpCacheTable(): void
	{
		$cacheIndexToBeWarmedUp = $this->getCacheIndexToBeWarmedUp();

		if ($cacheIndexToBeWarmedUp === 0) {
			return;
		}

		$this->cleanProductsProviderCache();

		try {
			$link = $this->getLink();
			$link->exec('SET SESSION group_concat_max_len=4294967295');

			$this->markCacheAsWarming($cacheIndexToBeWarmedUp);

			$productsCacheTableName = "eshop_products_cache_$cacheIndexToBeWarmedUp";
			$visibilityPricesCacheTableName = "eshop_products_prices_cache_$cacheIndexToBeWarmedUp";
			$categoriesTableName = "eshop_categories_cache_$cacheIndexToBeWarmedUp";

			// From this point, every section is tracked
			Debugger::timer();

			$this->dropCategoriesTables($cacheIndexToBeWarmedUp, $categoriesTableName);
			Debugger::dump('dropCategoriesTables: ' . Debugger::timer());
			Debugger::dump(DevelTools::getPeakMemoryUsage());

			$this->createVisibilityPriceTable($visibilityPricesCacheTableName);
			Debugger::dump('createVisibilityPriceTable: ' . Debugger::timer());
			Debugger::dump(DevelTools::getPeakMemoryUsage());

			$this->insertVisibilityPriceTable($visibilityPricesCacheTableName);
			Debugger::dump('insertVisibilityPriceTable: ' . Debugger::timer());
			Debugger::dump(DevelTools::getPeakMemoryUsage());

			$this->warmUpRelations($cacheIndexToBeWarmedUp);
			Debugger::dump('warmUpRelations: ' . Debugger::timer());
			Debugger::dump(DevelTools::getPeakMemoryUsage());

			[
				$allCategoryTypes,
				$allDisplayAmounts,
				$allCategories,
				$allProductPrimaryCategories,
				$productPrimaryCategories,
				$productAttributeValues,
				$productCategories,
			] = $this->getPrefetchedArrays();
			Debugger::dump('Prefetch before main table: ' . Debugger::timer());
			Debugger::dump(DevelTools::getPeakMemoryUsage());

			$this->createMainTable($productsCacheTableName, $allCategoryTypes);
			Debugger::dump('createMainTable: ' . Debugger::timer());
			Debugger::dump(DevelTools::getPeakMemoryUsage());

			$productsByCategories = $this->insertMainTable(
				$productsCacheTableName,
				$allCategoryTypes,
				$allDisplayAmounts,
				$allCategories,
				$allProductPrimaryCategories,
				$productPrimaryCategories,
				$productAttributeValues,
				$productCategories,
			);
			Debugger::dump('insertMainTable: ' . Debugger::timer());
			Debugger::dump(DevelTools::getPeakMemoryUsage());

			$this->indexMainTable($productsCacheTableName, $allCategoryTypes);
			Debugger::dump('indexMainTable: ' . Debugger::timer());
			Debugger::dump(DevelTools::getPeakMemoryUsage());

			$this->indexVisibilityPriceTable($visibilityPricesCacheTableName, $productsCacheTableName);
			Debugger::dump('indexVisibilityPriceTable: ' . Debugger::timer());
			Debugger::dump(DevelTools::getPeakMemoryUsage());

			$this->createCategoriesTable($categoriesTableName);
			Debugger::dump('createCategoriesTable: ' . Debugger::timer());
			Debugger::dump(DevelTools::getPeakMemoryUsage());

			$this->insertCategoriesTable($categoriesTableName, $productsByCategories, $allCategories);
			Debugger::dump('insertCategoriesTable: ' . Debugger::timer());
			Debugger::dump(DevelTools::getPeakMemoryUsage());

			$this->indexCategoriesTable($categoriesTableName, $productsCacheTableName);
			Debugger::dump('indexCategoriesTable: ' . Debugger::timer());
			Debugger::dump(DevelTools::getPeakMemoryUsage());

			$this->markCacheAsReady($cacheIndexToBeWarmedUp);
			$this->cleanProductsProviderCache();
		} catch (\Throwable $e) {
			Debugger::log($e, ILogger::EXCEPTION);
			Debugger::dump($e);

			$this->resetHangingStateOfCache($cacheIndexToBeWarmedUp);
		}
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

	protected function insertCategoriesTable(string $categoriesTableName, array $productsByCategories, array $allCategories): void
	{
		$i = 0;
		$categoriesToInsert = [];

		foreach ($productsByCategories as $category => $products) {
			$categoryId = $allCategories[$category]->id;

			foreach (\array_keys($products) as $product) {
				$categoriesToInsert[] = [
					$product,
					$categoryId,
				];

				$i++;
			}

			if ($i <= 10000) {
				continue;
			}

			$this->loadDataInfile($categoriesTableName, $categoriesToInsert);
			$categoriesToInsert = [];
		}

		$this->loadDataInfile($categoriesTableName, $categoriesToInsert);
	}

	protected function createCategoriesTable(string $categoriesTableName): void
	{
		$link = $this->getLink();

		$link->exec("
CREATE TABLE `$categoriesTableName` (
  product BIGINT UNSIGNED NOT NULL,
  category INT UNSIGNED NOT NULL
);");
	}

	/**
	 * @param string $productsCacheTableName
	 * @param array<mixed> $allCategoryTypes
	 * @param array<mixed> $allDisplayAmounts
	 * @param array<mixed> $allCategories
	 * @param array<mixed> $allProductPrimaryCategories
	 * @param array<mixed> $productPrimaryCategories
	 * @param array<mixed> $productAttributeValues
	 * @param array<mixed> $productCategories
	 * @return array<mixed>
	 */
	protected function insertMainTable(
		string $productsCacheTableName,
		array $allCategoryTypes,
		array $allDisplayAmounts,
		array $allCategories,
		array $allProductPrimaryCategories,
		array $productPrimaryCategories,
		array $productAttributeValues,
		array $productCategories,
	): array {
		$mutationSuffix = $this->getMutationSuffix();

		$productsCollection = $this->productRepository->many()
			->join(['price' => 'eshop_price'], 'this.uuid = price.fk_product', type: 'INNER')
			->join(['priceList' => 'eshop_pricelist'], 'price.fk_pricelist = priceList.uuid')
			->join(['discount' => 'eshop_discount'], 'priceList.fk_discount = discount.uuid')
			->join(['eshop_displayamount'], 'this.fk_displayAmount = eshop_displayamount.uuid')
			->join(['eshop_displaydelivery'], 'this.fk_displayDelivery = eshop_displaydelivery.uuid')
			->join(['eshop_producer'], 'this.fk_producer = eshop_producer.uuid')
			->setSelect([
				'id' => 'this.id',
				'fkDisplayAmount' => 'eshop_displayamount.id',
				'fkDisplayDelivery' => 'eshop_displaydelivery.id',
				'fkProducer' => 'eshop_producer.id',
				'name' => "this.name$mutationSuffix",
				'code' => 'this.code',
				'subCode' => 'this.subCode',
				'externalCode' => 'this.externalCode',
				'ean' => 'this.ean',
			])
			->where('priceList.isActive', true)
			->where('(discount.validFrom IS NULL OR discount.validFrom <= DATE(now())) AND (discount.validTo IS NULL OR discount.validTo >= DATE(now()))')
			->setGroupBy(['this.id']);

		$productsByCategories = [];
		$allCategoriesByCategory = [];
		$productsDataToInsert = [];

		$i = 0;
		$first = true;

		while ($product = $productsCollection->fetch(\stdClass::class)) {
			/** @var \stdClass $product */

			if ($first) {
				Debugger::dump('Main select: ' . Debugger::timer());
				Debugger::dump(DevelTools::getPeakMemoryUsage());

				$first = false;
			}

			$productData = [
				$product->id,
				$product->fkProducer ?: '\N',
				$product->fkDisplayAmount ?: '\N',
				$product->fkDisplayDelivery ?: '\N',
				$product->fkDisplayAmount ? ((int) $allDisplayAmounts[$product->fkDisplayAmount]->isSold) : '\N',
					$productAttributeValues[$product->id] ?? null ?: '\N',
				$product->name ?: '\N',
				$product->code ? "\"$product->code\"" : '\N',
				$product->subCode ? "\"$product->subCode\"" : '\N',
				$product->externalCode ? "\"$product->externalCode\"" : '\N',
				$product->ean ? "\"$product->ean\"" : '\N',
			];

			$primaryCategories = isset($productPrimaryCategories[$product->id]) ? \explode(',', $productPrimaryCategories[$product->id]) : [];

			foreach ($primaryCategories as $primaryCategory) {
				$primaryCategory = $allProductPrimaryCategories[$primaryCategory];

				$products[$product->id]["primaryCategory_$primaryCategory->categoryType"] = $primaryCategory->category;
			}

			if (($missingCount = \count($allCategoryTypes) - \count($primaryCategories)) !== 0) {
				for ($j = 0; $j < $missingCount; $j++) {
					$productData[] = null;
				}
			}

			if ($categories = ($productCategories[$product->id] ?? null)) {
				$categories = \explode(',', $categories);

				foreach ($categories as $category) {
					$categoryCategories = $allCategoriesByCategory[$category] ?? null;

					if ($categoryCategories === null) {
						$categoryCategories = $allCategoriesByCategory[$category] = \array_merge($this->getAncestorsOfCategory($category, $allCategories), [$category]);
					}

					$productData['categories'] = \array_unique(\array_merge($productData['categories'] ?? [], $categoryCategories));

					foreach ($productData['categories'] as $productCategory) {
						$productsByCategories[$productCategory][$product->id] = true;
					}
				}
			}

			unset($productData['categories']);

			$productsDataToInsert[] = $productData;

			$i++;

			if ($i !== 10000) {
				continue;
			}

			$i = 0;

			$this->loadDataInfile($productsCacheTableName, $productsDataToInsert);
			$productsDataToInsert = [];
		}

		$this->loadDataInfile($productsCacheTableName, $productsDataToInsert);
		unset($productsDataToInsert);

		$productsCollection->__destruct();

		return $productsByCategories;
	}

	/**
	 * @return array<mixed>
	 */
	protected function getPrefetchedArrays(): array
	{
		$allCategoryTypes = $this->categoryTypeRepository->many()->select(['this.id'])->setOrderBy(['this.id'])->fetchArray(\stdClass::class);

		$allDisplayAmounts = $this->displayAmountRepository->many()->setIndex('id')->fetchArray(\stdClass::class);

		/** @var array<object{id: int, ancestor: string}> $allCategories */
		$allCategories = $this->categoryRepository->many()->setSelect(['this.id', 'ancestor' => 'this.fk_ancestor'], keepIndex: true)->fetchArray(\stdClass::class);

		/** @var array<object{category: string|null, categoryType: string}> $allProductPrimaryCategories */
		$allProductPrimaryCategories = $this->productPrimaryCategoryRepository->many()
			->join(['eshop_categorytype'], 'this.fk_categoryType = eshop_categorytype.uuid')
			->join(['eshop_category'], 'this.fk_category = eshop_category.uuid')
			->setSelect(['category' => 'eshop_category.id', 'categoryType' => 'eshop_categorytype.id'], keepIndex: true)
			->fetchArray(\stdClass::class);

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
			->toArrayOf('groupedValues');

		$productAttributeValues = $this->productRepository->many()
			->join(['assign' => 'eshop_attributeassign'], 'this.uuid = assign.fk_product', type: 'INNER')
			->join(['joinedTable' => 'eshop_attributevalue'], 'assign.fk_value = joinedTable.uuid', type: 'INNER')
			->setSelect([
				'id' => 'this.id',
				'groupedValues' => 'GROUP_CONCAT(DISTINCT joinedTable.id)',
			])
			->setGroupBy(['this.id'])
			->setIndex('id')
			->toArrayOf('groupedValues');

		$productCategories = $this->productRepository->many()
			->join(['joinedTable' => 'eshop_product_nxn_eshop_category'], 'this.uuid = joinedTable.fk_product', type: 'INNER')
			->setSelect([
				'id' => 'this.id',
				'groupedValues' => 'GROUP_CONCAT(DISTINCT joinedTable.fk_category)',
			])
			->setGroupBy(['this.id'])
			->setIndex('id')
			->toArrayOf('groupedValues');

		return [$allCategoryTypes, $allDisplayAmounts, $allCategories, $allProductPrimaryCategories, $productPrimaryCategories, $productAttributeValues, $productCategories];
	}

	protected function indexMainTable(string $productsCacheTableName, array $allCategoryTypes): void
	{
		$link = $this->getLink();

		$link->exec("CREATE INDEX idx_producer ON `$productsCacheTableName` (producer);");
		$link->exec("CREATE INDEX idx_displayAmount ON `$productsCacheTableName` (displayAmount);");
		$link->exec("CREATE INDEX idx_displayDelivery ON `$productsCacheTableName` (displayDelivery);");
		$link->exec("CREATE INDEX idx_displayAmount_isSold ON `$productsCacheTableName` (displayAmount_isSold);");
		$link->exec("CREATE INDEX idx_subCode ON `$productsCacheTableName` (subCode);");
		$link->exec("CREATE INDEX idx_externalCode ON `$productsCacheTableName` (externalCode);");
		$link->exec("CREATE FULLTEXT INDEX idx_name ON `$productsCacheTableName` (name);");
		$link->exec("CREATE UNIQUE INDEX idx_unique_code ON `$productsCacheTableName` (code);");
		$link->exec("CREATE UNIQUE INDEX idx_unique_ean ON `$productsCacheTableName` (ean);");

		foreach ($allCategoryTypes as $categoryType) {
			$link->exec("ALTER TABLE `$productsCacheTableName` ADD INDEX idx_primaryCategory_{$categoryType->id} (primaryCategory_{$categoryType->id});");
		}
	}

	protected function insertVisibilityPriceTable(string $pricesCacheTableName): void
	{
		Debugger::timer('getAllPossibleVisibilityAndPriceListOptions');
		[$visibilityPriceListsOptions, $allVisibilityLists, $allPriceLists] = $this->getAllPossibleVisibilityAndPriceListOptions();
		Debugger::dump('insertVisibilityPriceTable -- getAllPossibleVisibilityAndPriceListOptions: ' . Debugger::timer('getAllPossibleVisibilityAndPriceListOptions'));
		Debugger::dump(DevelTools::getPeakMemoryUsage());

		Debugger::timer('insertVisibilityPriceTable -- prefetch');

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

		Debugger::dump('insertVisibilityPriceTable -- prefetch: ' . Debugger::timer('insertVisibilityPriceTable -- prefetch'));
		Debugger::dump(DevelTools::getPeakMemoryUsage());

		$loadDataTime = 0;

		Debugger::timer('insertVisibilityPriceTable -- main while');

		foreach (\array_keys($visibilityPriceListsOptions) as $index) {
			$explodedIndex = \explode('-', $index);

			if (\count($explodedIndex) !== 2) {
				continue;
			}

			[$visibilityListsString, $priceListsString] = $explodedIndex;
			/** @var array<int> $visibilityLists */
			$visibilityLists = \explode(',', $visibilityListsString);
			/** @var array<int> $priceLists */
			$priceLists = \explode(',', $priceListsString);

			$mapToInsert = [];
			$i = 0;

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

						$price = $priceItems[$priceListId];

						if (!$this->shopperUser->getShowZeroPrices() && !$price->price > 0) {
							continue;
						}

						$mapToInsert[] = [
							$index,
							$product,
							$price->price,
							$price->priceVat,
							$price->priceBefore ?: '\N',
							$price->priceVatBefore ?: '\N',
							$priceListId,
							$visibilityListItem->hidden,
							$visibilityListItem->hiddenInMenu,
							$visibilityListItem->priority,
							$visibilityListItem->unavailable,
							$visibilityListItem->recommended,
						];

						break 2;
					}
				}

				$i++;

				if ($i !== 10000) {
					continue;
				}

				Debugger::timer('loadData');
				$this->loadDataInfile($pricesCacheTableName, $mapToInsert);
				$loadDataTime += Debugger::timer('loadData');

				$mapToInsert = [];
				$i = 0;
			}

			Debugger::timer('loadData');
			$this->loadDataInfile($pricesCacheTableName, $mapToInsert);
			$loadDataTime += Debugger::timer('loadData');
		}

		Debugger::dump('insertVisibilityPriceTable -- main while: ' . Debugger::timer('insertVisibilityPriceTable -- main while'));

		Debugger::dump('insertVisibilityPriceTable -- load data time: ' . $loadDataTime);
	}

	protected function indexVisibilityPriceTable(string $pricesCacheTableName, string $productsCacheTableName): void
	{
		$link = $this->getLink();

		Debugger::timer('indexVisibilityPriceTable -- PRIMARY');
		$link->exec("ALTER TABLE `$pricesCacheTableName` ADD PRIMARY KEY (visibilityPriceIndex, product);");
		Debugger::dump('indexVisibilityPriceTable -- PRIMARY: ' . Debugger::timer('indexVisibilityPriceTable -- PRIMARY'));

		Debugger::timer('indexVisibilityPriceTable -- product');
		$link->exec("ALTER TABLE $pricesCacheTableName ADD CONSTRAINT FOREIGN KEY (product) REFERENCES $productsCacheTableName(product) ON UPDATE CASCADE ON DELETE CASCADE ");
		Debugger::dump('indexVisibilityPriceTable -- product: ' . Debugger::timer('indexVisibilityPriceTable -- product'));

//		Debugger::timer('indexVisibilityPriceTable -- idx_price');
//		$link->exec("CREATE INDEX idx_price ON `$pricesCacheTableName` (price);");
//		Debugger::dump('indexVisibilityPriceTable -- idx_price: ' . Debugger::timer('indexVisibilityPriceTable -- idx_price'));
	}

	protected function indexCategoriesTable(string $tableName, string $productsCacheTableName): void
	{
		$link = $this->getLink();

		$link->exec("ALTER TABLE `$tableName` ADD PRIMARY KEY (product, category);");
		$link->exec("ALTER TABLE $tableName ADD CONSTRAINT FOREIGN KEY (product) REFERENCES $productsCacheTableName(product) ON UPDATE CASCADE ON DELETE CASCADE ");
		$link->exec("ALTER TABLE `$tableName` ADD INDEX (category);");
	}

	protected function loadDataInfile(string $tableName, array $data, int $chunkSize = 10000): void
	{
//		Debugger::timer('loadDataInfile');
		$tmpFileName = \tempnam($this->container->getParameter('tempDir'), 'csv');

		$buffer = \fopen('php://memory', 'rw');
		$file = \fopen($tmpFileName, 'w');

		$i = 0;

		foreach ($data as $row) {
			\fputcsv($buffer, $row);

			$i++;

			if ($i !== $chunkSize) {
				continue;
			}

			\rewind($buffer);
			$csv = \stream_get_contents($buffer);
			\fclose($buffer);
			$buffer = \fopen('php://memory', 'rw');

			\fwrite($file, $csv);
			unset($csv);

			$i = 0;
		}

		\rewind($buffer);
		$csv = \stream_get_contents($buffer);
		\fclose($buffer);

		\fwrite($file, $csv);
		unset($csv);

		\fclose($file);

//		Debugger::dump('Insert to CSV: ' . Debugger::timer('loadDataInfile'));

		$tmpFileName = \str_replace('\\', '\\\\', $tmpFileName);

		$this->getLink()->exec("LOAD DATA LOCAL INFILE \"$tmpFileName\"
			INTO TABLE $tableName
			fields terminated by ','
			optionally enclosed by '\"'
			escaped by \"\\\\\";");

		FileSystem::delete($tmpFileName);

//		Debugger::dump('Insert to DB: ' . Debugger::timer('loadDataInfile'));
	}

	protected function getLink(): \PDO
	{
		if ($this->link !== false) {
			return $this->link;
		}

		return $this->link = $this->connection->getLink();
	}

	protected function getDbName(): string
	{
		if ($this->dbName !== false) {
			return $this->dbName;
		}

		return $this->dbName = $this->connection->getDatabaseName();
	}

	protected function getMutationSuffix(): string
	{
		if ($this->mutationSuffix !== false) {
			return $this->mutationSuffix;
		}

		return $this->mutationSuffix = $this->connection->getMutationSuffix();
	}

	protected function resetHangingStateOfCache(int $id): void
	{
		$this->productsCacheStateRepository->many()->where('this.uuid', $id)->update(['state' => 'empty']);
	}

	protected function markCacheAsWarming(int $id): void
	{
		$this->productsCacheStateRepository->many()->where('this.uuid', $id)->update(['state' => 'warming']);
	}

	protected function markCacheAsReady(int $id): void
	{
		$this->productsCacheStateRepository->many()->where('this.uuid', $id)->update([
			'state' => 'ready',
			'lastWarmUpTs' => null,
			'lastReadyTs' => Carbon::now()->toDateTimeString(),
		]);

		$this->productsCacheStateRepository->many()->where('this.uuid', $id === 1 ? 2 : 1)->update([
			'state' => 'empty',
			'lastWarmUpTs' => null,
			'lastReadyTs' => null,
		]);

		$this->cleanAppCache();
	}

	/**
	 * @return int<0, 2>
	 * @throws \StORM\Exception\NotFoundException
	 */
	protected function getCacheIndexToBeWarmedUp(): int
	{
		$cache1State = $this->productsCacheStateRepository->one('1');
		$cache2State = $this->productsCacheStateRepository->one('2');

		if (!$cache1State?->state || !$cache2State?->state) {
			return 0;
		}

		if ($cache1State->state === 'warming' && $cache1State->lastWarmUpTs && Carbon::now()->diffInMinutes(Carbon::parse($cache1State->lastWarmUpTs)) > 15) {
			$cache1State->state = 'empty';
			$cache1State->lastWarmUpTs = Carbon::now()->toDateTimeString();

			$cache1State->updateAll(['state', 'lastWarmUpTs']);
		}

		if ($cache2State->state === 'warming' && $cache2State->lastWarmUpTs && Carbon::now()->diffInMinutes(Carbon::parse($cache2State->lastWarmUpTs)) > 15) {
			$cache2State->state = 'empty';
			$cache2State->lastWarmUpTs = Carbon::now()->toDateTimeString();

			$cache2State->updateAll(['state', 'lastWarmUpTs']);
		}

		$cache1StateIndex = match ($cache1State->state) {
			'empty' => 0,
			'warming' => 1,
			'ready' => 2,
		};

		$cache2StateIndex = match ($cache2State->state) {
			'empty' => 0,
			'warming' => 1,
			'ready' => 2,
		};

		$logicTable = [
			'00' => 1,
			'01' => 0,
			'02' => 1,
			'10' => 0,
			'11' => 0,
			'12' => 0,
			'20' => 2,
			'21' => 0,
			'22' => 1,
		];

		$index = $logicTable[$cache1StateIndex . $cache2StateIndex];

		if ($index > 0) {
			($index === 1 ? $cache1State : $cache2State)->update(['lastWarmUpTs' => Carbon::now()->toDateTimeString()]);
		}

		return $index;
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

	protected function warmUpRelations(int $cacheIndexToBeWarmedUp): void
	{
		$link = $this->getLink();
		$relationsCacheTableName = "eshop_products_relations_cache_$cacheIndexToBeWarmedUp";

		$link->exec("DROP TABLE IF EXISTS `$relationsCacheTableName`");

		$link->exec("
CREATE TABLE `$relationsCacheTableName` (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    master BIGINT UNSIGNED NOT NULL,
    slave BIGINT UNSIGNED NOT NULL,
    priority SMALLINT NOT NULL,
    amount SMALLINT NOT NULL,
    hidden BOOL NOT NULL,
    systemic BOOL NOT NULL,
    discountPct DOUBLE,
    masterPct DOUBLE,
    type INT UNSIGNED NOT NULL
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

		$rowsToInsert = [];
		$chunkCounter = 0;

		foreach ($relations as $relation) {
			$rowsToInsert[] = [
				'master' => $relation->getValue('masterId'),
				'slave' => $relation->getValue('slaveId'),
				'type' => $relation->getValue('typeId'),
				'priority' => $relation->priority,
				'amount' => $relation->amount,
				'hidden' => $relation->hidden,
				'systemic' => $relation->systemic,
				'discountPct' => $relation->discountPct,
				'masterPct' => $relation->masterPct,
			];

			$chunkCounter++;

			if ($chunkCounter !== 1000) {
				continue;
			}

			$chunkCounter = 0;

			$this->connection->createRows($relationsCacheTableName, $rowsToInsert, chunkSize: 1000);
			$rowsToInsert = [];
		}

		$this->connection->createRows($relationsCacheTableName, $rowsToInsert, chunkSize: 1000);
		unset($rowsToInsert);

//		$link->exec("CREATE INDEX idx_master ON `$relationsCacheTableName` (master);");
//		$link->exec("CREATE INDEX idx_slave ON `$relationsCacheTableName` (slave);");
//		$link->exec("CREATE INDEX idx_type ON `$relationsCacheTableName` (type);");
		$link->exec("CREATE INDEX idx_related_master ON `$relationsCacheTableName` (master, type);");
		$link->exec("CREATE INDEX idx_related_slave ON `$relationsCacheTableName` (slave, type);");
		$link->exec("CREATE INDEX idx_products_related_unique ON `$relationsCacheTableName` (master, slave);");
		$link->exec("CREATE UNIQUE INDEX idx_related_code ON `$relationsCacheTableName` (master, slave, amount, discountPct, masterPct);");
	}

	protected function dropCategoriesTables(int $cacheIndexToBeWarmedUp, string $categoriesTableName): void
	{
		$dbName = $this->getDbName();

		// Currently is used ony one table
		$this->getLink()->exec("DROP TABLE IF EXISTS `$categoriesTableName`");

		// Drop old tables to clean DB
		$categoryTablesInDb = $this->getLink()->query("SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES
                  WHERE TABLE_NAME LIKE 'eshop_categories_cache_{$cacheIndexToBeWarmedUp}_%' AND TABLE_SCHEMA = '$dbName';")
			->fetchAll(\PDO::FETCH_COLUMN);

		foreach ($categoryTablesInDb as $categoryTableName) {
			$this->getLink()->exec("DROP TABLE `$categoryTableName`");
		}

		$categoryTablesInDb = $this->getLink()->query("SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES
                  WHERE TABLE_NAME LIKE 'eshop_categoryproducts_cache_{$cacheIndexToBeWarmedUp}_%' AND TABLE_SCHEMA = '$dbName';")
			->fetchAll(\PDO::FETCH_COLUMN);

		foreach ($categoryTablesInDb as $categoryTableName) {
			$this->getLink()->exec("DROP TABLE `$categoryTableName`");
		}
	}

	/**
	 * @return array{0: array<string, true>, 1: array<int>, 2: array<int>}
	 */
	protected function getAllPossibleVisibilityAndPriceListOptions(): array
	{
		$existingOptions = [];
		$allVisibilityLists = [];
		$allPriceLists = [];
		$customerGroupsQuery = $this->customerGroupRepository->many();

		// Only customer groups marked as defaultUnregisteredGroup are used
		if ($unregisteredGroups = $this->settingsService->getAllDefaultUnregisteredGroups()) {
			$customerGroupsQuery->where('this.uuid', $unregisteredGroups);
		} else {
			$customerGroupsQuery->where('this.uuid', CustomerGroupRepository::UNREGISTERED_PK);
		}

		foreach ($customerGroupsQuery as $customerGroup) {
			$visibilityLists = $customerGroup->getDefaultVisibilityLists()->where('hidden', false)->setSelect(['id'])->setOrderBy(['priority'])->toArrayOf('id', toArrayValues: true);
			$priceLists = $customerGroup->getDefaultPricelists()->where('isActive', true)->setSelect(['id'])->setOrderBy(['priority'])->toArrayOf('id', toArrayValues: true);

			foreach ($visibilityLists as $visibilityList) {
				$allVisibilityLists[$visibilityList] = true;
			}

			foreach ($priceLists as $priceList) {
				$allPriceLists[$priceList] = true;
			}

			$index =
				\implode(',', $visibilityLists) .
				'-' .
				\implode(',', $priceLists);

			$existingOptions[$index] = true;
		}

		foreach (['eshop_customer_nxn_eshop_pricelist', 'eshop_customer_nxn_eshop_pricelist_favourite'] as $table) {
			$customersQuery = $this->customerRepository->many()
				->join(['customerXpriceList' => $table], 'this.uuid = customerXpriceList.fk_customer')
				->join(['priceList' => 'eshop_pricelist'], 'customerXpriceList.fk_pricelist = priceList.uuid')
				->join(['customerXvisibilityList' => 'eshop_customer_nxn_eshop_visibilitylist'], 'this.uuid = customerXvisibilityList.fk_customer')
				->join(['visibilityList' => 'eshop_visibilitylist'], 'customerXvisibilityList.fk_visibilitylist = visibilityList.uuid')
				->setSelect([
					'visibilityPriceIndex' => 'DISTINCT(CONCAT(
					GROUP_CONCAT(DISTINCT visibilityList.id ORDER BY visibilityList.priority),
					"-",
					GROUP_CONCAT(DISTINCT priceList.id ORDER BY priceList.priority)
				))',
				])
				->setGroupBy(['this.uuid']);

			$indexes = $customersQuery->toArrayOf('visibilityPriceIndex');

			foreach ($indexes as $index) {
				if (!$index) {
					continue;
				}

				$exploded = \explode('-', $index);

				if (\count($exploded) === 2) {
					foreach (\explode(',', $exploded[0]) as $visibilityList) {
						$allVisibilityLists[$visibilityList] = true;
					}

					foreach (\explode(',', $exploded[1]) as $priceList) {
						$allPriceLists[$priceList] = true;
					}
				}

				$existingOptions[$index] = true;
			}
		}

		return [$existingOptions, \array_keys($allVisibilityLists), \array_keys($allPriceLists)];
	}

	protected function createVisibilityPriceTable(string $pricesCacheTableName): void
	{
		$link = $this->getLink();

		$link->exec("
DROP TABLE IF EXISTS `$pricesCacheTableName`;
CREATE TABLE `$pricesCacheTableName` (
  visibilityPriceIndex VARCHAR(255) NOT NULL,
  product BIGINT UNSIGNED NOT NULL,
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

	protected function createMainTable(string $productsCacheTableName, array $allCategoryTypes): void
	{
		$link = $this->getLink();

		$link->exec("
DROP TABLE IF EXISTS `$productsCacheTableName`;
CREATE TABLE `$productsCacheTableName` (
  product BIGINT UNSIGNED PRIMARY KEY,
  producer INT UNSIGNED,
  displayAmount INT UNSIGNED,
  displayDelivery INT UNSIGNED,
  displayAmount_isSold BOOL,
  attributeValues TEXT,
  name VARCHAR(255),
  code VARCHAR(255),
  subCode VARCHAR(255),
  externalCode VARCHAR(255),
  ean VARCHAR(255)
);");

		foreach ($allCategoryTypes as $categoryType) {
			$link->exec("ALTER TABLE $productsCacheTableName ADD primaryCategory_{$categoryType->id} INT UNSIGNED;");
		}
	}
}
