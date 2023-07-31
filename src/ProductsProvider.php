<?php

namespace Eshop;

use Base\ShopsConfig;
use Eshop\DB\AttributeValueRepository;
use Eshop\DB\CategoryRepository;
use Eshop\DB\CategoryTypeRepository;
use Eshop\DB\DisplayAmountRepository;
use Eshop\DB\PricelistRepository;
use Eshop\DB\PriceRepository;
use Eshop\DB\ProductRepository;
use Eshop\DB\VisibilityListItemRepository;
use Eshop\DB\VisibilityListRepository;
use Nette\DI\Container;
use StORM\Connection;
use Tracy\Debugger;
use Web\DB\SettingRepository;

class ProductsProvider
{
	public const CATEGORY_COLUMNS_COUNT = 20;

	protected const BASE_TABLE_NAME = 'eshop_productsprovidercache';

	protected const CACHE_STATE_TABLE_NAME = self::BASE_TABLE_NAME . '_state';

	public function __construct(
		protected readonly ProductRepository $productRepository,
		protected readonly CategoryRepository $categoryRepository,
		protected readonly PriceRepository $priceRepository,
		protected readonly PricelistRepository $pricelistRepository,
		protected readonly Container $container,
		protected readonly Connection $connection,
		protected readonly ShopsConfig $shopsConfig,
		protected readonly CategoryTypeRepository $categoryTypeRepository,
		protected readonly SettingRepository $settingRepository,
		protected readonly VisibilityListItemRepository $visibilityListItemRepository,
		protected readonly AttributeValueRepository $attributeValueRepository,
		protected readonly DisplayAmountRepository $displayAmountRepository,
		protected readonly VisibilityListRepository $visibilityListRepository,
	) {
	}

	public function warmUpCacheTable(): void
	{
		Debugger::timer();

		// Cache states: 0-not ready, available to be warmedUp, 1-not ready, warmingUp currently, 2-ready

		$link = $this->connection->getLink();

		$cacheStateTableName = self::CACHE_STATE_TABLE_NAME;

		$link->exec("CREATE TABLE IF NOT EXISTS `$cacheStateTableName` (id varchar(32) primary key, value tinyint);");

		$cacheStates = $this->connection->rows([$cacheStateTableName])->setIndex('id')->toArray();

		if (\count($cacheStates) !== 2) {
			$link->exec("TRUNCATE TABLE $cacheStateTableName");
			$this->connection->createRow($cacheStateTableName, ['id' => 'ready_index', 'value' => null]);
			$this->connection->createRow($cacheStateTableName, ['id' => 'warming_index', 'value' => null]);

			$cacheStates = $this->connection->rows([$cacheStateTableName])->setIndex('id')->toArray();
		}

		\dump($cacheStates);
		$readyIndex = $cacheStates['ready_index']->value;
		$warmingIndex = $cacheStates['warming_index']->value;

		if ($warmingIndex === null) {
			return;
		}

		$this->connection->syncRow($cacheStateTableName, ['id' => 'warming_index', 'value' => $readyIndex === 1 ? 2 : 1]);

		die();

		$allCategories = $this->categoryRepository->many()->toArray();

		foreach ($allCategories as $category) {
			$link->exec("DROP TABLE IF EXISTS `eshop_categoryproducts_cache_$category->id`");
		}

		$link->exec('
DROP TABLE IF EXISTS `eshop_products_cache`;
CREATE TABLE `eshop_products_cache` (
  product INT UNSIGNED PRIMARY KEY,
  producer INT UNSIGNED,
  displayAmount INT UNSIGNED,
  displayAmount_isSold TINYINT(1),
  attributeValues TEXT,
  INDEX idx_producer (producer),
  INDEX idx_displayAmount (displayAmount),
  INDEX idx_displayAmount_isSold (displayAmount_isSold)
);');

		foreach ($this->visibilityListRepository->many() as $visibilityList) {
			$link->exec("ALTER TABLE `eshop_products_cache` ADD COLUMN visibilityList_{$visibilityList->id} INT UNSIGNED DEFAULT('{$visibilityList->id}');");
			$link->exec("ALTER TABLE `eshop_products_cache` ADD INDEX idx_visibilityList_{$visibilityList->id} (visibilityList_{$visibilityList->id});");

			$link->exec("ALTER TABLE `eshop_products_cache` ADD COLUMN visibilityList_{$visibilityList->id}_hidden TINYINT;");
			$link->exec("ALTER TABLE `eshop_products_cache` ADD COLUMN visibilityList_{$visibilityList->id}_hiddenInMenu TINYINT;");
			$link->exec("ALTER TABLE `eshop_products_cache` ADD COLUMN visibilityList_{$visibilityList->id}_priority SMALLINT;");
			$link->exec("ALTER TABLE `eshop_products_cache` ADD COLUMN visibilityList_{$visibilityList->id}_unavailable TINYINT;");
			$link->exec("ALTER TABLE `eshop_products_cache` ADD COLUMN visibilityList_{$visibilityList->id}_recommended TINYINT;");
		}

		foreach ($this->pricelistRepository->many() as $priceList) {
			$link->exec("ALTER TABLE `eshop_products_cache` ADD COLUMN priceList_{$priceList->id} INT UNSIGNED DEFAULT('{$priceList->id}');");

			$link->exec("ALTER TABLE `eshop_products_cache` ADD COLUMN priceList_{$priceList->id}_price DOUBLE;");
			$link->exec("ALTER TABLE `eshop_products_cache` ADD COLUMN priceList_{$priceList->id}_priceVat DOUBLE;");
			$link->exec("ALTER TABLE `eshop_products_cache` ADD COLUMN priceList_{$priceList->id}_priceBefore DOUBLE;");
			$link->exec("ALTER TABLE `eshop_products_cache` ADD COLUMN priceList_{$priceList->id}_priceVatBefore DOUBLE;");
		}

		Debugger::dump('drop/create tables');
		Debugger::dump(Debugger::timer());

		$allPrices = $this->priceRepository->many()->toArray();
		$allPriceLists = $this->pricelistRepository->many()->toArray();
		$allVisibilityLists = $this->visibilityListRepository->many()->toArray();
		$allVisibilityListItems = $this->visibilityListItemRepository->many()->toArray();
		$allDisplayAmounts = $this->displayAmountRepository->many()->setIndex('id')->toArray();
		$allCategoriesByCategory = [];

		$this->connection->getLink()->exec('SET SESSION group_concat_max_len=4294967295');

		$productsByCategories = [];
		$products = [];

		$productsCollection = $this->productRepository->many()
			->join(['price' => 'eshop_price'], 'this.uuid = price.fk_product', type: 'INNER')
			->join(['priceList' => 'eshop_pricelist'], 'price.fk_pricelist = priceList.uuid')
			->join(['discount' => 'eshop_discount'], 'priceList.fk_discount = discount.uuid')
			->join(['eshop_product_nxn_eshop_category'], 'this.uuid = eshop_product_nxn_eshop_category.fk_product')
//			->join(['eshop_category'], 'eshop_product_nxn_eshop_category.fk_category = eshop_category.uuid')
			->join(['visibilityListItem' => 'eshop_visibilitylistitem'], 'visibilityListItem.fk_product = this.uuid', type: 'INNER')
			->join(['visibilityList' => 'eshop_visibilitylist'], 'visibilityListItem.fk_visibilityList = visibilityList.uuid')
			->join(['assign' => 'eshop_attributeassign'], 'this.uuid = assign.fk_product')
			->join(['eshop_displayamount'], 'this.fk_displayAmount = eshop_displayamount.uuid')
			->join(['eshop_producer'], 'this.fk_producer = eshop_producer.uuid')
			->join(['eshop_attributevalue'], 'assign.fk_value = eshop_attributevalue.uuid')
			->setSelect([
				'id' => 'this.id',
				'fkDisplayAmount' => 'eshop_displayamount.id',
				'fkProducer' => 'eshop_producer.id',
				'pricesPKs' => 'GROUP_CONCAT(DISTINCT price.uuid ORDER BY priceList.priority)',
				'categoriesPKs' => 'GROUP_CONCAT(DISTINCT eshop_product_nxn_eshop_category.fk_category)',
				'visibilityListItemsPKs' => 'GROUP_CONCAT(DISTINCT visibilityListItem.uuid ORDER BY visibilityList.priority)',
				'attributeValuesPKs' => 'GROUP_CONCAT(DISTINCT eshop_attributevalue.id)',
			])
			->where('priceList.isActive', true)
			->where('(discount.validFrom IS NULL OR discount.validFrom <= DATE(now())) AND (discount.validTo IS NULL OR discount.validTo >= DATE(now()))')
			->setGroupBy(['this.id']);

		Debugger::dump('load entities');
		Debugger::dump(Debugger::timer());

		while ($product = $productsCollection->fetch(\stdClass::class)) {
			/** @var \stdClass $product */

			if (!$prices = $product->pricesPKs) {
				continue;
			}

			$products[$product->id] = [
				'product' => $product->id,
				'displayAmount' => $product->fkDisplayAmount,
				'displayAmount_isSold' => $product->fkDisplayAmount ? $allDisplayAmounts[$product->fkDisplayAmount]->isSold : null,
				'producer' => $product->fkProducer,
			];

//			for ($i = 0; $i < self::CATEGORY_COLUMNS_COUNT; $i++) {
//				$products[$product->id]["category_$i"] = null;
//			}

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

//				$i = 0;
//
//				foreach ($products[$product->id]['categories'] as $productCategory) {
//					$products[$product->id]["category_$i"] = $allCategories[$productCategory]->id;
//
//					$i++;
//				}
			}

			unset($products[$product->id]['categories']);

			if ($visibilityListItems = $product->visibilityListItemsPKs) {
				$visibilityListItems = \explode(',', $visibilityListItems);

				foreach ($visibilityListItems as $visibilityListItem) {
					$visibilityListItem = $allVisibilityListItems[$visibilityListItem];

					$products[$product->id]["visibilityList_{$visibilityListItem->visibilityList->id}_hidden"] = $visibilityListItem->hidden;
					$products[$product->id]["visibilityList_{$visibilityListItem->visibilityList->id}_hiddenInMenu"] = $visibilityListItem->hiddenInMenu;
					$products[$product->id]["visibilityList_{$visibilityListItem->visibilityList->id}_priority"] = $visibilityListItem->priority;
					$products[$product->id]["visibilityList_{$visibilityListItem->visibilityList->id}_unavailable"] = $visibilityListItem->unavailable;
					$products[$product->id]["visibilityList_{$visibilityListItem->visibilityList->id}_recommended"] = $visibilityListItem->recommended;
				}
			}

			$prices = \explode(',', $prices);

			foreach ($prices as $price) {
				$price = $allPrices[$price];

				$products[$product->id]["priceList_{$price->pricelist->id}_price"] = $price->price;
				$products[$product->id]["priceList_{$price->pricelist->id}_priceVat"] = $price->priceVat;
				$products[$product->id]["priceList_{$price->pricelist->id}_priceBefore"] = $price->priceBefore;
				$products[$product->id]["priceList_{$price->pricelist->id}_priceVatBefore"] = $price->priceVatBefore;
			}

			$products[$product->id]['attributeValues'] = $product->attributeValuesPKs;
		}

		Debugger::dump('main loop');
		Debugger::dump(Debugger::timer());

		$this->connection->createRows('eshop_products_cache', $products, chunkSize: 100000);

		Debugger::dump('insert products');
		Debugger::dump(Debugger::timer());

		foreach ($productsByCategories as $category => $products) {
			$categoryId = $allCategories[$category]->id;

			$link->exec("DROP TABLE IF EXISTS `eshop_categoryproducts_cache_$categoryId`;
CREATE TABLE `eshop_categoryproducts_cache_$categoryId` (
  product INT UNSIGNED PRIMARY KEY,
  FOREIGN KEY (product) REFERENCES eshop_products_cache(product) ON UPDATE CASCADE ON DELETE CASCADE 
);");

			foreach (\array_keys($products) as $product) {
				$this->connection->createRow("eshop_categoryproducts_cache_$categoryId", ['product' => $product]);
			}
		}

		Debugger::dump('create/insert categories tables');
		Debugger::dump(Debugger::timer());
	}

	/**
	 * @param array<mixed> $filters
	 * @param string|null $orderByName
	 * @param 'ASC'|'DESC' $orderByDirection Works only if $orderByName is not null
	 * @param array<string, \Eshop\DB\Pricelist> $priceLists
	 * @param array<string, \Eshop\DB\VisibilityList> $visibilityLists
	 * @return array{
	 *     "productPKs": list<string>,
	 *     "attributeValuesCounts": array<string, int>,
	 *     "displayAmountsCounts": array<string, int>,
	 *     "producersCounts": array<string, int>
	 * }|false
	 * @throws \StORM\Exception\NotFoundException
	 */
	public function getProductsFromCacheTable(
		array $filters,
		string|null $orderByName = null,
		string $orderByDirection = 'ASC',
		array $priceLists = [],
		array $visibilityLists = []
	): array|false {
		unset($orderByName);
		unset($orderByDirection);

		$category = isset($filters['category']) ? $this->categoryRepository->many()->where('this.path', $filters['category'])->first(true) : null;

//		$categoriesWhereString = null;

//		for ($i = 0; $i < self::CATEGORY_COLUMNS_COUNT; $i++) {
//			$categoriesWhereString .= "category_$i = :category OR ";
//		}

//		$categoriesWhereString = Strings::subString($categoriesWhereString, 0, -4);

		if ($category) {
			$productsCollection = $this->connection->rows(['category' => "eshop_categoryproducts_cache_$category->id"])->join(['this' => 'eshop_products_cache'], 'this.product = category.product', type: 'INNER');
		} else {
			$productsCollection = $this->connection->rows(['this' => 'eshop_products_cache']);
		}

		$productsCollection->setSelect([
				'product' => 'this.product',
				'producer' => 'this.producer',
				'attributeValues' => 'this.attributeValues',
				'displayAmount' => 'this.displayAmount',
			])
			->where($this->createCoalesceFromArray($visibilityLists, 'visibilityList_', '_hidden') . ' = 0')
			->where($this->createCoalesceFromArray($priceLists, 'priceList_', '_price') . ' > 0')
			->orderBy([
				$this->createCoalesceFromArray($visibilityLists, 'visibilityList_', '_priority') => 'ASC',
				'case COALESCE(displayAmount_isSold, 2)
					 when 0 then 0
					 when 1 then 1
					 when 2 then 2
					 else 2 end' => 'ASC',
				$this->createCoalesceFromArray($priceLists, 'priceList_', '_price') => 'ASC',
			]);

//		if ($category) {
//			$productsCollection->where($categoriesWhereString, ['category' => $category->id]);
//		}

		$productPKs = [];
		$displayAmountsCounts = [];
		$producersCounts = [];
		$attributeValuesCounts = [];

		DevelTools::dumpCollection($productsCollection);

		while ($product = $productsCollection->fetch()) {
			$productPKs[] = $product->product;

			if ($product->displayAmount) {
				$displayAmountsCounts[$product->displayAmount] = ($displayAmountsCounts[$product->displayAmount] ?? 0) + 1;
			}

			if ($product->producer) {
				$producersCounts[$product->producer] = ($producersCounts[$product->producer] ?? 0) + 1;
			}

			if (!$product->attributeValues) {
				continue;
			}

			foreach (\explode(',', $product->attributeValues) as $attributeValue) {
				if (!$attributeValue) {
					continue;
				}

				$attributeValuesCounts[$attributeValue] = ($attributeValuesCounts[$attributeValue] ?? 0) + 1;
			}
		}

		return [
			'productPKs' => $productPKs,
			'attributeValuesCounts' => $attributeValuesCounts,
			'displayAmountsCounts' => $displayAmountsCounts,
			'producersCounts' => $producersCounts,
		];
	}

	/**
	 * @param string $category
	 * @param array<\Eshop\DB\Category> $allCategories
	 * @return array<string>
	 */
	protected function getAncestorsOfCategory(string $category, array $allCategories): array
	{
		$categories = [];

		while ($ancestor = $allCategories[$category]->getValue('ancestor')) {
			$categories[] = $ancestor;
			$category = $ancestor;
		}

		return $categories;
	}

	/**
	 * @param array $values
	 */
	protected function createCoalesceFromArray(array $values, string|null $prefix = null, string|null $suffix = null): string
	{
		return $values ? ('COALESCE(' . \implode(',', \array_map(function ($item) use ($prefix, $suffix): string {
				return $prefix . $item->id . $suffix;
		}, $values)) . ')') : 'NULL';
	}
}
