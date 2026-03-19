<?php

namespace Eshop\Services\ProductsCache;

use Base\ShopsConfig;
use Eshop\DB\AttributeRepository;
use Eshop\DB\AttributeValueRepository;
use Eshop\DB\CategoryRepository;
use Eshop\DB\CategoryTypeRepository;
use Eshop\DB\Customer;
use Eshop\DB\DisplayAmountRepository;
use Eshop\DB\DisplayDeliveryRepository;
use Eshop\DB\Merchant;
use Eshop\DB\PricelistRepository;
use Eshop\DB\PriceRepository;
use Eshop\DB\ProducerRepository;
use Eshop\DB\ProductPrimaryCategoryRepository;
use Eshop\DB\ProductRepository;
use Eshop\DB\RelatedRepository;
use Eshop\DB\RelatedTypeRepository;
use Eshop\DB\VisibilityListItemRepository;
use Eshop\DB\VisibilityListRepository;
use Eshop\DevelTools;
use Eshop\ShopperUser;
use Nette\Caching\Cache;
use Nette\Caching\Storage;
use Nette\DI\Container;
use Nette\DI\MissingServiceException;
use Nette\Utils\Arrays;
use StORM\DIConnection;
use StORM\ICollection;
use Tracy\Debugger;
use Tracy\ILogger;
use Web\DB\SettingRepository;

class ProductsCacheGetterService
{
	public bool $debug = false;

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
		'name' => 'this.name',
		'published' => 'this.published',
		'buyCount' => 'this.buyCount',
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

	private DIConnection $connection;

	/** @var array<string, string|null> */
	private array $mappingCache = [];

	public function __construct(
		protected readonly ProductRepository $productRepository,
		protected readonly CategoryRepository $categoryRepository,
		protected readonly PriceRepository $priceRepository,
		/** @var \Eshop\DB\PricelistRepository<\Eshop\DB\Pricelist> */
		protected readonly PricelistRepository $pricelistRepository,
		protected readonly Container $container,
		protected readonly ShopsConfig $shopsConfig,
		protected readonly CategoryTypeRepository $categoryTypeRepository,
		protected readonly SettingRepository $settingRepository,
		protected readonly VisibilityListItemRepository $visibilityListItemRepository,
		protected readonly AttributeValueRepository $attributeValueRepository,
		protected readonly DisplayAmountRepository $displayAmountRepository,
		protected readonly VisibilityListRepository $visibilityListRepository,
		protected readonly ProducerRepository $producerRepository,
		protected readonly DisplayDeliveryRepository $displayDeliveryRepository,
		protected readonly AttributeRepository $attributeRepository,
		protected readonly ShopperUser $shopperUser,
		protected readonly RelatedRepository $relatedRepository,
		protected readonly RelatedTypeRepository $relatedTypeRepository,
		protected readonly ProductPrimaryCategoryRepository $productPrimaryCategoryRepository,
		readonly Storage $storage,
		protected readonly ProductsCacheDiffUpdateService $productsCacheDiffUpdateService,
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

	public function getIndexByCustomer(Customer|Merchant $customerMerchant): string
	{
		$visibilityLists = $customerMerchant->getVisibilityLists()->toArray();
		$priceLists = $customerMerchant->getPricelists()->toArray();

		$visibilityListsIds = $this->visibilityListRepository->many()
			->setSelect(['this.id'])
			->setOrderBy(['this.priority', 'this.uuid'])
			->where('this.uuid', \array_keys($visibilityLists))
			->toArrayOf('id', toArrayValues: true);
		$priceListsIds = $this->pricelistRepository->many()
			->setSelect(['this.id'])
			->setOrderBy(['this.priority', 'this.uuid'])
			->where('this.uuid', \array_keys($priceLists))
			->toArrayOf('id', toArrayValues: true);

		return \implode(',', $visibilityListsIds) . '-' . \implode(',', $priceListsIds);
	}

	public function addCollectionOrderExpression(string $name, callable $callback): void
	{
		$this->allowedCollectionOrderExpressions[$name] = $callback;
	}

	public function getConnection(): \StORM\DIConnection
	{
		if (isset($this->connection)) {
			return $this->connection;
		}

		try {
			/** @var \StORM\DIConnection $connection */
			$connection = $this->container->getByName('storm.cache');

			return $this->connection = $connection;
		} catch (MissingServiceException $e) {
			Debugger::log('Storm connection for products cache service was not found.', ILogger::EXCEPTION);

			throw $e;
		}
	}

	public function isReady(): bool
	{
		try {
			return (bool) $this->getConnection()->query('SHOW TABLES LIKE :q', ['q' => ProductsCacheBaseWarmUpService::PRODUCTS_TABLE_NAME])->fetch();
		} catch (\Throwable) {
			return false;
		}
	}

	/**
	 * @param array<mixed> $filters
	 * @param string|null $orderByName
	 * @param 'ASC'|'DESC' $orderByDirection Works only if $orderByName is not null
	 * @param array<string|int, \Eshop\DB\Pricelist> $priceLists
	 * @param array<string|int, \Eshop\DB\VisibilityList> $visibilityLists
	 * @return array{
	 *     "productPKs": list<string>,
	 *     "attributeValuesCounts": array<string|int, int>,
	 *     "displayAmountsCounts": array<string|int, int>,
	 *     "displayDeliveriesCounts": array<string|int, int>,
	 *     "producersCounts": array<string|int, int>,
	 *     'categoriesCounts'?: array<string|int, int>,
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
		bool $countCategories = false,
	): array|false {
		try {
			$this->getConnection();
		} catch (\Exception) {
			// Cache DB is not available

			return false;
		}

		$productsCacheTableName = ProductsCacheBaseWarmUpService::PRODUCTS_TABLE_NAME;
		$categoriesTableName = ProductsCacheBaseWarmUpService::CATEGORIES_TABLE_NAME;
		$relationsCacheTableName = ProductsCacheBaseWarmUpService::RELATIONS_TABLE_NAME;

		if (!$visibilityLists) {
			throw new \Exception('No VisibilityLists supplied.');
		}

		if (!$priceLists) {
			throw new \Exception('No PriceLists supplied.');
		}

		if (isset($filters['pricelist'])) {
			$priceLists = \array_filter($priceLists, static fn($priceList) => Arrays::contains($filters['pricelist'], $priceList), \ARRAY_FILTER_USE_KEY);
		}

		unset($filters['pricelist']);

		$visibilityListsIds = $this->visibilityListRepository->many()
			->setSelect(['this.id'])
			->setOrderBy(['this.priority', 'this.uuid'])
			->where('this.uuid', \array_keys($visibilityLists))
			->toArrayOf('id', toArrayValues: true);

		$priceListsIds = $this->pricelistRepository->many()
			->setSelect(['this.id'])
			->setOrderBy(['this.priority', 'this.uuid'])
			->where('this.uuid', \array_keys($priceLists))
			->toArrayOf('id', toArrayValues: true);

		$visibilityPriceListsIndex = \implode(',', $visibilityListsIds) . '-' . \implode(',', $priceListsIds);
		Debugger::barDump($visibilityPriceListsIndex);

//		$dataCacheIndex = \serialize($filters) . '_' . $orderByName . '-' . $orderByDirection . '_' . \serialize(\array_keys($priceLists)) . '_' . \serialize(\array_keys($visibilityLists));

//		$cachedData = $this->cache->load($dataCacheIndex, dependencies: [
//		  Cache::Tags => [GeneralProductsCacheProvider::PRODUCTS_PROVIDER_CACHE_TAG],
//		]);
//
//		if ($cachedData) {
//			return $cachedData;
//		}

		// TODO check for category type cannot be done for use cases where there are many category types per shop
		// This comes with small probability of wrong category type, when two categories from different category types have the same path
//		$mainCategoryType = $this->shopsConfig->getSelectedShop() ?
//			$this->settingRepository->getValueByName(SettingsPresenter::MAIN_CATEGORY_TYPE . '_' . $this->shopsConfig->getSelectedShop()->getPK()) :
//			'main';

		/** @var \Eshop\DB\Category|null $category */
		$category = isset($filters['category']) ?
			$this->categoryRepository->many()->select(['this.id'])->where('this.path', $filters['category'])->first(true) :
			null;

		unset($filters['category']);

		$visibilityPricesCacheTableName = $this->resolvePhysicalTableName($visibilityPriceListsIndex);

		if ($visibilityPricesCacheTableName === null) {
			return false;
		}

		$productsCollection = $this->getConnection()->rows(['this' => $productsCacheTableName])
			->join(
				['visibilityPrice' => "`$visibilityPricesCacheTableName`"],
				'this.product = visibilityPrice.product',
				type: 'INNER',
			);

		if (!$this->shopperUser->getShowZeroPrices()) {
			if ($this->shopperUser->getShowWithoutVat()) {
				$productsCollection->where('visibilityPrice.price > 0');
			} elseif ($this->shopperUser->getShowVat()) {
				$productsCollection->where('visibilityPrice.priceVat > 0');
			}
		}

		if ($category) {
			$descendants = [$category->id];

			if ($category->showDescendantProducts) {
				$descendants = \array_merge(
					$descendants,
					$category->getDescendants()
						->setSelect(['id'], keepIndex: true)
						->where('showProductsInAncestors', true)
						->toArrayOf('id', toArrayValues: true)
				);
			}

			$productsCollection->join(
				['category' => $categoriesTableName],
				'this.product = category.product AND category.category IN(' . \implode(',', $descendants) . ')',
				type: 'INNER',
			);
		}

		$productsCollection->setGroupBy(['this.product']);

		$productsCollection->setSelect([
			'product' => 'this.product',
			'producer' => 'this.producer',
			'attributeValues' => 'this.attributeValues',
			'displayAmount' => 'this.displayAmount',
			'displayDelivery' => 'this.displayDelivery',
			'price' => 'visibilityPrice.price',
			'priceVat' => 'visibilityPrice.priceVat',
			'priceList' => 'visibilityPrice.priceList',
			'masterProduct' => 'this.masterProduct',
			'ribbons' => 'this.ribbons',
			'internalRibbons' => 'this.internalRibbons',
			'projectName' => 'this.projectName',
		]);

		/** @var array<int, \Eshop\DB\Attribute> $allAttributes */
		$allAttributes = [];
		$dynamicFiltersAttributes = [];
		$dynamicFilters = [];

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

			$productsCollection->where('this.product', $this->getConnection()->rows([$relationsCacheTableName])
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

			$productsCollection->where('this.product', $this->getConnection()->rows([$relationsCacheTableName])
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

						if ($attribute->showRange) {
							$attributeValues = $this->attributeValueRepository->many()
								->select(['this.id'])
								->where('this.fk_attributevaluerange', $subValue)
								->toArray();

							foreach ($attributeValues as $attributeValue) {
								$dynamicFiltersAttributes[$attribute->id][$attributeValue->getValue('attributeValueRange')][] = $attributeValue->id;
							}
						} elseif ($attribute->showNumericSlider) {
							$numericAttributeValuesQuery = $this->attributeValueRepository->many()
								->setSelect(['this.id']);

							if (isset($subValue['from'])) {
								$numericAttributeValuesQuery->where('this.number >= :from OR this.numberFrom >= :from', ['from' => $subValue['from']]);
							}

							if (isset($subValue['to'])) {
								$numericAttributeValuesQuery->where('this.number <= :to OR this.numberTo <= :to', ['to' => $subValue['to']]);
							}

							$dynamicFiltersAttributes[$attribute->id] = $numericAttributeValuesQuery
								->where('this.fk_attribute', $attribute->getPK())
								->toArrayOf('id', toArrayValues: true);
						} else {
							$dynamicFiltersAttributes[$attribute->id] =
								$this->attributeValueRepository->many()
									->setSelect(['this.id'])
									->where('this.uuid', $subValue)
									->toArrayOf('id', toArrayValues: true);
						}
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
		$categoriesCounts = [];
		$descendantCategoriesMap = [];

		if ($countCategories) {
			$productsCollection->join(['category' => $categoriesTableName], 'this.product = category.product', type: 'INNER');
			$productsCollection->select(['categories' => 'GROUP_CONCAT(category.category)']);

			/** @var array<\Eshop\DB\Category> $allCategories */
			$allCategories = $this->categoryRepository->many()
				->select(['this.id',])
				->setIndex('id')
				->toArray();

			$categoriesIdUuidMap = $this->categoryRepository->many()
				->setSelect(['this.id', 'this.uuid'])
				->setIndex('uuid')
				->toArrayOf('id');
		}

		if ($this->debug) {
			DevelTools::bdumpCollection($productsCollection);
		}

		$priceMin = \PHP_FLOAT_MAX;
		$priceMax = \PHP_FLOAT_MIN;
		$priceVatMin = \PHP_FLOAT_MAX;
		$priceVatMax = \PHP_FLOAT_MIN;

		$dynamicallyCountedDynamicFilters = [];
//		Debugger::dump(Debugger::timer());

		$fetchedProducts = $productsCollection->fetchArray(\stdClass::class);

//		Debugger::dump(Debugger::timer());

		foreach ($fetchedProducts as $product) {
			$attributeValues = $product->attributeValues ? \array_flip(\explode(',', $product->attributeValues)) : [];

			foreach ($dynamicFiltersAttributes as $attributePK => $attributeValuesPKs) {
				if (\count($attributeValuesPKs) === 0) {
					continue;
				}

				/** @var \Eshop\DB\Attribute $attribute */
				$attribute = $allAttributes[$attributePK];

				if ($attribute->showRange) {
					foreach ($attributeValuesPKs as $attributeValueRanges) {
						$found = false;

						foreach ($attributeValueRanges as $attributeValue) {
							if (isset($attributeValues[$attributeValue])) {
								$found = true;

								break;
							}
						}

						if (!$found) {
							continue 3;
						}
					}

					continue;
				}

				if ($attribute->filterType === 'or' || $attribute->showNumericSlider) {
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

					continue;
				}

				foreach ($attributeValuesPKs as $attributeValue) {
					if (!isset($attributeValues[$attributeValue])) {
						continue 3;
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

			if ($countCategories && $product->categories) {
				$categories = \explode(',', $product->categories);

				foreach ($categories as $currentCategoryId) {
					$categoriesCounts[$currentCategoryId] = ($categoriesCounts[$currentCategoryId] ?? 0) + 1;

					$categoryEntity = $allCategories[$currentCategoryId];

					$descendantCategoriesMap[$categoryEntity->getPK()] ??= $categoryEntity->getDescendants()
						->setSelect(['id'], keepIndex: true)
						->where('showProductsInAncestors', true)
						->toArrayOf('id', toArrayValues: true);

					foreach ($descendantCategoriesMap[$categoryEntity->getPK()] as $descendant) {
						$categoriesCounts[$descendant] = ($categoriesCounts[$descendant] ?? 0) + 1;
					}

					// Najdi všechny předky v $allCategories a přičti je také
					$currentCategory = $currentCategoryId;

					while (isset($allCategories[$currentCategory]) && $allCategories[$currentCategory]->getValue('ancestor')) {
						$ancestor = $allCategories[$categoriesIdUuidMap[$allCategories[$currentCategory]->getValue('ancestor')]];

						if ($ancestor->showDescendantProducts) {
							$categoriesCounts[$ancestor->id] = ($categoriesCounts[$ancestor->id] ?? 0) + 1;
						}

						$currentCategory = $categoriesIdUuidMap[$allCategories[$currentCategory]->getValue('ancestor')];
					}
				}
			}

			foreach (\array_keys($attributeValues) as $attributeValue) {
				$attributeValuesCounts[$attributeValue] = ($attributeValuesCounts[$attributeValue] ?? 0) + 1;
			}
		}

//		Debugger::dump(Debugger::timer());
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

//		Debugger::dump(Debugger::timer());
		$attributeValues = $this->attributeValueRepository->many()
			->setSelect([
				'this.uuid',
				'this.id',
				'rangePK' => 'this.fk_attributevaluerange',
				'showRange' => 'attribute.showRange',
			])
			->join(['attribute' => 'eshop_attribute'], 'this.fk_attribute = attribute.uuid')
			->where('this.id', \array_keys($attributeValuesCounts))
			->fetchArray(\stdClass::class);

		foreach ($attributeValues as $attributeValue) {
			if ($attributeValue->showRange && $attributeValue->rangePK !== null) {
				$attributeValuesCounts[$attributeValue->rangePK] = ($attributeValuesCounts[$attributeValue->rangePK] ?? 0) + $attributeValuesCounts[$attributeValue->id];
			} else {
				$attributeValuesCounts[$attributeValue->uuid] = $attributeValuesCounts[$attributeValue->id];
			}

			unset($attributeValuesCounts[$attributeValue->id]);
		}

		$result = [
			'productPKs' => $productPKs,
			'attributeValuesCounts' => $attributeValuesCounts,
			'displayAmountsCounts' => $displayAmountsCounts,
			'displayDeliveriesCounts' => $displayDeliveriesCounts,
			'producersCounts' => $producersCounts,
			'priceMin' => $priceMin && $priceMin < \PHP_FLOAT_MAX ? \floor($priceMin) : 0,
			'priceMax' => $priceMax && $priceMax > \PHP_FLOAT_MIN ? \ceil($priceMax) : 0,
			'priceVatMin' => $priceVatMin && $priceVatMin < \PHP_FLOAT_MAX ? \floor($priceVatMin) : 0,
			'priceVatMax' => $priceVatMax && $priceVatMax > \PHP_FLOAT_MIN ? \ceil($priceVatMax) : 0,
		];

		if ($categoriesCounts) {
			$result['categoriesCounts'] = $categoriesCounts;
		}

		return $result;

//		$this->saveDataCacheIndex($dataCacheIndex, $result);

//		Debugger::dump(Debugger::timer());
//		return $result;
	}

	protected function startUp(): void
	{
		$this->allowedCollectionOrderExpressions['availabilityAndPrice'] =
			static function (ICollection $productsCollection, string $direction, array $visibilityLists, array $priceLists): void {
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
			static function (ICollection $productsCollection, string $direction, array $visibilityLists, array $priceLists): void {
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

		$this->allowedCollectionFilterExpressions['query2'] = static function (ICollection $productsCollection, string $query, array $visibilityLists, array $priceLists): void {
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

		$this->allowedCollectionOrderExpressions['query2'] = static function (ICollection $productsCollection, string $query, array $visibilityLists, array $priceLists): void {
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

		$this->allowedCollectionFilterExpressions['producer'] = function (ICollection $productsCollection, string|null|array $producer, array $visibilityLists, array $priceLists): void {
			if ($producer !== null) {
				$producerArray = $this->producerRepository->many()
					->where('this.uuid', \is_array($producer) ? \array_values($producer) : $producer)
					->setSelect(['this.id'])
					->toArrayOf('id', toArrayValues: true);

				$productsCollection->where('this.producer', $producerArray);
			} else {
				$productsCollection->where('this.producer IS NULL');
			}
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

		$this->allowedDynamicFilterExpressions['ribbon'] = function (\stdClass $product, mixed $value, array $visibilityLists, array $priceLists): bool {
			$ribbons = \array_flip(\explode(',', (string) $product->ribbons));

			if (\is_string($value)) {
				return isset($ribbons[$value]);
			}

			if (\is_array($value)) {
				foreach ($value as $ribbon) {
					if (!isset($ribbons[$ribbon])) {
						return false;
					}
				}

				return true;
			}

			throw new \InvalidArgumentException("Filter 'ribbon': Input must be string or array!");
		};

		$this->allowedDynamicFilterExpressions['notRibbon'] = function (\stdClass $product, mixed $value, array $visibilityLists, array $priceLists): bool {
			$ribbons = \array_flip(\explode(',', (string) $product->ribbons));

			if (\is_string($value)) {
				return !isset($ribbons[$value]);
			}

			if (\is_array($value)) {
				foreach ($value as $ribbon) {
					if (isset($ribbons[$ribbon])) {
						return false;
					}
				}

				return true;
			}

			throw new \InvalidArgumentException("Filter 'notRibbon': Input must be string or array!");
		};

		$this->allowedDynamicFilterExpressions['internalRibbon'] = function (\stdClass $product, mixed $value, array $visibilityLists, array $priceLists): bool {
			$ribbons = \array_flip(\explode(',', (string) $product->internalRibbons));

			if (\is_string($value)) {
				return isset($ribbons[$value]);
			}

			if (\is_array($value)) {
				foreach ($value as $ribbon) {
					if (!isset($ribbons[$ribbon])) {
						return false;
					}
				}

				return true;
			}

			throw new \InvalidArgumentException("Filter 'internalRibbon': Input must be string or array!");
		};

		$this->allowedDynamicFilterExpressions['notInternalRibbon'] = function (\stdClass $product, mixed $value, array $visibilityLists, array $priceLists): bool {
			$ribbons = \array_flip(\explode(',', (string) $product->internalRibbons));

			if (\is_string($value)) {
				return !isset($ribbons[$value]);
			}

			if (\is_array($value)) {
				foreach ($value as $ribbon) {
					if (isset($ribbons[$ribbon])) {
						return false;
					}
				}

				return true;
			}

			throw new \InvalidArgumentException("Filter 'notInternalRibbon': Input must be string or array!");
		};

		$this->allowedDynamicFilterExpressions['masterProduct'] = static function (\stdClass $product, mixed $value, array $visibilityLists, array $priceLists): bool {
			if ($value === true) {
				return $product->masterProduct === null;
			}

			if ($value === false) {
				return $product->masterProduct !== null;
			}

			return true;
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
		return $values ? ('COALESCE(' . \implode(',', \array_map(static function (mixed $item) use ($prefix, $suffix, $separator): string {
				return $prefix . ($prefix ? $separator : '') . $item->id . ($suffix ? $separator : '') . $suffix;
		}, $values)) . ')') : 'NULL';
	}

	private function resolvePhysicalTableName(string $priceIndex): string|null
	{
		if (\array_key_exists($priceIndex, $this->mappingCache)) {
			return $this->mappingCache[$priceIndex];
		}

		try {
			$result = $this->getConnection()->query(
				'SELECT physical_table FROM `price_table_map` WHERE price_index = :idx LIMIT 1',
				['idx' => $priceIndex],
			);

			$row = $result->fetch(\PDO::FETCH_ASSOC);

			$this->mappingCache[$priceIndex] = $row !== false ? $row['physical_table'] : null;
		} catch (\Throwable) {
			$this->mappingCache[$priceIndex] = null;
		}

		return $this->mappingCache[$priceIndex];
	}
}
