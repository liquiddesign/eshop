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
use Eshop\DB\ProductsCacheStateRepository;
use Eshop\DB\VisibilityListItemRepository;
use Eshop\DB\VisibilityListRepository;
use Nette\DI\Container;
use Nette\Utils\Arrays;
use Nette\Utils\Strings;
use StORM\DIConnection;
use StORM\ICollection;
use Tracy\Debugger;
use Web\DB\SettingRepository;

class ProductsProvider
{
	/**
	 * Also hard-coded: category, pricelist
	 * @var array<string>
	 */
	protected array $allowedFilterColumns = [
		'hidden' => 'visibilityList.hidden',
		'hiddenInMenu' => 'visibilityList.hiddenInMenu',
		'priority' => 'visibilityList.priority',
		'recommended' => 'visibilityList.recommended',
		'unavailable' => 'visibilityList.unavailable',
		'name' => 'name',
		'producer' => 'producer',
		'displayAmount' => 'displayAmount',
		'isSold' => 'displayAmount_isSold',
		'price' => 'priceList.price',
	];

	/**
	 * @var array<string>
	 */
	protected array $allowedFilterExpressions = [];

	/**
	 * @var array<string>
	 */
	protected array $allowedOrderColumns = [
		'priority' => 'visibilityList.priority',
		'price' => 'priceList.price',
		'name' => 'name',
	];

	/**
	 * @var array<callable(\StORM\ICollection $productsCollection, 'ASC'|'DESC' $direction, array $visibilityLists, array $priceLists): void>
	 */
	protected array $allowedOrderExpressions = [];

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
	) {
		$this->allowedOrderExpressions['availabilityAndPrice'] = function (ICollection $productsCollection, string $direction, array $visibilityLists, array $priceLists): void {
			$productsCollection->orderBy([
				'case COALESCE(displayAmount_isSold, 2)
					 when 0 then 0
					 when 2 then 1
					 when 1 then 2
					 else 2 end' => $direction,
				$this->createCoalesceFromArray($priceLists, 'priceList', 'price') => $direction,
			]);
		};

		$this->allowedOrderExpressions['priorityAvailabilityPrice'] = function (ICollection $productsCollection, string $direction, array $visibilityLists, array $priceLists): void {
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
	}

	public function addAllowedFilterColumn(string $name, string $column): void
	{
		$this->allowedFilterColumns[$name] = $column;
	}

	public function addFilterExpression(string $name, callable $callback): void
	{
		$this->allowedFilterExpressions[$name] = $callback;
	}

	public function addAllowedOrderColumn(string $name, string $column): void
	{
		$this->allowedOrderColumns[$name] = $column;
	}

	public function addOrderExpression(string $name, callable $callback): void
	{
		$this->allowedOrderExpressions[$name] = $callback;
	}

	public function warmUpCacheTable(): void
	{
		$this->resetHangingStatesOfCaches();

		Debugger::timer();

		$link = $this->connection->getLink();
		$mutationSuffix = $this->connection->getMutationSuffix();

		$cacheIndexToBeWarmedUp = $this->getCacheIndexToBeWarmedUp();

		if ($cacheIndexToBeWarmedUp === 0) {
			return;
		}

		$this->markCacheAsWarming($cacheIndexToBeWarmedUp);

		$allCategories = $this->categoryRepository->many()->select(['this.id'])->toArray();

		foreach ($allCategories as $category) {
			$link->exec("DROP TABLE IF EXISTS `eshop_categoryproducts_cache_{$cacheIndexToBeWarmedUp}_$category->id`");
		}

		$productsCacheTableName = "eshop_products_cache_$cacheIndexToBeWarmedUp";

		$link->exec("
DROP TABLE IF EXISTS `$productsCacheTableName`;
CREATE TABLE `$productsCacheTableName` (
  product INT UNSIGNED PRIMARY KEY,
  producer INT UNSIGNED,
  displayAmount INT UNSIGNED,
  displayAmount_isSold TINYINT(1),
  attributeValues TEXT,
  name TEXT,
  INDEX idx_producer (producer),
  INDEX idx_displayAmount (displayAmount),
  INDEX idx_displayAmount_isSold (displayAmount_isSold)
);");

		foreach ($allVisibilityLists = $this->visibilityListRepository->many()->select(['this.id']) as $visibilityList) {
			$link->exec("ALTER TABLE `$productsCacheTableName` ADD COLUMN visibilityList_{$visibilityList->id} INT UNSIGNED DEFAULT('{$visibilityList->id}');");
			$link->exec("ALTER TABLE `$productsCacheTableName` ADD INDEX idx_visibilityList_{$visibilityList->id} (visibilityList_{$visibilityList->id});");

			$link->exec("ALTER TABLE `$productsCacheTableName` ADD COLUMN visibilityList_{$visibilityList->id}_hidden TINYINT;");
			$link->exec("ALTER TABLE `$productsCacheTableName` ADD COLUMN visibilityList_{$visibilityList->id}_hiddenInMenu TINYINT;");
			$link->exec("ALTER TABLE `$productsCacheTableName` ADD COLUMN visibilityList_{$visibilityList->id}_priority SMALLINT;");
			$link->exec("ALTER TABLE `$productsCacheTableName` ADD COLUMN visibilityList_{$visibilityList->id}_unavailable TINYINT;");
			$link->exec("ALTER TABLE `$productsCacheTableName` ADD COLUMN visibilityList_{$visibilityList->id}_recommended TINYINT;");
		}

		foreach ($allPriceLists = $this->pricelistRepository->many()->select(['this.id']) as $priceList) {
			$link->exec("ALTER TABLE `$productsCacheTableName` ADD COLUMN priceList_{$priceList->id} INT UNSIGNED DEFAULT('{$priceList->id}');");

			$link->exec("ALTER TABLE `$productsCacheTableName` ADD COLUMN priceList_{$priceList->id}_price DOUBLE;");
			$link->exec("ALTER TABLE `$productsCacheTableName` ADD COLUMN priceList_{$priceList->id}_priceVat DOUBLE;");
			$link->exec("ALTER TABLE `$productsCacheTableName` ADD COLUMN priceList_{$priceList->id}_priceBefore DOUBLE;");
			$link->exec("ALTER TABLE `$productsCacheTableName` ADD COLUMN priceList_{$priceList->id}_priceVatBefore DOUBLE;");
		}

		Debugger::dump('drop/create tables');
		Debugger::dump(Debugger::timer());

		$allPrices = $this->priceRepository->many()->toArray();
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
//          ->join(['eshop_category'], 'eshop_product_nxn_eshop_category.fk_category = eshop_category.uuid')
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
				'name' => "this.name$mutationSuffix",
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

		$link->beginTransaction();

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
				'name' => $product->name,
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

					$products[$product->id]["visibilityList_{$allVisibilityLists[$visibilityListItem->getValue('visibilityList')]->id}_hidden"] = $visibilityListItem->hidden;
					$products[$product->id]["visibilityList_{$allVisibilityLists[$visibilityListItem->getValue('visibilityList')]->id}_hiddenInMenu"] = $visibilityListItem->hiddenInMenu;
					$products[$product->id]["visibilityList_{$allVisibilityLists[$visibilityListItem->getValue('visibilityList')]->id}_priority"] = $visibilityListItem->priority;
					$products[$product->id]["visibilityList_{$allVisibilityLists[$visibilityListItem->getValue('visibilityList')]->id}_unavailable"] = $visibilityListItem->unavailable;
					$products[$product->id]["visibilityList_{$allVisibilityLists[$visibilityListItem->getValue('visibilityList')]->id}_recommended"] = $visibilityListItem->recommended;
				}
			}

			$prices = \explode(',', $prices);

			foreach ($prices as $price) {
				$price = $allPrices[$price];

				$products[$product->id]["priceList_{$allPriceLists[$price->getValue('pricelist')]->id}_price"] = $price->price;
				$products[$product->id]["priceList_{$allPriceLists[$price->getValue('pricelist')]->id}_priceVat"] = $price->priceVat;
				$products[$product->id]["priceList_{$allPriceLists[$price->getValue('pricelist')]->id}_priceBefore"] = $price->priceBefore;
				$products[$product->id]["priceList_{$allPriceLists[$price->getValue('pricelist')]->id}_priceVatBefore"] = $price->priceVatBefore;
			}

			$products[$product->id]['attributeValues'] = $product->attributeValuesPKs;
		}

		Debugger::dump('main loop');
		Debugger::dump(Debugger::timer());

		$this->connection->createRows("$productsCacheTableName", $products, chunkSize: 10000);

		Debugger::dump('insert products');
		Debugger::dump(Debugger::timer());

		$link->commit();

		Debugger::dump('commit transaction');
		Debugger::dump(Debugger::timer());

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

		Debugger::dump('create/insert categories tables');
		Debugger::dump(Debugger::timer());

		$this->markCacheAsReady($cacheIndexToBeWarmedUp);
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
		array $visibilityLists = [],
	): array|false {
		$category = isset($filters['category']) ? $this->categoryRepository->many()->select(['this.id'])->where('this.path', $filters['category'])->first(true) : null;
		unset($filters['category']);

		$cacheIndex = $this->getCacheIndexToBeUsed();

		$productsCollection = $category ?
			$this->connection->rows(['category' => "eshop_categoryproducts_cache_{$cacheIndex}_$category->id"])
				->join(['this' => "eshop_products_cache_{$cacheIndex}"], 'this.product = category.product', type: 'INNER') :
			$this->connection->rows(['this' => "eshop_products_cache_{$cacheIndex}"]);

		$productsCollection->setSelect([
			'product' => 'this.product',
			'producer' => 'this.producer',
			'attributeValues' => 'this.attributeValues',
			'displayAmount' => 'this.displayAmount',
		]);

		if (isset($filters['pricelist'])) {
			$priceLists = \array_filter($priceLists, fn($priceList) => Arrays::contains($filters['pricelist'], $priceList), \ARRAY_FILTER_USE_KEY);

			unset($filters['pricelist']);
		}

		foreach ($filters as $filter => $value) {
			if (isset($this->allowedFilterColumns[$filter])) {
				$filterColumn = $this->allowedFilterColumns[$filter];

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

			if (isset($this->allowedFilterExpressions[$filter])) {
				$this->allowedFilterExpressions[$filter]($productsCollection, $value, $visibilityLists, $priceLists);

				continue;
			}

			throw new \Exception("Filter '$filter' is not supported by ProductsProvider! You can add it manually with 'addAllowedFilterColumn' or 'addFilterExpression' function.");
		}

		$productsCollection->where($this->createCoalesceFromArray($priceLists, 'priceList', 'price') . ' > 0');

		if ($orderByName) {
			if (isset($this->allowedOrderColumns[$orderByName])) {
				$orderColumn = $this->allowedOrderColumns[$orderByName];

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
			} elseif (isset($this->allowedOrderExpressions[$orderByName])) {
				$this->allowedOrderExpressions[$orderByName]($productsCollection, $orderByDirection, $visibilityLists, $priceLists);
			} else {
				throw new \Exception("Order '$orderByName' is not supported by ProductsProvider! You can add it manually with 'addAllowedOrderColumn' or 'addOrderExpression' function.");
			}
		}

		$productPKs = [];
		$displayAmountsCounts = [];
		$producersCounts = [];
		$attributeValuesCounts = [];

		DevelTools::dumpCollection($productsCollection);

		$debug = false;

		while ($product = $productsCollection->fetch()) {
			if (!$debug) {
				Debugger::dump($this->connection->getLastLogItem()->getTotalTime());

				$debug = true;
			}

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

	protected function resetHangingStatesOfCaches(): void
	{
		//@TODO Check timestamps of caches in warming state. If its too long, reset to empty state.
	}

	protected function markCacheAsWarming(int $id): void
	{
		$this->productsCacheStateRepository->many()->where('this.uuid', $id)->update(['state' => 'warming']);
	}

	protected function markCacheAsReady(int $id): void
	{
		$this->productsCacheStateRepository->many()->where('this.uuid', $id)->update(['state' => 'ready']);
		$this->productsCacheStateRepository->many()->where('this.uuid', $id === 1 ? 2 : 1)->update(['state' => 'empty']);
	}

	/**
	 * @return int<0, 2>
	 * @throws \StORM\Exception\NotFoundException
	 */
	protected function getCacheIndexToBeWarmedUp(): int
	{
		$cache1State = $this->productsCacheStateRepository->one('1', true)->state;
		$cache2State = $this->productsCacheStateRepository->one('2', true)->state;

		$cache1State = match ($cache1State) {
			'empty' => 0,
			'warming' => 1,
			'ready' => 2,
		};

		$cache2State = match ($cache2State) {
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

		return $logicTable[$cache1State . $cache2State];
	}

	/**
	 * @return int<0, 2>
	 * @throws \StORM\Exception\NotFoundException
	 */
	protected function getCacheIndexToBeUsed(): int
	{
		$readyState = $this->productsCacheStateRepository->many()->where('this.state', 'ready')->first();

		return $readyState ? (int) $readyState->getPK() : 0;
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
	protected function createCoalesceFromArray(array $values, string|null $prefix = null, string|null $suffix = null, string $separator = '_'): string
	{
		return $values ? ('COALESCE(' . \implode(',', \array_map(function ($item) use ($prefix, $suffix, $separator): string {
				return $prefix . ($prefix ? $separator : '') . $item->id . ($suffix ? $separator : '') . $suffix;
		}, $values)) . ')') : 'NULL';
	}
}
