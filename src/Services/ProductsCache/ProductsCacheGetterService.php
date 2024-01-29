<?php

namespace Eshop\Services\ProductsCache;

use Base\Bridges\AutoWireService;
use Base\ShopsConfig;
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
use Eshop\ShopperUser;
use Nette\Caching\Cache;
use Nette\Caching\Storage;
use Nette\DI\Container;
use Nette\Utils\Arrays;
use StORM\DIConnection;
use StORM\ICollection;
use Tracy\Debugger;
use Web\DB\SettingRepository;

class ProductsCacheGetterService implements AutoWireService
{
	/**
	 * Also hard-coded: category, pricelist
	 * @var array<string>
	 */
	protected array $allowedCollectionFilterColumns = [
		'hidden' => 'visibilityPrice.hidden',
		'hiddenInMenu' => 'visibilityPrice.hiddenInMenu',
		'priority' => 'visibilityPrice.priority',
		'recommended' => 'visibilityPrice.recommended',
		'unavailable' => 'visibilityPrice.unavailable',
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
		'priority' => 'visibilityPrice.priority',
		'price' => 'visibilityPrice.price',
		'name' => 'name',
	];

	/**
	 * @var array<callable(
	 *  \StORM\ICollection<\stdClass> $productsCollection,
	 *  'ASC'|'DESC' $direction,
	 *  array<\Eshop\DB\VisibilityList> $visibilityLists,
	 *  array<\Eshop\DB\Pricelist> $priceLists,
	 * ): void>
	 */
	protected array $allowedCollectionOrderExpressions = [];

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

		$this->startUp();
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

		$productsCacheTableName = "eshop_products_cache_$cacheIndex";
		$visibilityPricesCacheTableName = "eshop_products_prices_cache_$cacheIndex";
		$categoriesTableName = "eshop_categories_cache_$cacheIndex";

		if (!$visibilityLists || !$priceLists) {
			throw new \Exception('No visibility or price lists supplied.');
		}

		if (isset($filters['pricelist'])) {
			$priceLists = \array_filter($priceLists, fn($priceList) => Arrays::contains($filters['pricelist'], $priceList), \ARRAY_FILTER_USE_KEY);
		}

		unset($filters['pricelist']);

		$visibilityListsIds = $this->visibilityListRepository->many()
			->setSelect(['this.id'])
			->setOrderBy(['this.priority'])
			->where('this.uuid', \array_keys($visibilityLists))
			->toArrayOf('id', toArrayValues: true);
		$priceListsIds = $this->pricelistRepository->many()
			->setSelect(['this.id'])
			->setOrderBy(['this.priority'])
			->where('this.uuid', \array_keys($priceLists))
			->toArrayOf('id', toArrayValues: true);

		$visibilityPriceListsIndex = \implode(',', $visibilityListsIds) . '-' . \implode(',', $priceListsIds);
		Debugger::barDump($visibilityPriceListsIndex);

//      $dataCacheIndex = \serialize($filters) . '_' . $orderByName . '-' . $orderByDirection . '_' . \serialize(\array_keys($priceLists)) . '_' . \serialize(\array_keys($visibilityLists));
//
//      $cachedData = $this->cache->load($dataCacheIndex, dependencies: [
//          Cache::Tags => [self::PRODUCTS_PROVIDER_CACHE_TAG],
//      ]);
//
//      if ($cachedData) {
//          return $cachedData;
//      }

		$mainCategoryType = $this->shopsConfig->getSelectedShop() ?
			$this->settingRepository->getValueByName(SettingsPresenter::MAIN_CATEGORY_TYPE . '_' . $this->shopsConfig->getSelectedShop()->getPK()) :
			'main';

		$category = isset($filters['category']) ?
			$this->categoryRepository->many()->setSelect(['this.id'])->where('this.path', $filters['category'])->where('this.fk_type', $mainCategoryType)->first(true) :
			null;

		unset($filters['category']);

		$productsCollection = $this->connection->rows(['this' => $productsCacheTableName])
			->join(
				['visibilityPrice' => $visibilityPricesCacheTableName],
				'this.product = visibilityPrice.product AND visibilityPrice.visibilityPriceIndex = :visibilityPriceListsIndex',
				['visibilityPriceListsIndex' => $visibilityPriceListsIndex],
				type: 'INNER',
			);

		if ($category) {
			$productsCollection->join(
				['category' => $categoriesTableName],
				'this.product = category.product AND category.category = :category',
				['category' => $category->id],
				type: 'INNER',
			);
		}

		$productsCollection->setGroupBy(['this.product']);
//		$productsCollection->where('visibilityPrice.price > 0');

		$productsCollection->setSelect([
			'product' => 'this.product',
			'producer' => 'this.producer',
			'attributeValues' => 'this.attributeValues',
			'displayAmount' => 'this.displayAmount',
			'displayDelivery' => 'this.displayDelivery',
			'price' => 'visibilityPrice.price',
			'priceVat' => 'visibilityPrice.priceVat',
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
				$productsCollection->where($this->allowedCollectionFilterColumns[$filter], $value);

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

		if ($orderByName) {
			if (isset($this->allowedCollectionOrderColumns[$orderByName])) {
				$productsCollection->orderBy([$this->allowedCollectionOrderColumns[$orderByName] => $orderByDirection]);
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

//		DevelTools::bdumpCollection($productsCollection);

		$priceMin = \PHP_FLOAT_MAX;
		$priceMax = \PHP_FLOAT_MIN;
		$priceVatMin = \PHP_FLOAT_MAX;
		$priceVatMax = \PHP_FLOAT_MIN;

		$dynamicallyCountedDynamicFilters = [];

		foreach ($productsCollection->fetchArray(\stdClass::class) as $product) {
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

	protected function startUp(): void
	{
		$this->allowedCollectionOrderExpressions['availabilityAndPrice'] =
			function (ICollection $productsCollection, string $direction, array $visibilityLists, array $priceLists): void {
				$productsCollection->orderBy([
					'case COALESCE(displayAmount_isSold, 2)
						 when 0 then 0
						 when 2 then 1
						 when 1 then 2
						 else 2 end' => $direction,
					'visibilityPrice.price' => $direction,
				]);
			};

		$this->allowedCollectionOrderExpressions['priorityAvailabilityPrice'] =
			function (ICollection $productsCollection, string $direction, array $visibilityLists, array $priceLists): void {
				$productsCollection->orderBy([
					'visibilityPrice.priority' => $direction,
					'case COALESCE(displayAmount_isSold, 2)
	                     when 0 then 0
	                     when 1 then 1
	                     when 2 then 2
	                     else 2 end' => $direction,
					'visibilityPrice.price' => $direction,
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

	/**
	 * @param string $index
	 * @param array<mixed> $data
	 */
	protected function saveDataCacheIndex(string $index, array $data): void
	{
		$this->cache->save($index, $data, [
			Cache::Tags => [GeneralProductsCacheProvider::PRODUCTS_PROVIDER_CACHE_TAG],
		]);
	}

	/**
	 * @deprecated Don't use. New cache has direct columns.
	 * @param array<mixed> $values
	 */
	protected function createCoalesceFromArray(array $values, string|null $prefix = null, string|null $suffix = null, string $separator = '_'): string
	{
		return $values ? ('COALESCE(' . \implode(',', \array_map(function (mixed $item) use ($prefix, $suffix, $separator): string {
				return $prefix . ($prefix ? $separator : '') . $item->id . ($suffix ? $separator : '') . $suffix;
		}, $values)) . ')') : 'NULL';
	}
}
