<?php

namespace Eshop\Services\ProductsCache;

use Eshop\DB\CategoryRepository;
use Eshop\DB\Customer;
use Eshop\DB\Merchant;
use Eshop\DB\PricelistRepository;
use Eshop\Services\SettingsService;
use Eshop\ShopperUser;
use Nette\Utils\Arrays;

/**
 * Main service to work with a cache of products. If possible, always use this service.
 */
class ProductsCacheProvider implements GeneralProductsCacheProvider
{
	private bool|null $isReady = null;

	/**
	 * @var array<string, array<int|string, int>>
	 */
	private array $cachedCategoryCounts = [];

	public function __construct(
		private readonly ProductsCacheGetterService $productsCacheProviderService,
		private readonly ProductsCacheDiffUpdateService $productsCacheDiffUpdateService,
		private readonly ShopperUser $shopperUser,
		private readonly PricelistRepository $pricelistRepository,
		private readonly SettingsService $settingsService,
		private readonly CategoryRepository $categoryRepository,
	) {
	}

	/**
	 * @inheritDoc
	 */
	public function warmUpCacheTable(array $customers = [], array $customerGroups = [], array $merchants = []): void
	{
		$this->productsCacheDiffUpdateService->warmUpCacheTableDiff($customers, $customerGroups, $merchants);
	}

	/**
	 * @inheritDoc
	 */
	public function getProductsFromCacheTable(
		array $filters,
		?string $orderByName = null,
		string $orderByDirection = 'ASC',
		array $priceLists = [],
		array $visibilityLists = [],
		bool $debug = false,
		bool $countCategories = false,
	): array|false {
		if (!$this->settingsService->isUsingProductsCache()) {
			return false;
		}

		$priceLists = $priceLists ?: $this->shopperUser->getPriceListsCached();
		$visibilityLists = $visibilityLists ?: $this->shopperUser->getVisibilityLists();
		$customer = $this->shopperUser->getCustomer();
		$merchant = $this->shopperUser->getMerchant();
		$customerGroup = $this->shopperUser->getCustomerGroup();

		if ($this->isReady === null) {
			$this->isReady = $this->productsCacheProviderService->isReady();
		}

		if (!$this->isReady) {
			throw new ProductsCacheNotReadyException();
		}

		$this->productsCacheProviderService->debug = $debug;

		if (isset($filters['pricelist'])) {
			$priceLists = \array_filter($priceLists, fn($priceList) => Arrays::contains($filters['pricelist'], $priceList), \ARRAY_FILTER_USE_KEY);
		}

		if (!$customer || !$merchant || $this->shopperUser->getMerchantPriceListsMode() !== 'merge') {
			try {
				return $this->productsCacheProviderService->getProductsFromCacheTable($filters, $orderByName, $orderByDirection, $priceLists, $visibilityLists, $countCategories);
			} catch (\Throwable $e) {
				if ($e->getCode() === '42S02') {
					// Table does not exist, try warming it up
					$this->productsCacheDiffUpdateService->updatePricesTableDiff(
						$customer ? [$customer] : [],
						$customerGroup ? [$customerGroup->getPK()] : [],
						$merchant ? [$merchant->getPK()] : [],
					);

					return $this->productsCacheProviderService->getProductsFromCacheTable($filters, $orderByName, $orderByDirection, $priceLists, $visibilityLists, $countCategories);
				}

				throw $e;
			}
		}

		// do two separate calls to cache with customer and merchant pricelists and combine results
		$customerPriceLists = $this->pricelistRepository->getCustomerPricelists(
			$customer,
			$this->shopperUser->getCurrency(),
			$this->shopperUser->getCountry(),
			$this->shopperUser->getCheckoutManager()->getDiscountCoupon(),
		)
			->where('this.uuid', \array_keys($priceLists))
			->toArray();

		$merchantPriceLists = $this->pricelistRepository->getMerchantPricelists(
			$merchant,
			$this->shopperUser->getCurrency(),
			$this->shopperUser->getCountry(),
			$this->shopperUser->getCheckoutManager()->getDiscountCoupon(),
		)
			->where('this.uuid', \array_keys($priceLists))
			->toArray();

		try {
			$customerResult = $customerPriceLists ?
				$this->productsCacheProviderService->getProductsFromCacheTable($filters, $orderByName, $orderByDirection, $customerPriceLists, $visibilityLists, $countCategories) :
				false;
		} catch (\Throwable $e) {
			if ($e->getCode() !== '42S02') {
				throw $e;
			}

			// Table does not exist, try warming it up
			$this->productsCacheDiffUpdateService->updatePricesTableDiff(
				[$customer],
				[],
				[$merchant->getPK()],
			);

			$customerResult = $this->productsCacheProviderService->getProductsFromCacheTable($filters, $orderByName, $orderByDirection, $customerPriceLists, $visibilityLists, $countCategories);
		}

		try {
			$merchantResult = $merchantPriceLists ?
				$this->productsCacheProviderService->getProductsFromCacheTable($filters, $orderByName, $orderByDirection, $merchantPriceLists, $visibilityLists, $countCategories) :
				false;
		} catch (\Throwable $e) {
			if ($e->getCode() !== '42S02') {
				throw $e;
			}

			// Table does not exist, try warming it up
			$this->productsCacheDiffUpdateService->updatePricesTableDiff(
				[$customer],
				[],
				[$merchant->getPK()],
			);

			$merchantResult = $this->productsCacheProviderService->getProductsFromCacheTable($filters, $orderByName, $orderByDirection, $merchantPriceLists, $visibilityLists, $countCategories);
		}

		if ($customerResult === false && $merchantResult === false) {
			return false;
		}

		if ($customerResult === false) {
			return $merchantResult;
		}

		if ($merchantResult === false) {
			return $customerResult;
		}

		// merge results
		// same pks ignore, new pks put in start

		$result = \array_merge(\array_diff($customerResult['productPKs'], $merchantResult['productPKs']), $merchantResult['productPKs']);

		return [
			'productPKs' => $result,
			'attributeValuesCounts' => \array_map(
				fn($key) =>
				($customerResult['attributeValuesCounts'][$key] ?? 0) + ($merchantResult['attributeValuesCounts'][$key] ?? 0),
				\array_keys($customerResult['attributeValuesCounts'] + $merchantResult['attributeValuesCounts'])
			),
			'displayAmountsCounts' => \array_map(
				fn($key) =>
				($customerResult['displayAmountsCounts'][$key] ?? 0) + ($merchantResult['displayAmountsCounts'][$key] ?? 0),
				\array_keys($customerResult['displayAmountsCounts'] + $merchantResult['displayAmountsCounts'])
			),
			'displayDeliveriesCounts' => \array_map(
				fn($key) =>
				($customerResult['displayDeliveriesCounts'][$key] ?? 0) + ($merchantResult['displayDeliveriesCounts'][$key] ?? 0),
				\array_keys($customerResult['displayDeliveriesCounts'] + $merchantResult['displayDeliveriesCounts'])
			),
			'producersCounts' => \array_map(
				fn($key) =>
				($customerResult['producersCounts'][$key] ?? 0) + ($merchantResult['producersCounts'][$key] ?? 0),
				\array_keys($customerResult['producersCounts'] + $merchantResult['producersCounts'])
			),
			'priceMin' => \min($customerResult['priceMin'], $merchantResult['priceMin']),
			'priceMax' => \max($customerResult['priceMax'], $merchantResult['priceMax']),
			'priceVatMin' => \min($customerResult['priceVatMin'], $merchantResult['priceVatMin']),
			'priceVatMax' => \max($customerResult['priceVatMax'], $merchantResult['priceVatMax']),
		];
	}

	public function getIndexByCustomer(Merchant|Customer $customerMerchant): string
	{
		return $this->productsCacheProviderService->getIndexByCustomer($customerMerchant);
	}

	public function addCollectionOrderExpression(string $name, callable $callback): void
	{
		$this->productsCacheProviderService->addCollectionOrderExpression($name, $callback);
	}

	public function addAllowedCollectionFilterColumn(string $name, string $column): void
	{
		$this->productsCacheProviderService->addAllowedCollectionFilterColumn($name, $column);
	}

	public function addFilterCollectionExpression(string $name, callable $callback): void
	{
		$this->productsCacheProviderService->addFilterCollectionExpression($name, $callback);
	}

	public function addAllowedDynamicFilterColumn(string $name, string $column): void
	{
		$this->productsCacheProviderService->addAllowedDynamicFilterColumn($name, $column);
	}

	public function addFilterDynamicExpression(string $name, callable $callback): void
	{
		$this->productsCacheProviderService->addFilterDynamicExpression($name, $callback);
	}

	public function addAllowedCollectionOrderColumn(string $name, string $column): void
	{
		$this->productsCacheProviderService->addAllowedCollectionOrderColumn($name, $column);
	}

	public function updatePricesCacheTable(array $customers = [], array $customerGroups = [], array $merchants = []): void
	{
		$this->productsCacheDiffUpdateService->updatePricesTableDiff($customers, $customerGroups, $merchants);
	}

	/**
	 * @inheritDoc
	 */
	public function getCategoryCount(array $filters, array $priceLists = [], array $visibilityLists = [], bool $debug = false,): int|null
	{
		$category = $filters['category'] ?? null;

		if (!$this->settingsService->isUsingProductsCache() || !$category) {
			return null;
		}

		unset($filters['category']);

		/** @var \Eshop\DB\Category $category */
		$category = $this->categoryRepository->many()->setSelect(['this.id'])->where('this.path', $category)->first(true);

		$dataCacheIndex = \serialize($filters) . '_' . \serialize(\array_keys($priceLists)) . '_' . \serialize(\array_keys($visibilityLists));
		$dataCacheIndex = \md5($dataCacheIndex);

		if (isset($this->cachedCategoryCounts[$dataCacheIndex])) {
			return $this->cachedCategoryCounts[$dataCacheIndex][$category->id] ?? null;
		}

		$result = $this->getProductsFromCacheTable(
			$filters,
			priceLists: $priceLists,
			visibilityLists: $visibilityLists,
			debug: $debug,
			countCategories: true,
		);

		$this->cachedCategoryCounts[$dataCacheIndex] = $result['categoriesCounts'] ?? [];

		return $this->cachedCategoryCounts[$dataCacheIndex][$category->id] ?? null;
	}
}
