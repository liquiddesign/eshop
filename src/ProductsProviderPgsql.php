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
use Eshop\DB\ProductPrimaryCategoryRepository;
use Eshop\DB\ProductRepository;
use Eshop\DB\ProductsCacheStateRepository;
use Eshop\DB\RelatedRepository;
use Eshop\DB\RelatedTypeRepository;
use Eshop\DB\VisibilityListItemRepository;
use Eshop\DB\VisibilityListRepository;
use Eshop\Services\ProductsCache\GeneralProductsCacheProvider;
use Nette\Caching\Cache;
use Nette\Caching\Storage;
use Nette\DI\Container;
use Nette\Utils\Arrays;
use Nette\Utils\Strings;
use PgSql\Connection;
use Ramsey\Uuid\Uuid;
use StORM\DIConnection;
use StORM\ICollection;
use Tracy\Debugger;
use Tracy\ILogger;
use Web\DB\SettingRepository;

/**
 * @deprecated Not useful at the moment. Don't delete yet.
 */
class ProductsProviderPgsql implements GeneralProductsCacheProvider
{
	public const PRODUCTS_PROVIDER_CACHE_TAG = 'productsProviderCache';

	/**
	 * Also hard-coded: category, pricelist
	 * @var array<string>
	 */
	protected array $allowedCollectionFilterColumns = [
		'hidden' => 'visibilityList.hidden',
		'hiddenInMenu' => 'visibilityList.hiddeninmenu',
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
		'systemicAttributes.availability' => 'displayamount',
		'systemicAttributes.delivery' => 'displaydelivery',
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

	protected Cache $cache;

	protected Connection|false|null $pgsqlConnection = null;

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
		protected readonly ProductPrimaryCategoryRepository $productPrimaryCategoryRepository,
		protected readonly RelatedRepository $relatedRepository,
		protected readonly RelatedTypeRepository $relatedTypeRepository,
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

		$this->allowedDynamicFilterExpressions['priceFrom'] = function (\stdClass $product, mixed $value, array $visibilityLists, array $priceLists): bool {
			$showVat = $this->shopperUser->getMainPriceType() === 'withVat';

			return $showVat ? $product->pricevat >= $value : $product->price >= $value;
		};

		$this->allowedDynamicFilterExpressions['priceTo'] = function (\stdClass $product, mixed $value, array $visibilityLists, array $priceLists): bool {
			$showVat = $this->shopperUser->getMainPriceType() === 'withVat';

			return $showVat ? $product->pricevat <= $value : $product->price <= $value;
		};


		$this->allowedDynamicFilterExpressions['priceGt'] = function (\stdClass $product, mixed $value, array $visibilityLists, array $priceLists): bool {
			$showVat = $this->shopperUser->getMainPriceType() === 'withVat';

			return $showVat ? $product->pricevat > $value : $product->price > $value;
		};
	}

	public function __destruct()
	{
		if ($this->pgsqlConnection) {
			\pg_close($this->pgsqlConnection);
		}
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
		$postgres = $this->initPgsqlConnection();

		if (!$postgres) {
			return;
		}

		$cacheIndexToBeWarmedUp = $this->getCacheIndexToBeWarmedUp();

		if ($cacheIndexToBeWarmedUp === 0) {
			return;
		}

		$this->cleanCache();

		try {
			$mutationSuffix = $this->connection->getMutationSuffix();

			$this->markCacheAsWarming($cacheIndexToBeWarmedUp);

			/** @var array<object{id: int, ancestor: string}> $allCategories */
			$allCategories = $this->categoryRepository->many()->setSelect(['this.id', 'ancestor' => 'this.fk_ancestor'], keepIndex: true)->fetchArray(\stdClass::class);

			$categoryTables = \pg_query($postgres, "SELECT * FROM pg_tables WHERE schemaname = 'public' AND tablename LIKE 'eshop_categoryproducts_cache_{$cacheIndexToBeWarmedUp}_%';");
			$categoryTables = \pg_fetch_all($categoryTables);

			if ($categoryTables) {
				$categoryTablesNamesToDelete = [];

				foreach ($categoryTables as $category) {
					$categoryTablesNamesToDelete[] = $category['tablename'];
				}

				unset($categoryTables);

				$categoryTablesNamesToDelete = \implode(',', $categoryTablesNamesToDelete);

				\pg_query($postgres, "DROP TABLE $categoryTablesNamesToDelete");
			}

			$productsCacheTableName = "eshop_products_cache_$cacheIndexToBeWarmedUp";

			$relationsCacheTableName = "eshop_products_relations_cache_$cacheIndexToBeWarmedUp";

			\pg_query($postgres, "DROP TABLE IF EXISTS $relationsCacheTableName");

			\pg_query($postgres, "
CREATE TABLE $relationsCacheTableName (
    uuid uuid PRIMARY KEY,
    master INTEGER NOT NULL,
    slave INTEGER NOT NULL,
    priority SMALLINT NOT NULL,
    amount SMALLINT NOT NULL,
    hidden INT2 NOT NULL,
    systemic INT2 NOT NULL,
    discountPct float8,
    masterPct float8,
    type INTEGER NOT NULL
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

			$relationsToInsert = [];
			$counter = 0;

			while ($relation = $relations->fetch(\stdClass::class)) {
				/** @var \stdClass $relation */

				$data = [
					Uuid::uuid7()->toString(),
					$relation->masterId,
					$relation->slaveId,
					$relation->priority,
					$relation->amount,
					$relation->hidden,
					$relation->systemic,
					$relation->discountPct ?: '\N',
					$relation->masterPct ?: '\N',
					$relation->typeId,
				];

				foreach ($data as &$row) {
					$row = \is_string($row) ? \str_replace(["\t", "\n"], '', $row) : $row;
				}

				$relationsToInsert[] = \implode("\t", $data);

				$counter++;

				if ($counter !== 20000) {
					continue;
				}

				$counter = 0;

				\pg_copy_from($postgres, $relationsCacheTableName, $relationsToInsert);
				$relationsToInsert = [];
			}

			\pg_copy_from($postgres, $relationsCacheTableName, $relationsToInsert);
			unset($relationsToInsert);

			$relations->__destruct();
			unset($relations);

			\pg_query($postgres, "CREATE UNIQUE INDEX {$relationsCacheTableName}_idx_unique ON $relationsCacheTableName (master, slave, type);");
//			\pg_query($postgres, "CREATE UNIQUE INDEX {$relationsCacheTableName}_idx_master ON $relationsCacheTableName (master);");
//			\pg_query($postgres, "CREATE UNIQUE INDEX {$relationsCacheTableName}_idx_slave ON $relationsCacheTableName (slave);");
//			\pg_query($postgres, "CREATE UNIQUE INDEX {$relationsCacheTableName}_idx_type ON $relationsCacheTableName (type);");

			\pg_query($postgres, "
DROP TABLE IF EXISTS $productsCacheTableName;
CREATE TABLE $productsCacheTableName (
  product INTEGER PRIMARY KEY,
  producer INTEGER,
  displayAmount INTEGER,
  displayDelivery INTEGER,
  displayAmount_isSold INT2,
  attributeValues TEXT,
  name varchar(255),
  code varchar(255),
  subCode varchar(255),
  externalCode varchar(255),
  ean varchar(255)
		);");

			$allCategoryTypes = $this->categoryTypeRepository->many()->select(['this.id'])->fetchArray(\stdClass::class);

			foreach ($allCategoryTypes as $categoryType) {
				\pg_query($postgres, "ALTER TABLE $productsCacheTableName ADD COLUMN primaryCategory_{$categoryType->id} INTEGER;");
			}

			$allVisibilityLists = $this->visibilityListRepository->many()->select(['this.id'])->fetchArray(\stdClass::class);

			foreach ($allVisibilityLists as $visibilityList) {
				\pg_query($postgres, "ALTER TABLE $productsCacheTableName ADD COLUMN visibilityList_{$visibilityList->id}_hidden INT2;");
				\pg_query($postgres, "ALTER TABLE $productsCacheTableName ADD COLUMN visibilityList_{$visibilityList->id}_hiddenInMenu INT2;");
				\pg_query($postgres, "ALTER TABLE $productsCacheTableName ADD COLUMN visibilityList_{$visibilityList->id}_priority INT2;");
				\pg_query($postgres, "ALTER TABLE $productsCacheTableName ADD COLUMN visibilityList_{$visibilityList->id}_unavailable INT2;");
				\pg_query($postgres, "ALTER TABLE $productsCacheTableName ADD COLUMN visibilityList_{$visibilityList->id}_recommended INT2;");
			}

			$allPriceLists = $this->pricelistRepository->many()->select(['this.id'])->fetchArray(\stdClass::class);

			foreach ($allPriceLists as $priceList) {
				\pg_query($postgres, "ALTER TABLE $productsCacheTableName ADD COLUMN priceList_{$priceList->id}_price float8;");
				\pg_query($postgres, "ALTER TABLE $productsCacheTableName ADD COLUMN priceList_{$priceList->id}_priceVat float8;");
				\pg_query($postgres, "ALTER TABLE $productsCacheTableName ADD COLUMN priceList_{$priceList->id}_priceBefore float8;");
				\pg_query($postgres, "ALTER TABLE $productsCacheTableName ADD COLUMN priceList_{$priceList->id}_priceVatBefore float8;");
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

			/** @var array<object{category: string|null, categoryType: string}> $allProductPrimaryCategories */
			$allProductPrimaryCategories = $this->productPrimaryCategoryRepository->many()
				->join(['eshop_categorytype'], 'this.fk_categoryType = eshop_categorytype.uuid')
				->join(['eshop_category'], 'this.fk_category = eshop_category.uuid')
				->setSelect(['category' => 'eshop_category.id', 'categoryType' => 'eshop_categorytype.id'], keepIndex: true)
				->fetchArray(\stdClass::class);

			$allCategoriesByCategory = [];

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
				->join(['primaryCategory' => 'eshop_productprimarycategory'], 'this.uuid = primaryCategory.fk_product')
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
					'productPrimaryCategoriesPKs' => 'GROUP_CONCAT(DISTINCT primaryCategory.uuid)',
				])
				->where('priceList.isActive', true)
				->where('(discount.validFrom IS NULL OR discount.validFrom <= DATE(now())) AND (discount.validTo IS NULL OR discount.validTo >= DATE(now()))')
				->setGroupBy(['this.id']);

			$products = [];
			$productsByCategories = [];
			$productCounter = 0;

			while ($product = $productsCollection->fetch(\stdClass::class)) {
				/** @var \stdClass $product */

				if (!$prices = $product->pricesPKs) {
					continue;
				}

				$productData = [
					$product->id,
					$product->fkProducer ?: '\N',
					$product->fkDisplayAmount ?: '\N',
					$product->fkDisplayDelivery ?: '\N',
					$product->fkDisplayAmount ? ((int) $allDisplayAmounts[$product->fkDisplayAmount]->isSold) : '\N',
					$product->attributeValuesPKs ?: '\N',
					$product->name ?: '\N',
					$product->code ? "$product->code" : '\N',
					$product->subCode ? "$product->subCode" : '\N',
					$product->externalCode ? "$product->externalCode" : '\N',
					$product->ean ? "$product->ean" : '\N',
				];

				$productPrimaryCategories = [];

				if ($primaryCategories = $product->productPrimaryCategoriesPKs) {
					$primaryCategories = \explode(',', $primaryCategories);

					foreach ($primaryCategories as $primaryCategory) {
						$primaryCategory = $allProductPrimaryCategories[$primaryCategory];

						$productPrimaryCategories[$primaryCategory->categoryType] = $primaryCategory->category;
					}
				}

				foreach ($allCategoryTypes as $categoryType) {
					$productData[] = $productPrimaryCategories[$categoryType->id] ?? '\N';
				}

				$productVisibilityListItems = [];

				if ($visibilityListItems = $product->visibilityListItemsPKs) {
					$visibilityListItems = \explode(',', $visibilityListItems);

					foreach ($visibilityListItems as $visibilityListItem) {
						$visibilityListItem = $allVisibilityListItems[$visibilityListItem];

						$productVisibilityListItems[$visibilityListItem->visibilityListId] = [
							$visibilityListItem->hidden,
							$visibilityListItem->hiddenInMenu,
							$visibilityListItem->priority,
							$visibilityListItem->unavailable,
							$visibilityListItem->recommended,
						];
					}
				}

				foreach ($allVisibilityLists as $visibilityList) {
					$productVisibilityListItem = $productVisibilityListItems[$visibilityList->id] ?? [];

					if ($productVisibilityListItem) {
						$productData = \array_merge($productData, $productVisibilityListItem);

						continue;
					}

					for ($i = 0; $i < 5; $i++) {
						$productData[] = '\N';
					}
				}

				$productPriceListItems = [];
				$priceListItems = \explode(',', $prices);

				foreach ($priceListItems as $priceListItem) {
					if (!isset($allPrices[$priceListItem])) {
						throw new \Exception("Price $priceListItem not found for product $product->id");
					}

					$priceListItem = $allPrices[$priceListItem];

					$productPriceListItems[$priceListItem->priceListId] = [
						$priceListItem->price ?: '\N',
						$priceListItem->priceVat ?: '\N',
						$priceListItem->priceBefore ?: '\N',
						$priceListItem->priceVatBefore ?: '\N',
					];
				}

				foreach ($allPriceLists as $visibilityList) {
					$productPriceListItem = $productPriceListItems[$visibilityList->id] ?? [];

					if ($productPriceListItem) {
						$productData = \array_merge($productData, $productPriceListItem);

						continue;
					}

					for ($i = 0; $i < 4; $i++) {
						$productData[] = '\N';
					}
				}

				if ($categories = $product->categoriesPKs) {
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

				foreach ($productData as &$row) {
					$row = \is_string($row) ? \str_replace(["\t", "\n"], '', $row) : $row;
				}

				$products[] = \implode("\t", $productData);


				$productCounter++;

				if ($productCounter !== 20000) {
					continue;
				}

				$productCounter = 0;

				\pg_copy_from($postgres, $productsCacheTableName, $products);
				$products = [];
			}

			\pg_copy_from($postgres, $productsCacheTableName, $products);
			unset($products);

			$productsCollection->__destruct();

			foreach ($productsByCategories as $category => $products) {
				$categoryId = $allCategories[$category]->id;

				\pg_query($postgres, "DROP TABLE IF EXISTS eshop_categoryproducts_cache_{$cacheIndexToBeWarmedUp}_$categoryId;");
				\pg_query($postgres, "CREATE TABLE eshop_categoryproducts_cache_{$cacheIndexToBeWarmedUp}_$categoryId (product INTEGER PRIMARY KEY);");

				$newRows = [];

				foreach (\array_keys($products) as $product) {
					$newRows[] = $product;
				}

				\pg_copy_from($postgres, "eshop_categoryproducts_cache_{$cacheIndexToBeWarmedUp}_$categoryId", $newRows);

				\pg_query($postgres, "ALTER TABLE eshop_categoryproducts_cache_{$cacheIndexToBeWarmedUp}_$categoryId
ADD CONSTRAINT fk_product
FOREIGN KEY (product)
REFERENCES eshop_products_cache_{$cacheIndexToBeWarmedUp}(product)
ON UPDATE CASCADE
ON DELETE CASCADE;");
			}

			foreach ($allCategoryTypes as $categoryType) {
				\pg_query($postgres, "CREATE INDEX {$productsCacheTableName}_idx_primaryCategory_{$categoryType->id} ON $productsCacheTableName (primaryCategory_{$categoryType->id});");
			}

			\pg_query($postgres, "CREATE INDEX {$productsCacheTableName}_idx_producer ON $productsCacheTableName (producer);");
			\pg_query($postgres, "CREATE INDEX {$productsCacheTableName}_idx_displayAmount ON $productsCacheTableName (displayAmount);");
			\pg_query($postgres, "CREATE INDEX {$productsCacheTableName}_idx_displayDelivery ON $productsCacheTableName (displayDelivery);");
			\pg_query($postgres, "CREATE INDEX {$productsCacheTableName}_idx_displayAmount_isSold ON $productsCacheTableName (displayAmount_isSold);");
			\pg_query($postgres, "CREATE INDEX {$productsCacheTableName}_idx_subCode ON $productsCacheTableName (subCode);");
			\pg_query($postgres, "CREATE INDEX {$productsCacheTableName}_idx_externalCode ON $productsCacheTableName (externalCode);");

			\pg_query($postgres, "CREATE UNIQUE INDEX {$productsCacheTableName}_idx_unique_code ON $productsCacheTableName (code);");
			\pg_query($postgres, "CREATE UNIQUE INDEX {$productsCacheTableName}_idx_unique_ean ON $productsCacheTableName (ean);");

			foreach ($allVisibilityLists as $visibilityList) {
				\pg_query(
					$postgres,
					"CREATE INDEX {$productsCacheTableName}_idx_visibilityList_{$visibilityList->id}_hidden ON $productsCacheTableName (visibilityList_{$visibilityList->id}_hidden);",
				);
				\pg_query(
					$postgres,
					"CREATE INDEX {$productsCacheTableName}_idx_visibilityList_{$visibilityList->id}_hiddenInMenu ON $productsCacheTableName (visibilityList_{$visibilityList->id}_hiddenInMenu);",
				);
				\pg_query(
					$postgres,
					"CREATE INDEX {$productsCacheTableName}_idx_visibilityList_{$visibilityList->id}_priority ON $productsCacheTableName (visibilityList_{$visibilityList->id}_priority);",
				);
				\pg_query(
					$postgres,
					"CREATE INDEX {$productsCacheTableName}_idx_visibilityList_{$visibilityList->id}_unavailable ON $productsCacheTableName (visibilityList_{$visibilityList->id}_unavailable);",
				);
				\pg_query(
					$postgres,
					"CREATE INDEX {$productsCacheTableName}_idx_visibilityList_{$visibilityList->id}_recommended ON $productsCacheTableName (visibilityList_{$visibilityList->id}_recommended);",
				);
			}

			foreach ($allPriceLists as $priceList) {
				\pg_query($postgres, "CREATE INDEX {$productsCacheTableName}_idx_priceList_{$priceList->id}_price ON $productsCacheTableName (priceList_{$priceList->id}_price);");
				\pg_query($postgres, "CREATE INDEX {$productsCacheTableName}_idx_priceList_{$priceList->id}_priceVat ON $productsCacheTableName (priceList_{$priceList->id}_priceVat);");
				\pg_query($postgres, "CREATE INDEX {$productsCacheTableName}_idx_priceList_{$priceList->id}_priceBefore ON $productsCacheTableName (priceList_{$priceList->id}_priceBefore);");
				\pg_query($postgres, "CREATE INDEX {$productsCacheTableName}_idx_priceList_{$priceList->id}_priceVatBefore ON $productsCacheTableName (priceList_{$priceList->id}_priceVatBefore);");
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
	 * @inheritDoc
	 */
	public function getProductsFromCacheTable(
		array $filters,
		string|null $orderByName = null,
		string $orderByDirection = 'ASC',
		array $priceLists = [],
		array $visibilityLists = [],
	): array|false {
		$postgres = $this->initPgsqlConnection();

		if (!$postgres) {
			return false;
		}

		$cacheIndex = $this->getCacheIndexToBeUsed();

		if ($cacheIndex === 0) {
			return false;
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
			$categoryTableExists = \pg_query($postgres, "SELECT * FROM pg_tables WHERE schemaname = 'public' AND tablename = 'eshop_categoryproducts_cache_{$cacheIndex}_$category->id';");
			$categoryTableExists = \pg_fetch_assoc($categoryTableExists);

			if ($categoryTableExists === false) {
				return $emptyResult;
			}
		}

		$productsCollection = $category ?
			$this->connection->rows(['category' => "eshop_categoryproducts_cache_{$cacheIndex}_$category->id"])
				->join(['this' => "eshop_products_cache_{$cacheIndex}"], 'this.product = category.product', type: 'INNER') :
			$this->connection->rows(['this' => "eshop_products_cache_{$cacheIndex}"]);

		if (isset($filters['pricelist'])) {
			$priceLists = \array_filter($priceLists, fn($priceList) => Arrays::contains($filters['pricelist'], $priceList), \ARRAY_FILTER_USE_KEY);

			unset($filters['pricelist']);
		}

		$productsCollection->setSelect([
			'product' => 'this.product',
			'producer' => 'this.producer',
			'attributeValues' => 'this.attributeValues',
			'displayAmount' => 'this.displayAmount',
			'displayDelivery' => 'this.displayDelivery',
			'price' => $this->createCoalesceFromArray($priceLists, 'priceList', 'price'),
			'priceVat' => $this->createCoalesceFromArray($priceLists, 'priceList', 'priceVat'),
		]);

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

			throw new \Exception("Filter '$filter' is not supported by ProductsCacheProvider! You can add it manually with 'addAllowedFilterColumn' or 'addFilterExpression' functions.");
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
				throw new \Exception("Order '$orderByName' is not supported by ProductsCacheProvider! You can add it manually with 'addAllowedOrderColumn' or 'addOrderExpression' function.");
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

		$sql = $productsCollection->getSql();
		$vars = [];
		$i = 1;

		foreach ($productsCollection->getVars() as $varKey => $varValue) {
			$sql = \str_replace(":$varKey", "$$i", $sql);
			$i++;
			$vars[] = $varValue;
		}

		Debugger::dump($sql);
		Debugger::dump($vars);

		Debugger::dump(Debugger::timer('pg_fetch'));
		/** @var \PgSql\Result $productsPgsqlQuery */
		$productsPgsqlQuery = \pg_query_params($postgres, $sql, $vars);
		Debugger::dump(Debugger::timer('pg_fetch'));

		while ($product = \pg_fetch_object($productsPgsqlQuery)) {
			$attributeValues = $product->attributevalues ? \array_flip(\explode(',', $product->attributevalues)) : [];

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

					if ($product->pricevat < $priceVatMin) {
						$priceVatMin = $product->pricevat;
					}
				}

				if ($filter === 'priceTo') {
					$dynamicallyCountedDynamicFilters[$filter] = true;

					if ($product->price > $priceMax) {
						$priceMax = $product->price;
					}

					if ($product->pricevat > $priceVatMax) {
						$priceVatMax = $product->pricevat;
					}
				}

				if ($filter === 'systemicAttributes.availability' && $product->displayamount) {
					$dynamicallyCountedDynamicFilters[$filter] = true;

					$displayAmountsCounts[$product->displayamount] = ($displayAmountsCounts[$product->displayamount] ?? 0) + 1;
				}

				if ($filter === 'systemicAttributes.delivery' && $product->displaydelivery) {
					$dynamicallyCountedDynamicFilters[$filter] = true;

					$displayDeliveriesCounts[$product->displaydelivery] = ($displayDeliveriesCounts[$product->displaydelivery] ?? 0) + 1;
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

			if (!isset($dynamicallyCountedDynamicFilters['systemicAttributes.availability']) && $product->displayamount) {
				$displayAmountsCounts[$product->displayamount] = ($displayAmountsCounts[$product->displayamount] ?? 0) + 1;
			}

			if (!isset($dynamicallyCountedDynamicFilters['systemicAttributes.delivery']) && $product->displaydelivery) {
				$displayDeliveriesCounts[$product->displaydelivery] = ($displayDeliveriesCounts[$product->displaydelivery] ?? 0) + 1;
			}

			if (!isset($dynamicallyCountedDynamicFilters['systemicAttributes.producer']) && $product->producer) {
				$producersCounts[$product->producer] = ($producersCounts[$product->producer] ?? 0) + 1;
			}

			if (!isset($dynamicallyCountedDynamicFilters['priceFrom'])) {
				if ($product->price < $priceMin) {
					$priceMin = $product->price;
				}

				if ($product->pricevat < $priceVatMin) {
					$priceVatMin = $product->pricevat;
				}
			}

			if (!isset($dynamicallyCountedDynamicFilters['priceTo'])) {
				if ($product->price > $priceMax) {
					$priceMax = $product->price;
				}

				if ($product->pricevat > $priceVatMax) {
					$priceVatMax = $product->pricevat;
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

	protected function initPgsqlConnection(): Connection|false
	{
		if ($this->pgsqlConnection === false) {
			return false;
		}

		if ($this->pgsqlConnection === null) {
			$this->pgsqlConnection = \pg_connect('host=localhost port=5432 dbname=abel user=postgres password=postgres');
		}

		return $this->pgsqlConnection;
	}
}