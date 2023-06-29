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
use Nette\DI\Container;
use Nette\DI\MissingServiceException;
use Nette\Utils\Arrays;
use StORM\Connection;
use Web\DB\SettingRepository;

class RedisProductProvider
{
	// Location on filters vs in product data
	public const ALLOWED_FILTER_PROPERTIES = [
		'attributes.producer' => 'producer',
		'attributes.displayAmount' => 'displayAmount',
		'attributes.displayDelivery' => 'displayDelivery',
	];

	public const ALLOWED_ORDER_PROPERTIES = [
		'visibilityListItem' => [
			'hidden' => true,
			'hiddenInMenu' => true,
			'priority' => true,
		],
		'price' => [
			'price' => true,
			'priceVat' => true,
		],
		'displayAmount' => [
			'isSold' => true,
		],
	];

	/**
	 * @var array<array<string|callable>>
	 */
	public array $allowedOrders = [
		'priority' => [
			'visibilityListItem.priority',
		],
	];

	/**
	 * @var array<mixed>
	 */
	protected array $cache = [];

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
	) {
	}

	/**
	 * @param string $name
	 * @param array<string|callable> $orderBy
	 * @throws \Exception
	 */
	public function addAllowedOrder(string $name, array $orderBy): void
	{
		foreach ($orderBy as $item) {
			if (\is_callable($item)) {
				continue;
			}

			if (\is_string($item)) {
				$dotCount = \substr_count($item, '.');

				if ($dotCount !== 1) {
					throw new \Exception("Order '$item' must contain exactly one dot.");
				}

				$explodedItem = \explode('.', $item);

				if (!isset($this::ALLOWED_ORDER_PROPERTIES[$explodedItem[0]][$explodedItem[1]])) {
					throw new \Exception("Order '$item' is not supported. Check supported types.");
				}

				continue;
			}

			/** @phpstan-ignore-next-line */
			throw new \Exception("Unsupported value to order! You can use only dotted string (e.g. 'visibilityListItem.hidden') or callable.");
		}

		$this->allowedOrders[$name] = $orderBy;
	}

	public function warmUpRedisCache(): void
	{
		try {
			/** @var \Predis\Client|null $redis */
			$redis = $this->container->getService('redis.connection.default.client');
		} catch (MissingServiceException) {
			return;
		}

		$redis->connect();

		if (!$redis->isConnected()) {
			return;
		}

		$redis->set('cacheReady', 0);

		// Clear all Redis indexes related to this cache
		$keys = \array_merge($redis->keys('productsInCategory*'), $redis->keys('product*'));

		if ($keys) {
			$redis->del($keys);
		}

		$allPrices = $this->priceRepository->many()->toArray();
		$allVisibilityListItems = $this->visibilityListItemRepository->many()->toArray();
		$allAttributeValues = $this->attributeValueRepository->many()->select(['attributePK' => 'fk_attribute'])->fetchArray(\stdClass::class);
		$allDisplayAmounts = $this->displayAmountRepository->many()->toArray();
		$allCategories = $this->categoryRepository->many()->toArray();
		$allCategoriesByCategory = [];

		$productsByCategories = [];

		$this->connection->getLink()->exec('SET SESSION group_concat_max_len=4294967295');

		$products = [];

		$productsCollection = $this->productRepository->many()
			->join(['price' => 'eshop_price'], 'this.uuid = price.fk_product')
			->join(['priceList' => 'eshop_pricelist'], 'price.fk_pricelist = priceList.uuid')
			->join(['discount' => 'eshop_discount'], 'priceList.fk_discount = discount.uuid')
			->join(['eshop_product_nxn_eshop_category'], 'this.uuid = eshop_product_nxn_eshop_category.fk_product')
			->join(['visibilityListItem' => 'eshop_visibilitylistitem'], 'visibilityListItem.fk_product = this.uuid')
			->join(['visibilityList' => 'eshop_visibilitylist'], 'visibilityListItem.fk_visibilityList = visibilityList.uuid')
			->join(['assign' => 'eshop_attributeassign'], 'this.uuid = assign.fk_product')
			->setSelect([
				'uuid' => 'this.uuid',
				'fkDisplayAmount' => 'this.fk_displayAmount',
				'fkProducer' => 'this.fk_producer',
				'pricesPKs' => 'GROUP_CONCAT(DISTINCT price.uuid ORDER BY priceList.priority)',
				'categoriesPKs' => 'GROUP_CONCAT(DISTINCT eshop_product_nxn_eshop_category.fk_category)',
				'visibilityListItemsPKs' => 'GROUP_CONCAT(DISTINCT visibilityListItem.uuid ORDER BY visibilityList.priority)',
				'attributeValuesPKs' => 'GROUP_CONCAT(DISTINCT assign.fk_value)',
			])
			->where('priceList.isActive', true)
			->where('(discount.validFrom IS NULL OR discount.validFrom <= DATE(now())) AND (discount.validTo IS NULL OR discount.validTo >= DATE(now()))')
			->where('visibilityList.hidden', false)
			->setGroupBy(['this.uuid']);

		while ($product = $productsCollection->fetch(\stdClass::class)) {
			/** @var \stdClass $product */

			if (!$prices = $product->pricesPKs) {
				continue;
			}

			$products[$product->uuid] = [
				'uuid' => $product->uuid,
				'displayAmount' => $product->fkDisplayAmount,
				'displayAmount.isSold' => $product->fkDisplayAmount ? $allDisplayAmounts[$product->fkDisplayAmount]->isSold : null,
				'producer' => $product->fkProducer,
			];

			if ($categories = $product->categoriesPKs) {
				$categories = \explode(',', $categories);

				foreach ($categories as $category) {
					$categoryCategories = $allCategoriesByCategory[$category] ?? null;

					if ($categoryCategories === null) {
						$categoryCategories = $allCategoriesByCategory[$category] = \array_merge($this->getAncestorsOfCategory($category, $allCategories), [$category]);
					}

					$products[$product->uuid]['categories'] = \array_unique(\array_merge($products[$product->uuid]['categories'] ?? [], $categoryCategories));

					foreach ($products[$product->uuid]['categories'] as $productCategory) {
						$productsByCategories[$productCategory][$product->uuid] = true;
					}
				}
			}

			if ($visibilityListItems = $product->visibilityListItemsPKs) {
				$visibilityListItems = \explode(',', $visibilityListItems);

				$data = [];

				foreach ($visibilityListItems as $visibilityListItem) {
					$visibilityListItem = $allVisibilityListItems[$visibilityListItem];
					$data[$visibilityListItem->getValue('visibilityList')] = [
						'hidden' => $visibilityListItem->hidden,
						'hiddenInMenu' => $visibilityListItem->hiddenInMenu,
						'priority' => $visibilityListItem->priority,
						'unavailable' => $visibilityListItem->unavailable,
						'recommended' => $visibilityListItem->recommended,
					];
				}

				$products[$product->uuid]['visibilityListItems'] = $data;
			}

			$prices = \explode(',', $prices);

			$productPrices = [];

			foreach ($prices as $price) {
				$price = $allPrices[$price];
				$productPrices[$price->getValue('pricelist')] = [
					'price' => $price->price,
					'priceVat' => $price->priceVat,
					'priceBefore' => $price->priceBefore,
					'priceVatBefore' => $price->priceVatBefore,
				];
			}

			$products[$product->uuid]['prices'] = $productPrices;

			if (!$attributeValues = $product->attributeValuesPKs) {
				continue;
			}

			$attributeValues = \explode(',', $attributeValues);

			foreach ($attributeValues as $attributeValue) {
				$attributeValue = $allAttributeValues[$attributeValue];

				$products[$product->uuid]['attributeValues'][$attributeValue->uuid] = true;
			}
		}

		$productsCollection->__destruct();

		$serializedProducts = [];

		foreach ($products as $product) {
			$serializedProducts["product:{$product['uuid']}"] = \serialize($product);
		}

		$redis->del('products');

		$prefixedProducts = \array_map(function ($value) {
			return 'product:' . $value;
		}, \array_keys($products));

		$redis->sadd('products', $prefixedProducts);

		$redis->mset($serializedProducts);

		foreach ($productsByCategories as $category => $products) {
			$prefixedProducts = \array_map(function ($value) {
				return 'product:' . $value;
			}, \array_keys($products));

			$redis->del("productsInCategory:$category");
			$redis->sadd("productsInCategory:$category", $prefixedProducts);
		}

		$redis->set('cacheReady', 1);
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
	public function getProductsFromRedis(
		array $filters,
		string|null $orderByName = null,
		string $orderByDirection = 'ASC',
		array $priceLists = [],
		array $visibilityLists = []
	): array|false {
		$cacheIndex = \serialize([
			$filters,
			$orderByName,
			$orderByDirection,
			\array_keys($priceLists),
			\array_keys($visibilityLists),
		]);

		if (isset($this->cache[$cacheIndex])) {
			return $this->cache[$cacheIndex];
		}

		try {
			/** @var \Predis\Client|null $redis */
			$redis = $this->container->getService('redis.connection.default.client');
		} catch (MissingServiceException) {
			return false;
		}

		$redis->connect();

		if (!$redis->isConnected()) {
			return false;
		}

//		\dump('connect');
//		\dump(\Tracy\::timer());

		$cacheReady = $redis->get('cacheReady');

		if ($cacheReady !== '1') {
			return false;
		}

//		\dump('ready');
//		\dump(\Tracy\Debugger::timer());
		$category = isset($filters['category']) ? $this->categoryRepository->many()->where('this.path', $filters['category'])->first(true) : null;

		$products = $category ? $redis->smembers("productsInCategory:{$category->getPK()}") : $redis->smembers('products');
//		\dump('smembers');
//		\dump(\Tracy\Debugger::timer());
		$loadedProductsFromRedis = $products ? $redis->mget($products) : [];
//		\dump('mget');
//		\dump(\Tracy\Debugger::timer());

////		\dump('pre_processing');
////		\dump(\Tracy\::timer());
		$orderedResult = [];
		$attributeValuesCounts = [];
		$displayAmountsCounts = [];
		$producersCounts = [];

		foreach ($loadedProductsFromRedis as $loadedProductFromRedis) {
			$product = \unserialize($loadedProductFromRedis);
			$productPK = $product['uuid'];

			foreach ($this::ALLOWED_FILTER_PROPERTIES as $filterLocation => $productLocation) {
				$filterLocationExploded = \explode('.', $filterLocation);
				$currentArray = &$filters;

				foreach ($filterLocationExploded as $key) {
					if (!isset($currentArray[$key])) {
						$currentArray[$key] = [];
					}

					$currentArray = &$currentArray[$key];
				}

				if (match ($filterLocation) {
					'attributes.producer', 'attributes.displayAmount', 'attributes.displayDelivery' => true,
					default => false,
				}) {
				if (\is_array($currentArray)) {
					$currentArray = Arrays::first($currentArray);
				}
				}

				if ($currentArray && $currentArray !== $product[$productLocation]) {
					continue 2;
				}
			}

			$found = false;

			$activeVisibilityListItem = null;

			foreach ($product['visibilityListItems'] ?? [] as $visibilityListPK => $visibilityListItem) {
				if (!isset($visibilityLists[$visibilityListPK])) {
					continue;
				}

				$found = true;

				foreach (['hidden', 'hiddenInMenu', 'recommended', 'unavailable'] as $filter) {
					if (isset($filters[$filter]) && $filters[$filter] !== $visibilityListItem[$filter]) {
						continue 3;
					}
				}

				$activeVisibilityListItem = $visibilityListItem;

				break;
			}

			if (!$found) {
				continue;
			}

			$found = false;
			$activePrice = null;

			foreach ($product['prices'] ?? [] as $priceListPK => $price) {
				if (!isset($priceLists[$priceListPK])) {
					continue;
				}

				$found = true;

				if (!$price['price'] > 0) {
					continue 2;
				}

				$activePrice = $price;

				break;
			}

			if (!$found) {
				continue;
			}

			if ($product['displayAmount']) {
				$displayAmountsCounts[$product['displayAmount']] = ($displayAmountsCounts[$product['displayAmount']] ?? 0) + 1;
			}

			if ($product['producer']) {
				$producersCounts[$product['producer']] = ($producersCounts[$product['producer']] ?? 0) + 1;
			}

			foreach (\array_keys($product['attributeValues'] ?? []) as $attributeValue) {
				$attributeValuesCounts[$attributeValue] = ($attributeValuesCounts[$attributeValue] ?? 0) + 1;
			}

			if (!$orderByName) {
				$orderedResult[] = $productPK;

				continue;
			}

			if (!isset($this->allowedOrders[$orderByName])) {
				throw new \Exception("Order by '$orderByName' is not supported. You need to use 'addAllowedOrder' method first.");
			}

//			foreach ($this->allowedOrders[$orderByName] as $order) {
//
//			}
			$orderedResult[(int) $activeVisibilityListItem['priority']][(int) $product['displayAmount.isSold']][(int) $activePrice['price']][$productPK] = true;
//			$orderedResult[(int) $activePrice['price']][$productPK] = true;
		}

		$this->recursiveKSort($orderedResult, $orderByDirection);

		$finalResults = [];
		$this->flattenArray($orderedResult, $finalResults);

		return $this->cache[$cacheIndex] = [
			'productPKs' => $finalResults,
			'attributeValuesCounts' => $attributeValuesCounts,
			'displayAmountsCounts' => $displayAmountsCounts,
			'producersCounts' => $producersCounts,
		];
	}

	/**
	 * @param string $categoryPK
	 * @return array<string>
	 * @throws \StORM\Exception\NotFoundException
	 */
	protected function getChildrenOfCategory(string $categoryPK): array
	{
		$category = $this->categoryRepository->one($categoryPK, true);

		return $this->categoryRepository->many()
			->where('this.path LIKE :s', ['s' => "$category->path%"])
			->toArrayOf('uuid', toArrayValues: true);
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

	private function recursiveKSort(&$array, string $orderByDirection): void
	{
		if (!\is_array($array)) {
			return;
		}

		if ($orderByDirection === 'ASC') {
			\ksort($array);
		} else {
			\krsort($array);
		}

		foreach ($array as &$value) {
			$this->recursiveKSort($value, $orderByDirection);
		}
	}

	/**
	 * @param array<mixed> $array
	 * @param array<string> $finalResults
	 */
	private function flattenArray(array $array, array &$finalResults): void
	{
		foreach ($array as $key => $value) {
			if (\is_array($value)) {
				$this->flattenArray($value, $finalResults);
			} else {
				$finalResults[] = $key;
			}
		}
	}
}
