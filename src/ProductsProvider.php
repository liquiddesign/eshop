<?php

namespace Eshop;

use Base\ShopsConfig;
use Carbon\Carbon;
use Eshop\Admin\SettingsPresenter;
use Eshop\DB\AttributeRepository;
use Eshop\DB\AttributeValueRepository;
use Eshop\DB\CategoryRepository;
use Eshop\DB\CategoryTypeRepository;
use Eshop\DB\DisplayAmountRepository;
use Eshop\DB\DisplayDeliveryRepository;
use Eshop\DB\PricelistRepository;
use Eshop\DB\PriceRepository;
use Eshop\DB\ProducerRepository;
use Eshop\DB\ProductRepository;
use Eshop\DB\ProductsCacheStateRepository;
use Eshop\DB\VisibilityListItemRepository;
use Eshop\DB\VisibilityListRepository;
use Nette\Caching\Cache;
use Nette\Caching\Storage;
use Nette\DI\Container;
use Nette\Utils\Arrays;
use Nette\Utils\Strings;
use StORM\DIConnection;
use StORM\ICollection;
use Tracy\Debugger;
use Tracy\ILogger;
use Web\DB\SettingRepository;

class ProductsProvider
{
	public const PRODUCTS_PROVIDER_CACHE_TAG = 'productsProviderCache';

	/**
	 * Also hard-coded: category, pricelist
	 * @var array<string>
	 */
	protected array $allowedCollectionFilterColumns = [
		'hidden' => 'visibilityList.hidden',
		'hiddenInMenu' => 'visibilityList.hiddenInMenu',
		'priority' => 'visibilityList.priority',
		'recommended' => 'visibilityList.recommended',
		'unavailable' => 'visibilityList.unavailable',
		'name' => 'name',
		'isSold' => 'displayAmount_isSold',
	];

	/**
	 * @var array<callable(\StORM\ICollection<\stdClass> $productsCollection, mixed $value, array<\Eshop\DB\VisibilityList> $visibilityLists, array<\Eshop\DB\Pricelist> $priceLists): void>
	 */
	protected array $allowedCollectionFilterExpressions = [];

	/**
	 * @var array<string>
	 */
	protected array $allowedDynamicFilterColumns = [
		'systemicAttributes.producer' => 'producer',
		'systemicAttributes.availability' => 'displayAmount',
		'systemicAttributes.delivery' => 'displayDelivery',
	];

	/**
	 * @var array<callable(\stdClass $product, mixed $value, array<\Eshop\DB\VisibilityList> $visibilityLists, array<\Eshop\DB\Pricelist> $priceLists): bool>
	 */
	protected array $allowedDynamicFilterExpressions = [];

	/**
	 * @var array<string>
	 */
	protected array $allowedCollectionOrderColumns = [
		'priority' => 'visibilityList.priority',
		'price' => 'priceList.price',
		'name' => 'name',
	];

	/**
	 * @var array<callable(\StORM\ICollection<\stdClass> $productsCollection, 'ASC'|'DESC' $direction, array<\Eshop\DB\VisibilityList> $visibilityLists, array<\Eshop\DB\Pricelist>): void>
	 */
	protected array $allowedCollectionOrderExpressions = [];

	/**
	 * @var array<mixed>
	 */
	protected array $dataCache = [];

	protected Cache $cache;

	public function __construct(
		protected readonly ProductRepository $productRepository,
		protected readonly CategoryRepository $categoryRepository,
		protected readonly PriceRepository $priceRepository,
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
		readonly Storage $storage,
	) {
		$this->cache = new Cache($storage);

		$this->allowedCollectionOrderExpressions['availabilityAndPrice'] = function (ICollection $productsCollection, string $direction, array $visibilityLists, array $priceLists): void {
			$productsCollection->orderBy([
				'case COALESCE(displayAmount_isSold, 2)
					 when 0 then 0
					 when 2 then 1
					 when 1 then 2
					 else 2 end' => $direction,
				$this->createCoalesceFromArray($priceLists, 'priceList', 'price') => $direction,
			]);
		};

		$this->allowedCollectionOrderExpressions['priorityAvailabilityPrice'] = function (ICollection $productsCollection, string $direction, array $visibilityLists, array $priceLists): void {
			$productsCollection->orderBy([
				$this->createCoalesceFromArray($visibilityLists, 'visibilityList', 'priority') => $direction,
				'case COALESCE(displayAmount_isSold, 2)
                     when 0 then 0
                     when 1 then 1
                     when 2 then 2
                     else 2 end' => $direction,
				$this->createCoalesceFromArray($priceLists, 'priceList', 'price') => $direction,
			]);
		};

		$this->allowedCollectionFilterExpressions['query2'] = function (ICollection $productsCollection, string $query, array $visibilityLists, array $priceLists): void {
			$orConditions = [
				'IF(this.subCode, CONCAT(this.code, this.subCode), this.code) LIKE :qlikeq',
				'this.externalCode LIKE :qlike',
				'this.ean LIKE :qlike',
				'this.name LIKE :qlike COLLATE utf8_general_ci',
				'this.name LIKE :qlikeq COLLATE utf8_general_ci',
				'MATCH(this.name) AGAINST (:q)',
			];

			$productsCollection->where(\implode(' OR ', $orConditions), [
				'q' => $query,
				'qlike' => $query . '%',
				'qlikeq' => '%' . $query . '%',
			]);
		};

		$this->allowedCollectionOrderExpressions['query2'] = function (ICollection $productsCollection, string $query, array $visibilityLists, array $priceLists): void {
			$productsCollection->orderBy([
				'this.name LIKE :qlike' => 'DESC',
				'this.name LIKE :qlikeq' => 'DESC',
				'this.code LIKE :qlike' => 'DESC',
				'this.ean LIKE :qlike' => 'DESC',
			]);
		};

		/**
		 * @param \StORM\ICollection $productsCollection
		 * @param array<string> $uuids
		 * @param array<\Eshop\DB\VisibilityList> $visibilityLists
		 * @param array<\Eshop\DB\Pricelist> $priceLists
		 * @return void
		 */
		$this->allowedCollectionFilterExpressions['uuids'] = function (ICollection $productsCollection, array $uuids, array $visibilityLists, array $priceLists): void {
			$productArray = $this->productRepository->many()->where('this.uuid', $uuids)->setSelect(['this.id'])->toArrayOf('id', toArrayValues: true);

			$productsCollection->where('this.product', $productArray);
		};

		$this->allowedCollectionFilterExpressions['producer'] = function (ICollection $productsCollection, string $producer, array $visibilityLists, array $priceLists): void {
			$producerArray = $this->producerRepository->many()->where('this.uuid', $producer)->setSelect(['this.id'])->toArrayOf('id', toArrayValues: true);

			$productsCollection->where('this.producer', $producerArray);
		};

		/**
		 * @param \StORM\ICollection $productsCollection
		 * @param string|array<string> $producer
		 * @param array<\Eshop\DB\VisibilityList> $visibilityLists
		 * @param array<\Eshop\DB\Pricelist> $priceLists
		 */
		$this->allowedCollectionFilterExpressions['producers'] = function (ICollection $productsCollection, array $producer, array $visibilityLists, array $priceLists): void {
			$producerArray = $this->producerRepository->many()->where('this.uuid', $producer)->setSelect(['this.id'])->toArrayOf('id', toArrayValues: true);

			$productsCollection->where('this.producer', $producerArray);
		};

		$this->allowedDynamicFilterExpressions['priceFrom'] = function (\stdClass $product, mixed $value, array $visibilityLists, array $priceLists): bool {
			$showVat = $this->shopperUser->getMainPriceType() === 'withVat';

			return $showVat ? $product->priceVat >= $value : $product->price >= $value;
		};

		$this->allowedDynamicFilterExpressions['priceTo'] = function (\stdClass $product, mixed $value, array $visibilityLists, array $priceLists): bool {
			$showVat = $this->shopperUser->getMainPriceType() === 'withVat';

			return $showVat ? $product->priceVat <= $value : $product->price <= $value;
		};
	}

	public function addAllowedCollectionFilterColumn(string $name, string $column): void
	{
		$this->allowedCollectionFilterColumns[$name] = $column;
	}

	public function addFilterCollectionExpression(string $name, callable $callback): void
	{
		$this->allowedCollectionFilterExpressions[$name] = $callback;
	}

	public function addAllowedDynamicFilterColumn(string $name, string $column): void
	{
		$this->allowedDynamicFilterColumns[$name] = $column;
	}

	public function addFilterDynamicExpression(string $name, callable $callback): void
	{
		$this->allowedDynamicFilterExpressions[$name] = $callback;
	}

	public function addAllowedCollectionOrderColumn(string $name, string $column): void
	{
		$this->allowedCollectionOrderColumns[$name] = $column;
	}

	public function addCollectionOrderExpression(string $name, callable $callback): void
	{
		$this->allowedCollectionOrderExpressions[$name] = $callback;
	}

	public function warmUpCacheTable(): void
	{
		$cacheIndexToBeWarmedUp = $this->getCacheIndexToBeWarmedUp();

		if ($cacheIndexToBeWarmedUp === 0) {
			return;
		}

		$this->cleanCache();

		try {
			$link = $this->connection->getLink();
			$mutationSuffix = $this->connection->getMutationSuffix();

			$this->markCacheAsWarming($cacheIndexToBeWarmedUp);

			/** @var array<object{id: int, ancestor: string}> $allCategories */
			$allCategories = $this->categoryRepository->many()->setSelect(['this.id', 'ancestor' => 'this.fk_ancestor'], keepIndex: true)->fetchArray(\stdClass::class);

			Debugger::timer();

			foreach ($allCategories as $category) {
				$categoryTableExists = $link->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_NAME = 'eshop_categoryproducts_cache_{$cacheIndexToBeWarmedUp}_$category->id';")
					->fetchColumn();

				if ($categoryTableExists <= 0) {
					continue;
				}

				$link->exec("DROP TABLE IF EXISTS `eshop_categoryproducts_cache_{$cacheIndexToBeWarmedUp}_$category->id`");
			}

			$productsCacheTableName = "eshop_products_cache_$cacheIndexToBeWarmedUp";

			$link->exec("
DROP TABLE IF EXISTS `$productsCacheTableName`;
CREATE TABLE `$productsCacheTableName` (
  product INT UNSIGNED PRIMARY KEY,
  producer INT UNSIGNED,
  displayAmount INT UNSIGNED,
  displayDelivery INT UNSIGNED,
  displayAmount_isSold TINYINT(1),
  attributeValues TEXT,
  name TEXT,
  code TEXT,
  subCode TEXT,
  externalCode TEXT,
  ean TEXT,
  INDEX idx_producer (producer),
  INDEX idx_displayAmount (displayAmount),
  INDEX idx_displayDelivery (displayDelivery),
  INDEX idx_displayAmount_isSold (displayAmount_isSold),
  INDEX idx_subCode (subCode),
  INDEX idx_externalCode (externalCode),
  FULLTEXT INDEX idx_name (name)
);");

			$link->exec("CREATE UNIQUE INDEX idx_unique_code ON `$productsCacheTableName` (code);");
			$link->exec("CREATE UNIQUE INDEX idx_unique_ean ON `$productsCacheTableName` (ean);");

			$allVisibilityLists = $this->visibilityListRepository->many()->select(['this.id'])->fetchArray(\stdClass::class);

			foreach ($allVisibilityLists as $visibilityList) {
				$link->exec("ALTER TABLE `$productsCacheTableName` ADD COLUMN visibilityList_{$visibilityList->id} INT UNSIGNED DEFAULT('{$visibilityList->id}');");
				$link->exec("ALTER TABLE `$productsCacheTableName` ADD INDEX idx_visibilityList_{$visibilityList->id} (visibilityList_{$visibilityList->id});");

				$link->exec("ALTER TABLE `$productsCacheTableName` ADD COLUMN visibilityList_{$visibilityList->id}_hidden TINYINT;");
				$link->exec("ALTER TABLE `$productsCacheTableName` ADD COLUMN visibilityList_{$visibilityList->id}_hiddenInMenu TINYINT;");
				$link->exec("ALTER TABLE `$productsCacheTableName` ADD COLUMN visibilityList_{$visibilityList->id}_priority SMALLINT;");
				$link->exec("ALTER TABLE `$productsCacheTableName` ADD COLUMN visibilityList_{$visibilityList->id}_unavailable TINYINT;");
				$link->exec("ALTER TABLE `$productsCacheTableName` ADD COLUMN visibilityList_{$visibilityList->id}_recommended TINYINT;");
			}

			$allPriceLists = $this->pricelistRepository->many()->select(['this.id'])->fetchArray(\stdClass::class);

			foreach ($allPriceLists as $priceList) {
				$link->exec("ALTER TABLE `$productsCacheTableName` ADD COLUMN priceList_{$priceList->id} INT UNSIGNED DEFAULT('{$priceList->id}');");

				$link->exec("ALTER TABLE `$productsCacheTableName` ADD COLUMN priceList_{$priceList->id}_price DOUBLE;");
				$link->exec("ALTER TABLE `$productsCacheTableName` ADD COLUMN priceList_{$priceList->id}_priceVat DOUBLE;");
				$link->exec("ALTER TABLE `$productsCacheTableName` ADD COLUMN priceList_{$priceList->id}_priceBefore DOUBLE;");
				$link->exec("ALTER TABLE `$productsCacheTableName` ADD COLUMN priceList_{$priceList->id}_priceVatBefore DOUBLE;");
			}

			$allPrices = $this->priceRepository->many()
				->join(['pricelist' => 'eshop_pricelist'], 'this.fk_pricelist = pricelist.uuid')
				->setSelect([
					'this.price',
					'this.priceVat',
					'this.priceBefore',
					'this.priceVatBefore',
					'priceListId' => 'pricelist.id',
				], keepIndex: true)->fetchArray(\stdClass::class);

			$allVisibilityListItems = $this->visibilityListItemRepository->many()
				->join(['visibilityList' => 'eshop_visibilitylist'], 'this.fk_visibilityList = visibilityList.uuid')
				->setSelect([
					'this.hidden',
					'this.hiddenInMenu',
					'this.priority',
					'this.unavailable',
					'this.recommended',
					'visibilityListId' => 'visibilityList.id',
				], keepIndex: true)->fetchArray(\stdClass::class);

			$allDisplayAmounts = $this->displayAmountRepository->many()->setIndex('id')->fetchArray(\stdClass::class);

			$allCategoriesByCategory = [];

			$this->connection->getLink()->exec('SET SESSION group_concat_max_len=4294967295');

			$productsCollection = $this->productRepository->many()
			->join(['price' => 'eshop_price'], 'this.uuid = price.fk_product', type: 'INNER')
			->join(['priceList' => 'eshop_pricelist'], 'price.fk_pricelist = priceList.uuid')
			->join(['discount' => 'eshop_discount'], 'priceList.fk_discount = discount.uuid')
			->join(['eshop_product_nxn_eshop_category'], 'this.uuid = eshop_product_nxn_eshop_category.fk_product')
			->join(['visibilityListItem' => 'eshop_visibilitylistitem'], 'visibilityListItem.fk_product = this.uuid', type: 'INNER')
			->join(['visibilityList' => 'eshop_visibilitylist'], 'visibilityListItem.fk_visibilityList = visibilityList.uuid')
			->join(['assign' => 'eshop_attributeassign'], 'this.uuid = assign.fk_product')
			->join(['eshop_displayamount'], 'this.fk_displayAmount = eshop_displayamount.uuid')
			->join(['eshop_displaydelivery'], 'this.fk_displayDelivery = eshop_displaydelivery.uuid')
			->join(['eshop_producer'], 'this.fk_producer = eshop_producer.uuid')
			->join(['eshop_attributevalue'], 'assign.fk_value = eshop_attributevalue.uuid')
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
				'pricesPKs' => 'GROUP_CONCAT(DISTINCT price.uuid ORDER BY priceList.priority)',
				'categoriesPKs' => 'GROUP_CONCAT(DISTINCT eshop_product_nxn_eshop_category.fk_category)',
				'visibilityListItemsPKs' => 'GROUP_CONCAT(DISTINCT visibilityListItem.uuid ORDER BY visibilityList.priority)',
				'attributeValuesPKs' => 'GROUP_CONCAT(DISTINCT eshop_attributevalue.id)',
			])
			->where('priceList.isActive', true)
			->where('(discount.validFrom IS NULL OR discount.validFrom <= DATE(now())) AND (discount.validTo IS NULL OR discount.validTo >= DATE(now()))')
			->setGroupBy(['this.id']);

			$products = [];
			$productsByCategories = [];
			$i = 0;

			while ($product = $productsCollection->fetch(\stdClass::class)) {
				/** @var \stdClass $product */

				if (!$prices = $product->pricesPKs) {
					continue;
				}

				$products[$product->id] = [
					'product' => $product->id,
					'displayAmount' => $product->fkDisplayAmount,
					'displayDelivery' => $product->fkDisplayDelivery,
					'displayAmount_isSold' => $product->fkDisplayAmount ? $allDisplayAmounts[$product->fkDisplayAmount]->isSold : null,
					'producer' => $product->fkProducer,
					'name' => $product->name,
					'code' => $product->code,
					'subCode' => $product->subCode,
					'externalCode' => $product->externalCode,
					'ean' => $product->ean,
				];

				foreach ($allVisibilityLists as $visibilityList) {
					$products[$product->id]["visibilityList_$visibilityList->id"] = null;
					$products[$product->id]["visibilityList_{$visibilityList->id}_hidden"] = null;
					$products[$product->id]["visibilityList_{$visibilityList->id}_hiddenInMenu"] = null;
					$products[$product->id]["visibilityList_{$visibilityList->id}_priority"] = null;
					$products[$product->id]["visibilityList_{$visibilityList->id}_unavailable"] = null;
					$products[$product->id]["visibilityList_{$visibilityList->id}_recommended"] = null;
				}

				foreach ($allPriceLists as $priceList) {
					$products[$product->id]["priceList_$priceList->id"] = null;
					$products[$product->id]["priceList_{$priceList->id}_price"] = null;
					$products[$product->id]["priceList_{$priceList->id}_priceVat"] = null;
					$products[$product->id]["priceList_{$priceList->id}_priceBefore"] = null;
					$products[$product->id]["priceList_{$priceList->id}_priceVatBefore"] = null;
				}

				if ($categories = $product->categoriesPKs) {
					$categories = \explode(',', $categories);

					foreach ($categories as $category) {
						$categoryCategories = $allCategoriesByCategory[$category] ?? null;

						if ($categoryCategories === null) {
							$categoryCategories = $allCategoriesByCategory[$category] = \array_merge($this->getAncestorsOfCategory($category, $allCategories), [$category]);
						}

						$products[$product->id]['categories'] = \array_unique(\array_merge($products[$product->id]['categories'] ?? [], $categoryCategories));

						foreach ($products[$product->id]['categories'] as $productCategory) {
							$productsByCategories[$productCategory][$product->id] = true;
						}
					}
				}

				unset($products[$product->id]['categories']);

				if ($visibilityListItems = $product->visibilityListItemsPKs) {
					$visibilityListItems = \explode(',', $visibilityListItems);

					foreach ($visibilityListItems as $visibilityListItem) {
						$visibilityListItem = $allVisibilityListItems[$visibilityListItem];

						$products[$product->id]["visibilityList_{$visibilityListItem->visibilityListId}_hidden"] = $visibilityListItem->hidden;
						$products[$product->id]["visibilityList_{$visibilityListItem->visibilityListId}_hiddenInMenu"] = $visibilityListItem->hiddenInMenu;
						$products[$product->id]["visibilityList_{$visibilityListItem->visibilityListId}_priority"] = $visibilityListItem->priority;
						$products[$product->id]["visibilityList_{$visibilityListItem->visibilityListId}_unavailable"] = $visibilityListItem->unavailable;
						$products[$product->id]["visibilityList_{$visibilityListItem->visibilityListId}_recommended"] = $visibilityListItem->recommended;
					}
				}

				$prices = \explode(',', $prices);

				foreach ($prices as $price) {
					$price = $allPrices[$price];

					$products[$product->id]["priceList_{$price->priceListId}_price"] = $price->price;
					$products[$product->id]["priceList_{$price->priceListId}_priceVat"] = $price->priceVat;
					$products[$product->id]["priceList_{$price->priceListId}_priceBefore"] = $price->priceBefore;
					$products[$product->id]["priceList_{$price->priceListId}_priceVatBefore"] = $price->priceVatBefore;
				}

				$products[$product->id]['attributeValues'] = $product->attributeValuesPKs;

				$i++;

				if ($i !== 1000) {
					continue;
				}

				$i = 0;

				$this->connection->createRows("$productsCacheTableName", $products, chunkSize: 1000);
				$products = [];
			}

			$this->connection->createRows("$productsCacheTableName", $products);
			unset($products);

			$productsCollection->__destruct();

			foreach ($productsByCategories as $category => $products) {
				$categoryId = $allCategories[$category]->id;

				$link->exec("DROP TABLE IF EXISTS `eshop_categoryproducts_cache_{$cacheIndexToBeWarmedUp}_$categoryId`;");
				$link->exec("CREATE TABLE `eshop_categoryproducts_cache_{$cacheIndexToBeWarmedUp}_$categoryId` (
  product INT UNSIGNED PRIMARY KEY,
  FOREIGN KEY (product) REFERENCES eshop_products_cache_{$cacheIndexToBeWarmedUp}(product) ON UPDATE CASCADE ON DELETE CASCADE 
);");

				$newRows = [];

				foreach (\array_keys($products) as $product) {
					$newRows[] = ['product' => $product];
				}

				$this->connection->createRows("eshop_categoryproducts_cache_{$cacheIndexToBeWarmedUp}_$categoryId", $newRows);
			}

			$this->markCacheAsReady($cacheIndexToBeWarmedUp);
			$this->cleanCache();
		} catch (\Throwable $e) {
			Debugger::log($e, ILogger::EXCEPTION);
			Debugger::dump($e);

			$this->resetHangingStateOfCache($cacheIndexToBeWarmedUp);
		}
	}

	/**
	 * @param array<mixed> $filters
	 * @param string|null $orderByName
	 * @param 'ASC'|'DESC' $orderByDirection Works only if $orderByName is not null
	 * @param array<string, \Eshop\DB\Pricelist> $priceLists
	 * @param array<string, \Eshop\DB\VisibilityList> $visibilityLists
	 * @return array{
	 *     "productPKs": list<string>,
	 *     "attributeValuesCounts": array<string|int, int>,
	 *     "displayAmountsCounts": array<string|int, int>,
	 *     "displayDeliveriesCounts": array<string|int, int>,
	 *     "producersCounts": array<string|int, int>,
	 *     'priceMin': float,
	 *     'priceMax': float,
	 *     'priceVatMin': float,
	 *     'priceVatMax': float
	 * }|false
	 * @throws \StORM\Exception\NotFoundException|\Throwable
	 */
	public function getProductsFromCacheTable(
		array $filters,
		string|null $orderByName = null,
		string $orderByDirection = 'ASC',
		array $priceLists = [],
		array $visibilityLists = [],
	): array|false {
		$cacheIndex = $this->getCacheIndexToBeUsed();

		if ($cacheIndex === 0) {
			return false;
		}

		$dataCacheIndex = \serialize($filters) . '_' . $orderByName . '-' . $orderByDirection . '_' . \serialize(\array_keys($priceLists)) . '_' . \serialize(\array_keys($visibilityLists));

		$cachedData = $this->cache->load($dataCacheIndex, dependencies: [
			Cache::Tags => [self::PRODUCTS_PROVIDER_CACHE_TAG],
		]);

		if ($cachedData) {
			return $cachedData;
		}

		$emptyResult = [
			'productPKs' => [],
			'attributeValuesCounts' => [],
			'displayAmountsCounts' => [],
			'displayDeliveriesCounts' => [],
			'producersCounts' => [],
			'priceMin' => 0,
			'priceMax' => 0,
			'priceVatMin' => 0,
			'priceVatMax' => 0,
		];

		$mainCategoryType = $this->shopsConfig->getSelectedShop() ?
			$this->settingRepository->getValueByName(SettingsPresenter::MAIN_CATEGORY_TYPE . '_' . $this->shopsConfig->getSelectedShop()->getPK()) :
			'main';

		$category = isset($filters['category']) ?
			$this->categoryRepository->many()->setSelect(['this.id'])->where('this.path', $filters['category'])->where('this.fk_type', $mainCategoryType)->first(true) :
			null;

		unset($filters['category']);

		if ($category) {
			$categoryTableExists = $this->connection->getLink()
				->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_NAME = 'eshop_categoryproducts_cache_{$cacheIndex}_$category->id';")
				->fetchColumn();

			if ($categoryTableExists === 0) {
				$this->saveDataCacheIndex($dataCacheIndex, $emptyResult);

				return $emptyResult;
			}
		}
		
		$productsCollection = $category ?
			$this->connection->rows(['category' => "eshop_categoryproducts_cache_{$cacheIndex}_$category->id"])
				->join(['this' => "eshop_products_cache_{$cacheIndex}"], 'this.product = category.product', type: 'INNER') :
			$this->connection->rows(['this' => "eshop_products_cache_{$cacheIndex}"]);

		$productsCollection->setSelect([
			'product' => 'this.product',
			'producer' => 'this.producer',
			'attributeValues' => 'this.attributeValues',
			'displayAmount' => 'this.displayAmount',
			'displayDelivery' => 'this.displayDelivery',
			'price' => $this->createCoalesceFromArray($priceLists, 'priceList', 'price'),
			'priceVat' => $this->createCoalesceFromArray($priceLists, 'priceList', 'priceVat'),
		]);

		if (isset($filters['pricelist'])) {
			$priceLists = \array_filter($priceLists, fn($priceList) => Arrays::contains($filters['pricelist'], $priceList), \ARRAY_FILTER_USE_KEY);

			unset($filters['pricelist']);
		}

		$allAttributes = [];
		$dynamicFiltersAttributes = [];
		$dynamicFilters = [];

		foreach ($filters as $filter => $value) {
			if ($filter === 'attributes') {
				foreach ($value as $subKey => $subValue) {
					if ($subKey === 'availability') {
						$subValue = $this->displayAmountRepository->many()->setSelect(['this.id'])->where('this.uuid', $subValue)->toArrayOf('id', toArrayValues: true);

						$dynamicFilters["systemicAttributes.$subKey"] = \array_flip($subValue);
					} elseif ($subKey === 'producer') {
						$subValue = $this->producerRepository->many()->setSelect(['this.id'])->where('this.uuid', $subValue)->toArrayOf('id', toArrayValues: true);

						$dynamicFilters["systemicAttributes.$subKey"] = \array_flip($subValue);
					} elseif ($subKey === 'delivery') {
						$subValue = $this->displayDeliveryRepository->many()->setSelect(['this.id'])->where('this.uuid', $subValue)->toArrayOf('id', toArrayValues: true);

						$dynamicFilters["systemicAttributes.$subKey"] = \array_flip($subValue);
					} else {
						/** @var \Eshop\DB\Attribute $attribute */
						$attribute = $this->attributeRepository->many()->where('this.uuid', $subKey)->select(['this.id'])->first(true);
						$allAttributes[$attribute->id] = $attribute;

						$dynamicFiltersAttributes[$attribute->id] =
							$this->attributeValueRepository->many()->setSelect(['this.id'])->where('this.uuid', $subValue)->toArrayOf('id', toArrayValues: true);
					}
				}

				unset($filters['attributes']);
			}

			if (!isset($this->allowedDynamicFilterExpressions[$filter]) && !isset($this->allowedDynamicFilterColumns[$filter])) {
				continue;
			}

			$dynamicFilters[$filter] = $value;
		}

		foreach ($filters as $filter => $value) {
			if (isset($this->allowedCollectionFilterColumns[$filter])) {
				$filterColumn = $this->allowedCollectionFilterColumns[$filter];

				if (Strings::contains($filterColumn, '.')) {
					[$filterColumn1, $filterColumn2] = \explode('.', $filterColumn);

					$filterExpression = match ($filterColumn1) {
						'visibilityList' => $this->createCoalesceFromArray($visibilityLists, 'visibilityList', $filterColumn2),
						'priceList' => $this->createCoalesceFromArray($priceLists, 'priceList', $filterColumn2),
						default => $filterColumn,
					};
				} else {
					$filterExpression = $filterColumn;
				}

				$productsCollection->where($filterExpression, $value);

				continue;
			}

			if (isset($this->allowedCollectionFilterExpressions[$filter])) {
				$this->allowedCollectionFilterExpressions[$filter]($productsCollection, $value, $visibilityLists, $priceLists);

				continue;
			}

			if (isset($this->allowedDynamicFilterExpressions[$filter]) || isset($this->allowedDynamicFilterColumns[$filter])) {
				continue;
			}

			throw new \Exception("Filter '$filter' is not supported by ProductsProvider! You can add it manually with 'addAllowedFilterColumn' or 'addFilterExpression' functions.");
		}

		$productsCollection->where($this->createCoalesceFromArray($priceLists, 'priceList', 'price') . ' > 0');

		if ($orderByName) {
			if (isset($this->allowedCollectionOrderColumns[$orderByName])) {
				$orderColumn = $this->allowedCollectionOrderColumns[$orderByName];

				if (Strings::contains($orderColumn, '.')) {
					[$orderColumn1, $orderColumn2] = \explode('.', $orderColumn);

					$orderExpression = match ($orderColumn1) {
						'visibilityList' => $this->createCoalesceFromArray($visibilityLists, 'visibilityList', $orderColumn2),
						'priceList' => $this->createCoalesceFromArray($priceLists, 'priceList', $orderColumn2),
						default => $orderColumn,
					};
				} else {
					$orderExpression = $orderColumn;
				}

				$productsCollection->orderBy([$orderExpression => $orderByDirection]);
			} elseif (isset($this->allowedCollectionOrderExpressions[$orderByName])) {
				$this->allowedCollectionOrderExpressions[$orderByName]($productsCollection, $orderByDirection, $visibilityLists, $priceLists);
			} else {
				throw new \Exception("Order '$orderByName' is not supported by ProductsProvider! You can add it manually with 'addAllowedOrderColumn' or 'addOrderExpression' function.");
			}
		}

		$productPKs = [];
		$displayAmountsCounts = [];
		$displayDeliveriesCounts = [];
		$producersCounts = [];
		$attributeValuesCounts = [];

//		DevelTools::dumpCollection($productsCollection);

		$priceMin = \PHP_FLOAT_MAX;
		$priceMax = \PHP_FLOAT_MIN;
		$priceVatMin = \PHP_FLOAT_MAX;
		$priceVatMax = \PHP_FLOAT_MIN;

		$dynamicallyCountedDynamicFilters = [];

		while ($product = $productsCollection->fetch()) {
			$attributeValues = $product->attributeValues ? \array_flip(\explode(',', $product->attributeValues)) : [];

			foreach ($dynamicFiltersAttributes as $attributePK => $attributeValuesPKs) {
				if (\count($attributeValuesPKs) === 0) {
					continue;
				}

				/** @var \Eshop\DB\Attribute $attribute */
				$attribute = $allAttributes[$attributePK];

				if ($attribute->filterType === 'and') {
					foreach ($attributeValuesPKs as $attributeValue) {
						if (!isset($attributeValues[$attributeValue])) {
							continue 3;
						}
					}
				} else {
					$found = false;

					foreach ($attributeValuesPKs as $attributeValue) {
						if (isset($attributeValues[$attributeValue])) {
							$found = true;

							break;
						}
					}

					if (!$found) {
						continue 2;
					}
				}
			}

			foreach (\array_keys($dynamicFilters) as $filter) {
				$subDynamicFilters = $dynamicFilters;
				unset($subDynamicFilters[$filter]);

				$useProduct = true;

				foreach ($subDynamicFilters as $subFilter => $value) {
					if (isset($this->allowedDynamicFilterColumns[$subFilter])) {
						if (!$product->{$this->allowedDynamicFilterColumns[$subFilter]}) {
							$useProduct = false;

							break;
						}

						if (!isset($value[$product->{$this->allowedDynamicFilterColumns[$subFilter]}])) {
							$useProduct = false;

							break;
						}

						continue;
					}

					if (!isset($this->allowedDynamicFilterExpressions[$subFilter])) {
						continue;
					}

					if (!$this->allowedDynamicFilterExpressions[$subFilter]($product, $value, $visibilityLists, $priceLists)) {
						$useProduct = false;

						break;
					}
				}

				if (!$useProduct) {
					continue;
				}

				if ($filter === 'priceFrom') {
					$dynamicallyCountedDynamicFilters[$filter] = true;

					if ($product->price < $priceMin) {
						$priceMin = $product->price;
					}

					if ($product->priceVat < $priceVatMin) {
						$priceVatMin = $product->priceVat;
					}
				}

				if ($filter === 'priceTo') {
					$dynamicallyCountedDynamicFilters[$filter] = true;

					if ($product->price > $priceMax) {
						$priceMax = $product->price;
					}

					if ($product->priceVat > $priceVatMax) {
						$priceVatMax = $product->priceVat;
					}
				}

				if ($filter === 'systemicAttributes.availability' && $product->displayAmount) {
					$dynamicallyCountedDynamicFilters[$filter] = true;

					$displayAmountsCounts[$product->displayAmount] = ($displayAmountsCounts[$product->displayAmount] ?? 0) + 1;
				}

				if ($filter === 'systemicAttributes.delivery' && $product->displayDelivery) {
					$dynamicallyCountedDynamicFilters[$filter] = true;

					$displayDeliveriesCounts[$product->displayDelivery] = ($displayDeliveriesCounts[$product->displayAmount] ?? 0) + 1;
				}

				if ($filter !== 'systemicAttributes.producer' || !$product->producer) {
					continue;
				}

				$dynamicallyCountedDynamicFilters[$filter] = true;

				$producersCounts[$product->producer] = ($producersCounts[$product->producer] ?? 0) + 1;
			}

			foreach ($dynamicFilters as $filter => $value) {
				if (isset($this->allowedDynamicFilterColumns[$filter])) {
					if (!$product->{$this->allowedDynamicFilterColumns[$filter]}) {
						continue 2;
					}

					if (!isset($value[$product->{$this->allowedDynamicFilterColumns[$filter]}])) {
						continue 2;
					}

					continue;
				}

				if (!isset($this->allowedDynamicFilterExpressions[$filter])) {
					continue;
				}

				if (!$this->allowedDynamicFilterExpressions[$filter]($product, $value, $visibilityLists, $priceLists)) {
					continue 2;
				}
			}

			if (!isset($dynamicallyCountedDynamicFilters['systemicAttributes.availability']) && $product->displayAmount) {
				$displayAmountsCounts[$product->displayAmount] = ($displayAmountsCounts[$product->displayAmount] ?? 0) + 1;
			}

			if (!isset($dynamicallyCountedDynamicFilters['systemicAttributes.delivery']) && $product->displayDelivery) {
				$displayDeliveriesCounts[$product->displayDelivery] = ($displayDeliveriesCounts[$product->displayDelivery] ?? 0) + 1;
			}

			if (!isset($dynamicallyCountedDynamicFilters['systemicAttributes.producer']) && $product->producer) {
				$producersCounts[$product->producer] = ($producersCounts[$product->producer] ?? 0) + 1;
			}

			if (!isset($dynamicallyCountedDynamicFilters['priceFrom'])) {
				if ($product->price < $priceMin) {
					$priceMin = $product->price;
				}

				if ($product->priceVat < $priceVatMin) {
					$priceVatMin = $product->priceVat;
				}
			}

			if (!isset($dynamicallyCountedDynamicFilters['priceTo'])) {
				if ($product->price > $priceMax) {
					$priceMax = $product->price;
				}

				if ($product->priceVat > $priceVatMax) {
					$priceVatMax = $product->priceVat;
				}
			}

			$productPKs[] = $product->product;

			foreach (\array_keys($attributeValues) as $attributeValue) {
				$attributeValuesCounts[$attributeValue] = ($attributeValuesCounts[$attributeValue] ?? 0) + 1;
			}
		}

		$displayAmounts = $this->displayAmountRepository->many()->setSelect(['this.uuid'])->where('this.id', \array_keys($displayAmountsCounts))->setIndex('this.id')->toArrayOf('uuid');

		foreach ($displayAmounts as $displayAmountId => $displayAmountUuid) {
			$displayAmountsCounts[$displayAmountUuid] = $displayAmountsCounts[$displayAmountId];
			unset($displayAmountsCounts[$displayAmountId]);
		}

		$displayDeliveries = $this->displayDeliveryRepository->many()->setSelect(['this.uuid'])->where('this.id', \array_keys($displayDeliveriesCounts))->setIndex('this.id')->toArrayOf('uuid');

		foreach ($displayDeliveries as $displayDeliveryId => $displayDeliveryUuid) {
			$displayDeliveriesCounts[$displayDeliveryUuid] = $displayDeliveriesCounts[$displayDeliveryId];
			unset($displayDeliveriesCounts[$displayDeliveryId]);
		}

		$producers = $this->producerRepository->many()->setSelect(['this.uuid'])->where('this.id', \array_keys($producersCounts))->setIndex('this.id')->toArrayOf('uuid');

		foreach ($producers as $producerId => $producerUuid) {
			$producersCounts[$producerUuid] = $producersCounts[$producerId];
			unset($producersCounts[$producerId]);
		}

		$attributeValues = $this->attributeValueRepository->many()->setSelect(['this.uuid'])->where('this.id', \array_keys($attributeValuesCounts))->setIndex('this.id')->toArrayOf('uuid');

		foreach ($attributeValues as $attributeValueId => $attributeValueUuid) {
			$attributeValuesCounts[$attributeValueUuid] = $attributeValuesCounts[$attributeValueId];
			unset($attributeValuesCounts[$attributeValueId]);
		}

		$result = [
			'productPKs' => $productPKs,
			'attributeValuesCounts' => $attributeValuesCounts,
			'displayAmountsCounts' => $displayAmountsCounts,
			'displayDeliveriesCounts' => $displayDeliveriesCounts,
			'producersCounts' => $producersCounts,
			'priceMin' => $priceMin < \PHP_FLOAT_MAX ? \floor($priceMin) : 0,
			'priceMax' => $priceMax > \PHP_FLOAT_MIN ? \ceil($priceMax) : 0,
			'priceVatMin' => $priceVatMin < \PHP_FLOAT_MAX ? \floor($priceVatMin) : 0,
			'priceVatMax' => $priceVatMax > \PHP_FLOAT_MIN ? \ceil($priceVatMax) : 0,
		];

		$this->saveDataCacheIndex($dataCacheIndex, $result);

		return $result;
	}

	public function cleanCache(): void
	{
		$this->cache->clean([Cache::Tags => [self::PRODUCTS_PROVIDER_CACHE_TAG]]);
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
	 * @param array<mixed> $values
	 */
	protected function createCoalesceFromArray(array $values, string|null $prefix = null, string|null $suffix = null, string $separator = '_'): string
	{
		return $values ? ('COALESCE(' . \implode(',', \array_map(function (mixed $item) use ($prefix, $suffix, $separator): string {
			return $prefix . ($prefix ? $separator : '') . $item->id . ($suffix ? $separator : '') . $suffix;
		}, $values)) . ')') : 'NULL';
	}

	protected function saveDataCacheIndex(string $index, array $data): void
	{
		$this->cache->save($index, $data, [
			Cache::Tags => [self::PRODUCTS_PROVIDER_CACHE_TAG],
		]);
	}
}
