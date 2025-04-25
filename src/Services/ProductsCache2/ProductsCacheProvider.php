<?php

namespace Eshop\Services\ProductsCache2;

use Eshop\DB\PricelistRepository;
use Eshop\ShopperUser;
use Nette\Utils\Arrays;

/**
 * Main service to work with a cache of products. If possible, always use this service.
 */
class ProductsCacheProvider implements GeneralProductsCacheProvider
{
	private bool|null $isReady = null;

	public function __construct(
		private readonly ProductsCacheGetterService $productsCacheProviderService,
		private readonly ProductsCacheDiffUpdateService $productsCacheDiffUpdateService,
		private readonly ShopperUser $shopperUser,
		private readonly PricelistRepository $pricelistRepository,
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
	): array|false {
		$priceLists = $priceLists ?: $this->shopperUser->getPriceListsCached();
		$visibilityLists = $visibilityLists ?: $this->shopperUser->getVisibilityLists();
		$customer = $this->shopperUser->getCustomer();
		$merchant = $this->shopperUser->getMerchant();

		if ($this->isReady === null) {
			$this->isReady = $this->productsCacheProviderService->isReady();
		}

		if (!$this->isReady) {
			throw new ProductsCacheNotReadyException();
		}

		if (isset($filters['pricelist'])) {
			$priceLists = \array_filter($priceLists, fn($priceList) => Arrays::contains($filters['pricelist'], $priceList), \ARRAY_FILTER_USE_KEY);
		}

		if ($merchant?->priceListsMode !== 'merge' || !$customer) {
			return $this->productsCacheProviderService->getProductsFromCacheTable($filters, $orderByName, $orderByDirection, $priceLists, $visibilityLists);
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

		$customerResult = $customerPriceLists ?
			$this->productsCacheProviderService->getProductsFromCacheTable($filters, $orderByName, $orderByDirection, $customerPriceLists, $visibilityLists) :
			false;

		$merchantResult = $merchantPriceLists ?
			$this->productsCacheProviderService->getProductsFromCacheTable($filters, $orderByName, $orderByDirection, $merchantPriceLists, $visibilityLists) :
			false;

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
}
