<?php

namespace Eshop;

use Base\ShopsConfig;
use Carbon\Carbon;
use Eshop\Admin\ScriptsPresenter;
use Eshop\Admin\SettingsPresenter;
use Eshop\Common\Helpers;
use Eshop\DB\AttributeRepository;
use Eshop\DB\AttributeValueRepository;
use Eshop\DB\CategoryRepository;
use Eshop\DB\CategoryTypeRepository;
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
use Nette\Caching\Cache;
use Nette\Caching\Storage;
use Nette\DI\Container;
use Nette\Utils\Arrays;
use Nette\Utils\Random;
use Nette\Utils\Strings;
use StORM\DIConnection;
use StORM\ICollection;
use Tracy\Debugger;
use Tracy\ILogger;
use Web\DB\SettingRepository;

class ProductsProvider implements GeneralProductProvider
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
	 * @var array<callable(
	 *  \StORM\ICollection<\stdClass> $productsCollection,
	 *  'ASC'|'DESC' $direction,
	 *  array<\Eshop\DB\VisibilityList> $visibilityLists,
	 *  array<\Eshop\DB\Pricelist> $priceLists,
	 *  string $pricesCacheTableName,
	 * ): void>
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
		readonly Storage $storage,
	) {
		$this->cache = new Cache($storage);

		$this->allowedCollectionOrderExpressions['availabilityAndPrice'] =
			function (ICollection $productsCollection, string $direction, array $visibilityLists, array $priceLists, string $pricesCacheTableName): void {
				$productsCollection->orderBy([
					'case COALESCE(displayAmount_isSold, 2)
						 when 0 then 0
						 when 2 then 1
						 when 1 then 2
						 else 2 end' => $direction,
				]);

				$this->applyPricesOrderToCollection($productsCollection, $direction, $priceLists, $pricesCacheTableName, 'price', '> 0');
			};

		$this->allowedCollectionOrderExpressions['priorityAvailabilityPrice'] =
			function (ICollection $productsCollection, string $direction, array $visibilityLists, array $priceLists, string $pricesCacheTableName): void {
				$productsCollection->orderBy([
					$this->createCoalesceFromArray($visibilityLists, 'visibilityList', 'priority') => $direction,
					'case COALESCE(displayAmount_isSold, 2)
	                     when 0 then 0
	                     when 1 then 1
	                     when 2 then 2
	                     else 2 end' => $direction,
				]);

				$this->applyPricesOrderToCollection($productsCollection, $direction, $priceLists, $pricesCacheTableName, 'price', '> 0');
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
		 * @param array{0: array<string>|string, 1: string} $value
		 * @param array<\Eshop\DB\VisibilityList> $visibilityLists
		 * @param array<\Eshop\DB\Pricelist> $priceLists
		 * @throws \Exception
		 */
		$this->allowedCollectionFilterExpressions['primaryCategoryByCategoryType'] = function (ICollection $productsCollection, array $value, array $visibilityLists, array $priceLists): void {
			if (\count($value) !== 2) {
				throw new \Exception("Filter 'primaryCategoryByCategoryType': Input must be array with exactly 2 items!");
			}

			[$categories, $categoryType] = $value;
			$categories = $this->categoryRepository->many()->where('this.uuid', $categories)->setSelect(['this.id'])->toArrayOf('id', toArrayValues: true);
			$categoryType = $this->categoryTypeRepository->many()->where('this.uuid', $categoryType)->setSelect(['id' => 'this.id'])->firstValue('id');

			$productsCollection->where("this.primaryCategory_$categoryType", $categories);
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

		$this->allowedDynamicFilterExpressions['priceGt'] = function (\stdClass $product, mixed $value, array $visibilityLists, array $priceLists): bool {
			$showVat = $this->shopperUser->getMainPriceType() === 'withVat';

			return $showVat ? $product->priceVat > $value : $product->price > $value;
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

		$this->cleanProductsProviderCache();

		try {
			$link = $this->connection->getLink();
			$dbName = $this->connection->getDatabaseName();
			$mutationSuffix = $this->connection->getMutationSuffix();

			$this->markCacheAsWarming($cacheIndexToBeWarmedUp);

			Debugger::timer();

			$categoryTablesInDb = $link->query("SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES
                  WHERE TABLE_NAME LIKE 'eshop_categories_cache_{$cacheIndexToBeWarmedUp}_%' AND TABLE_SCHEMA = '$dbName';")
				->fetchAll(\PDO::FETCH_COLUMN);

			foreach ($categoryTablesInDb as $categoryTableName) {
				$link->exec("DROP TABLE `$categoryTableName`");
			}

			$categoryTablesInDb = $link->query("SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES
                  WHERE TABLE_NAME LIKE 'eshop_categoryproducts_cache_{$cacheIndexToBeWarmedUp}_%' AND TABLE_SCHEMA = '$dbName';")
				->fetchAll(\PDO::FETCH_COLUMN);

			foreach ($categoryTablesInDb as $categoryTableName) {
				$link->exec("DROP TABLE `$categoryTableName`");
			}

			$productsCacheTableName = "eshop_products_cache_$cacheIndexToBeWarmedUp";
			$relationsCacheTableName = "eshop_products_relations_cache_$cacheIndexToBeWarmedUp";
			$pricesCacheTableName = "eshop_products_prices_cache_$cacheIndexToBeWarmedUp";

			Debugger::dump(Debugger::timer());
			$link->exec("DROP TABLE IF EXISTS `$relationsCacheTableName`");

			$link->exec("
CREATE TABLE `$relationsCacheTableName` (
    id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    master INT UNSIGNED NOT NULL,
    slave INT UNSIGNED NOT NULL,
    priority SMALLINT NOT NULL,
    amount SMALLINT NOT NULL,
    hidden TINYINT(1) NOT NULL,
    systemic TINYINT(1) NOT NULL,
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

			Debugger::dump(Debugger::timer());

			$link->exec("CREATE INDEX idx_master ON `$relationsCacheTableName` (master);");
			$link->exec("CREATE INDEX idx_slave ON `$relationsCacheTableName` (slave);");
			$link->exec("CREATE INDEX idx_type ON `$relationsCacheTableName` (type);");
			$link->exec("CREATE INDEX idx_related_master ON `$relationsCacheTableName` (master, type);");
			$link->exec("CREATE INDEX idx_related_slave ON `$relationsCacheTableName` (slave, type);");
			$link->exec("CREATE INDEX idx_products_related_unique ON `$relationsCacheTableName` (master, slave);");
			$link->exec("CREATE UNIQUE INDEX idx_related_code ON `$relationsCacheTableName` (master, slave, amount, discountPct, masterPct);");

			Debugger::dump(Debugger::timer());

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
  ean TEXT
);");
			$allVisibilityLists = $this->visibilityListRepository->many()->select(['this.id'])->fetchArray(\stdClass::class);

			foreach ($allVisibilityLists as $visibilityList) {
//              $link->exec("ALTER TABLE `$productsCacheTableName` ADD COLUMN visibilityList_{$visibilityList->id} INT UNSIGNED DEFAULT('{$visibilityList->id}');");
//              $link->exec("ALTER TABLE `$productsCacheTableName` ADD INDEX idx_visibilityList_{$visibilityList->id} (visibilityList_{$visibilityList->id});");

				$link->exec("ALTER TABLE `$productsCacheTableName` ADD COLUMN visibilityList_{$visibilityList->id}_hidden TINYINT;");
				$link->exec("ALTER TABLE `$productsCacheTableName` ADD COLUMN visibilityList_{$visibilityList->id}_hiddenInMenu TINYINT;");
				$link->exec("ALTER TABLE `$productsCacheTableName` ADD COLUMN visibilityList_{$visibilityList->id}_priority SMALLINT;");
				$link->exec("ALTER TABLE `$productsCacheTableName` ADD COLUMN visibilityList_{$visibilityList->id}_unavailable TINYINT;");
				$link->exec("ALTER TABLE `$productsCacheTableName` ADD COLUMN visibilityList_{$visibilityList->id}_recommended TINYINT;");
			}

			$allCategoryTypes = $this->categoryTypeRepository->many()->select(['this.id'])->fetchArray(\stdClass::class);

			foreach ($allCategoryTypes as $categoryType) {
				$link->exec("ALTER TABLE `$productsCacheTableName` ADD COLUMN primaryCategory_{$categoryType->id} INT UNSIGNED;");
			}

//          $allPriceLists = $this->pricelistRepository->many()->select(['this.id'])->fetchArray(\stdClass::class);

//          foreach ($allPriceLists as $priceList) {
//              $link->exec("ALTER TABLE `$productsCacheTableName` ADD COLUMN priceList_{$priceList->id} INT UNSIGNED DEFAULT('{$priceList->id}');");
//
//              $link->exec("ALTER TABLE `$productsCacheTableName` ADD COLUMN priceList_{$priceList->id}_price DOUBLE;");
//              $link->exec("ALTER TABLE `$productsCacheTableName` ADD COLUMN priceList_{$priceList->id}_priceVat DOUBLE;");
//              $link->exec("ALTER TABLE `$productsCacheTableName` ADD COLUMN priceList_{$priceList->id}_priceBefore DOUBLE;");
//              $link->exec("ALTER TABLE `$productsCacheTableName` ADD COLUMN priceList_{$priceList->id}_priceVatBefore DOUBLE;");
//          }

			Debugger::dump(Debugger::timer());

			$link->exec("
DROP TABLE IF EXISTS `$pricesCacheTableName`;
CREATE TABLE `$pricesCacheTableName` (
  id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  product INT UNSIGNED NOT NULL,
  priceList INT UNSIGNED NOT NULL,
  price DOUBLE,
  priceVat DOUBLE,
  priceBefore DOUBLE,
  priceVatBefore DOUBLE,
  priority INT NOT NULL
);");

			$allPrices = $this->priceRepository->many()
				->join(['pricelist' => 'eshop_pricelist'], 'this.fk_pricelist = pricelist.uuid')
				->join(['product' => 'eshop_product'], 'this.fk_product = product.uuid')
				->setSelect([
					'this.price',
					'this.priceVat',
					'this.priceBefore',
					'this.priceVatBefore',
					'priceListId' => 'pricelist.id',
					'priceListPriority' => 'pricelist.priority',
					'productId' => 'product.id',
				], keepIndex: true)->fetchArray(\stdClass::class);

			$pricesToInsert = [];
			$i = 0;

			Debugger::dump(Debugger::timer());

			foreach ($allPrices as $price) {
				$pricesToInsert[] = [
					'product' => $price->productId,
					'priceList' => $price->priceListId,
					'price' => $price->price,
					'priceVat' => $price->priceVat,
					'priceBefore' => $price->priceBefore,
					'priceVatBefore' => $price->priceVatBefore,
					'priority' => $price->priceListPriority,
				];

				$i++;

				if ($i !== 1000) {
					continue;
				}

				$i = 0;

				$this->connection->createRows("$pricesCacheTableName", $pricesToInsert, chunkSize: 1000);
				$pricesToInsert = [];
			}

			$this->connection->createRows("$pricesCacheTableName", $pricesToInsert);
			unset($pricesToInsert);

			Debugger::dump(Debugger::timer());

			$link->exec("CREATE INDEX idx_product ON `$pricesCacheTableName` (product);");
			$link->exec("CREATE INDEX idx_priority ON `$pricesCacheTableName` (priority);");
			$link->exec("CREATE UNIQUE INDEX idx_prices_unique ON `$pricesCacheTableName` (product, priceList);");

			Debugger::dump(Debugger::timer());

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

			/** @var array<object{id: int, ancestor: string}> $allCategories */
			$allCategories = $this->categoryRepository->many()->setSelect(['this.id', 'ancestor' => 'this.fk_ancestor'], keepIndex: true)->fetchArray(\stdClass::class);

			/** @var array<object{category: string|null, categoryType: string}> $allProductPrimaryCategories */
			$allProductPrimaryCategories = $this->productPrimaryCategoryRepository->many()
				->join(['eshop_categorytype'], 'this.fk_categoryType = eshop_categorytype.uuid')
				->join(['eshop_category'], 'this.fk_category = eshop_category.uuid')
				->setSelect(['category' => 'eshop_category.id', 'categoryType' => 'eshop_categorytype.id'], keepIndex: true)
				->fetchArray(\stdClass::class);

			$allCategoriesByCategory = [];

			Debugger::dump(Debugger::timer());

			$this->connection->getLink()->exec('SET SESSION group_concat_max_len=4294967295');

			$productPrimaryCategories = $this->productRepository->many()
				->join(['joinedTable' => 'eshop_productprimarycategory'], 'this.uuid = joinedTable.fk_product', type: 'INNER')
				->setSelect([
					'id' => 'this.id',
					'groupedValues' => 'GROUP_CONCAT(DISTINCT joinedTable.uuid)',
				])
				->setGroupBy(['this.id'])
				->setIndex('id')
				->toArrayOf('groupedValues');

			$productAttributeValues = $this->productRepository->many()
				->join(['assign' => 'eshop_attributeassign'], 'this.uuid = assign.fk_product', type: 'INNER')
				->join(['joinedTable' => 'eshop_productprimarycategory'], 'assign.fk_value = joinedTable.fk_product', type: 'INNER')
				->setSelect([
					'id' => 'this.id',
					'groupedValues' => 'GROUP_CONCAT(DISTINCT joinedTable.uuid)',
				])
				->setGroupBy(['this.id'])
				->setIndex('id')
				->toArrayOf('groupedValues');

			$productVisibilityListItems = $this->productRepository->many()
				->join(['visibilityListItem' => 'eshop_visibilitylistitem'], 'this.uuid = visibilityListItem.fk_product', type: 'INNER')
				->join(['visibilityList' => 'eshop_visibilitylist'], 'visibilityListItem.fk_visibilityList = visibilityList.uuid', type: 'INNER')
				->setSelect([
					'id' => 'this.id',
					'groupedValues' => 'GROUP_CONCAT(DISTINCT visibilityListItem.uuid ORDER BY visibilityList.priority)',
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

			Debugger::dump(Debugger::timer());

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

			$products = [];
			$productsByCategories = [];
			$i = 0;

			$first = true;

			while ($product = $productsCollection->fetch(\stdClass::class)) {
				/** @var \stdClass $product */

				if ($first) {
					Debugger::dump(Debugger::timer());

					$first = false;
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
					$products[$product->id]["visibilityList_{$visibilityList->id}_hidden"] = null;
					$products[$product->id]["visibilityList_{$visibilityList->id}_hiddenInMenu"] = null;
					$products[$product->id]["visibilityList_{$visibilityList->id}_priority"] = null;
					$products[$product->id]["visibilityList_{$visibilityList->id}_unavailable"] = null;
					$products[$product->id]["visibilityList_{$visibilityList->id}_recommended"] = null;
				}

				foreach ($allCategoryTypes as $categoryType) {
					$products[$product->id]["primaryCategory_$categoryType->id"] = null;
				}

				if ($categories = ($productCategories[$product->id] ?? null)) {
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

				if ($visibilityListItems = ($productVisibilityListItems[$product->id] ?? null)) {
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

				$primaryCategories = isset($productPrimaryCategories[$product->id]) ? \explode(',', $productPrimaryCategories[$product->id]) : [];

				foreach ($primaryCategories as $primaryCategory) {
					$primaryCategory = $allProductPrimaryCategories[$primaryCategory];

					$products[$product->id]["primaryCategory_$primaryCategory->categoryType"] = $primaryCategory->category;
				}

				$products[$product->id]['attributeValues'] = ($productAttributeValues[$product->id] ?? null);

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

			Debugger::dump(Debugger::timer());

			$link->exec("CREATE INDEX idx_producer ON `$productsCacheTableName` (producer);");
			$link->exec("CREATE INDEX idx_displayAmount ON `$productsCacheTableName` (displayAmount);");
			$link->exec("CREATE INDEX idx_displayDelivery ON `$productsCacheTableName` (displayDelivery);");
			$link->exec("CREATE INDEX idx_displayAmount_isSold ON `$productsCacheTableName` (displayAmount_isSold);");
			$link->exec("CREATE INDEX idx_subCode ON `$productsCacheTableName` (subCode);");
			$link->exec("CREATE INDEX idx_externalCode ON `$productsCacheTableName` (externalCode);");
			$link->exec("CREATE FULLTEXT INDEX idx_name ON `$productsCacheTableName` (name);");
			$link->exec("CREATE UNIQUE INDEX idx_unique_code ON `$productsCacheTableName` (code);");
			$link->exec("CREATE UNIQUE INDEX idx_unique_ean ON `$productsCacheTableName` (ean);");

//          $link->exec("ALTER TABLE $pricesCacheTableName ADD CONSTRAINT FOREIGN KEY (product) REFERENCES $productsCacheTableName(product) ON UPDATE CASCADE ON DELETE CASCADE;");

			foreach ($allCategoryTypes as $categoryType) {
				$link->exec("ALTER TABLE `$productsCacheTableName` ADD INDEX idx_primaryCategory_{$categoryType->id} (primaryCategory_{$categoryType->id});");
			}

			Debugger::dump(Debugger::timer());

			foreach ($productsByCategories as $category => $products) {
				$categoryId = $allCategories[$category]->id;

				$tableName = "eshop_categories_cache_{$cacheIndexToBeWarmedUp}_$categoryId";

				$link->exec("DROP TABLE IF EXISTS `$tableName`;");
				$link->exec("CREATE TABLE `$tableName` (product INT UNSIGNED PRIMARY KEY);");

				$newRows = [];

				foreach (\array_keys($products) as $product) {
					$newRows[] = ['product' => $product];
				}

				$this->connection->createRows("$tableName", $newRows, chunkSize: 1000);

				$link->exec("ALTER TABLE $tableName ADD CONSTRAINT FOREIGN KEY (product) REFERENCES eshop_products_cache_{$cacheIndexToBeWarmedUp}(product) ON UPDATE CASCADE ON DELETE CASCADE ");
			}

			Debugger::dump(Debugger::timer());

			$this->markCacheAsReady($cacheIndexToBeWarmedUp);
			$this->cleanProductsProviderCache();
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
	 * @throws \StORM\Exception\GeneralException
	 * @throws \StORM\Exception\NotFoundException
	 * @throws \Throwable
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

//      $dataCacheIndex = \serialize($filters) . '_' . $orderByName . '-' . $orderByDirection . '_' . \serialize(\array_keys($priceLists)) . '_' . \serialize(\array_keys($visibilityLists));
//
//      $cachedData = $this->cache->load($dataCacheIndex, dependencies: [
//          Cache::Tags => [self::PRODUCTS_PROVIDER_CACHE_TAG],
//      ]);
//
//      if ($cachedData) {
//          return $cachedData;
//      }

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

		$dbName = $this->connection->getDatabaseName();

		if ($category) {
			$categoryTableExistsQuery = $this->connection->getLink()
				->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_NAME = 'eshop_categories_cache_{$cacheIndex}_$category->id' AND TABLE_SCHEMA = '$dbName';");

			if (!$categoryTableExistsQuery) {
				return $emptyResult;
			}

			$categoryTableExistsQuery = $categoryTableExistsQuery->fetchColumn();

			if ($categoryTableExistsQuery === 0) {
//              $this->saveDataCacheIndex($dataCacheIndex, $emptyResult);

				return $emptyResult;
			}
		}
		
		$productsCollection = $category ?
			$this->connection->rows(['category' => "eshop_categories_cache_{$cacheIndex}_$category->id"])
				->join(['this' => "eshop_products_cache_{$cacheIndex}"], 'this.product = category.product', type: 'INNER') :
			$this->connection->rows(['this' => "eshop_products_cache_{$cacheIndex}"]);

		$pricesCacheTableName = "eshop_products_prices_cache_{$cacheIndex}";

		$productsCollection->setGroupBy(['this.product']);

		if (isset($filters['pricelist'])) {
			$priceLists = \array_filter($priceLists, fn($priceList) => Arrays::contains($filters['pricelist'], $priceList), \ARRAY_FILTER_USE_KEY);

			unset($filters['pricelist']);
		}

		$inPriceLists = Helpers::arrayToSqlInStatement($priceLists, 'id');

		$productsCollection->setSelect([
			'product' => 'this.product',
			'producer' => 'this.producer',
			'attributeValues' => 'this.attributeValues',
			'displayAmount' => 'this.displayAmount',
			'displayDelivery' => 'this.displayDelivery',
			'price' => "(SELECT price FROM $pricesCacheTableName as prices WHERE this.product = prices.product AND prices.priceList IN(:inPriceLists) AND prices.price > 0 AND prices.priority =
                (SELECT MIN(pricesPriority.priority) FROM $pricesCacheTableName as pricesPriority WHERE
                    this.product = pricesPriority.product AND pricesPriority.priceList IN(:inPriceLists) AND pricesPriority.price > 0)
            LIMIT 1)",
			'priceVat' => "(SELECT priceVat FROM $pricesCacheTableName as prices WHERE this.product = prices.product AND prices.priceList IN(:inPriceLists) AND prices.price > 0 AND prices.priority =
                (SELECT MIN(pricesPriority.priority) FROM $pricesCacheTableName as pricesPriority WHERE
                    this.product = pricesPriority.product AND pricesPriority.priceList IN(:inPriceLists) AND pricesPriority.price > 0)
            LIMIT 1)",
		], ['inPriceLists' => $inPriceLists,]);

		$allAttributes = [];
		$dynamicFiltersAttributes = [];
		$dynamicFilters = [];

		$relationsCacheTableName = "eshop_products_relations_cache_$cacheIndex";

		if (isset($filters['relatedTypeMaster']) && isset($filters['relatedTypeSlave'])) {
			throw new \Exception("Filters 'relatedTypeMaster' and 'relatedTypeSlave' can't be used at the same time.");
		}

		if (isset($filters['relatedTypeMaster'])) {
			$relatedTypeMaster = $filters['relatedTypeMaster'];

			if (!isset($relatedTypeMaster[0]) || !isset($relatedTypeMaster[1])) {
				throw new \Exception("Incomplete values for filter: 'relatedTypeMaster'.");
			}

			$relatedTypeMaster[0] = $this->productRepository->many()->where('this.uuid', $relatedTypeMaster[0])->setSelect(['id' => 'this.id'])->firstValue('id');
			$relatedTypeMaster[1] = $this->relatedTypeRepository->many()->where('this.uuid', $relatedTypeMaster[1])->setSelect(['id' => 'this.id'])->firstValue('id');

			$productsCollection->where('this.product', $this->connection->rows([$relationsCacheTableName])
				->where('master', $relatedTypeMaster[0])
				->where('type', $relatedTypeMaster[1])
				->toArrayOf('slave'));

			unset($filters['relatedTypeMaster']);
		}

		if (isset($filters['relatedTypeSlave'])) {
			$relatedTypeSlave = $filters['relatedTypeSlave'];

			if (!isset($relatedTypeSlave[0]) || !isset($relatedTypeSlave[1])) {
				throw new \Exception("Incomplete values for filter: 'relatedTypeSlave'.");
			}

			$relatedTypeSlave[0] = $this->productRepository->many()->where('this.uuid', $relatedTypeSlave[0])->setSelect(['id' => 'this.id'])->firstValue('id');
			$relatedTypeSlave[1] = $this->relatedTypeRepository->many()->where('this.uuid', $relatedTypeSlave[1])->setSelect(['id' => 'this.id'])->firstValue('id');

			$productsCollection->where('this.product', $this->connection->rows([$relationsCacheTableName])
				->where('slave', $relatedTypeSlave[0])
				->where('type', $relatedTypeSlave[1])
				->toArrayOf('master'));

			unset($filters['relatedTypeSlave']);
		}

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

//      $productsCollection->where($this->createCoalesceFromArray($priceLists, 'priceList', 'price') . ' > 0');
		$productsCollection->where("EXISTS (SELECT * FROM $pricesCacheTableName as prices WHERE this.product = prices.product AND prices.priceList IN(:inPriceLists) AND prices.price > 0)");

		if ($orderByName) {
			if (isset($this->allowedCollectionOrderColumns[$orderByName])) {
				$orderColumn = $this->allowedCollectionOrderColumns[$orderByName];

				$orderExpression = null;

				if (Strings::contains($orderColumn, '.')) {
					[$orderColumn1, $orderColumn2] = \explode('.', $orderColumn);

					if ($orderColumn1 === 'priceList') {
						$this->applyPricesOrderToCollection($productsCollection, $orderByDirection, $priceLists, $pricesCacheTableName, 'price', '> 0');
					} else {
						$orderExpression = match ($orderColumn1) {
							'visibilityList' => $this->createCoalesceFromArray($visibilityLists, 'visibilityList', $orderColumn2),
							default => $orderColumn,
						};
					}
				} else {
					$orderExpression = $orderColumn;
				}

				if ($orderExpression) {
					$productsCollection->orderBy([$orderExpression => $orderByDirection]);
				}
			} elseif (isset($this->allowedCollectionOrderExpressions[$orderByName])) {
				$this->allowedCollectionOrderExpressions[$orderByName]($productsCollection, $orderByDirection, $visibilityLists, $priceLists, $pricesCacheTableName);
			} else {
				throw new \Exception("Order '$orderByName' is not supported by ProductsProvider! You can add it manually with 'addAllowedOrderColumn' or 'addOrderExpression' function.");
			}
		}

		$productPKs = [];
		$displayAmountsCounts = [];
		$displayDeliveriesCounts = [];
		$producersCounts = [];
		$attributeValuesCounts = [];

//		DevelTools::bdumpCollection($productsCollection);

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

		return [
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

//      $this->saveDataCacheIndex($dataCacheIndex, $result);

//      return $result;
	}

	public function cleanProductsProviderCache(): void
	{
		$this->cache->clean([Cache::Tags => [self::PRODUCTS_PROVIDER_CACHE_TAG]]);
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
	 * @param \StORM\ICollection<\stdClass> $productsCollection
	 * @param 'ASC'|'DESC' $direction
	 * @param array<\Eshop\DB\Pricelist> $priceLists
	 * @param string $pricesCacheTableName
	 * @param string $property
	 * @param string|null $where
	 */
	protected function applyPricesOrderToCollection(ICollection $productsCollection, string $direction, array $priceLists, string $pricesCacheTableName, string $property, string|null $where): void
	{
		$whereIf1 = null;
		$whereIf2 = null;

		if ($where) {
			$whereIf1 = "AND prices.$property $where";
			$whereIf2 = "AND pricesPriority.$property $where";
		}

		$priceListsInString = Helpers::arrayToSqlInStatement($priceLists, 'id');
		$priceListsPropertyName = 'var__inPriceLists__' . Random::generate();

		$productsCollection->orderBy([
			"(SELECT $property FROM $pricesCacheTableName as prices WHERE this.product = prices.product AND prices.priceList IN(:inPriceLists) $whereIf1 AND prices.priority =
				(SELECT MIN(pricesPriority.priority) FROM $pricesCacheTableName as pricesPriority WHERE
					this.product = pricesPriority.product AND pricesPriority.priceList IN(:$priceListsPropertyName) $whereIf2)
			LIMIT 1)" => $direction,
		], [$priceListsPropertyName => $priceListsInString]);
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

	/**
	 * @param string $index
	 * @param array<mixed> $data
	 */
	protected function saveDataCacheIndex(string $index, array $data): void
	{
		$this->cache->save($index, $data, [
			Cache::Tags => [self::PRODUCTS_PROVIDER_CACHE_TAG],
		]);
	}
}
