<?php

namespace Eshop\Services\ProductsCache;

/**
 * Main service to work with cache of products. If possible, always use this service.
 */
readonly class ProductsCacheProvider implements GeneralProductsCacheProvider
{
	public function __construct(
		protected ProductsCacheWarmUpService $productsCacheWarmUpService,
		protected ProductsCacheGetterService $productsCacheProviderService,
	) {
	}

	public function warmUpCacheTable(): void
	{
		$this->productsCacheWarmUpService->warmUpCacheTable();
	}

	/**
	 * Works like warmUpCacheTable, but don't erase all data.
	 */
	public function warmUpCacheTableDiff(): void
	{
		$this->productsCacheWarmUpService->warmUpCacheTableDiff();
	}

	/**
	 * @inheritDoc
	 */
	public function getProductsFromCacheTable(array $filters, ?string $orderByName = null, string $orderByDirection = 'ASC', array $priceLists = [], array $visibilityLists = [],): array|false
	{
		return $this->productsCacheProviderService->getProductsFromCacheTable($filters, $orderByName, $orderByDirection, $priceLists, $visibilityLists);
	}
}
