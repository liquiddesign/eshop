<?php

namespace Eshop\Services\ProductsCache;

use DaveLiddament\PhpLanguageExtensions\InjectableVersion;

#[InjectableVersion]
interface GeneralProductsCacheProvider
{
	public const PRODUCTS_PROVIDER_CACHE_TAG = 'productsProviderCache';

	/**
	 * @param array<string|\Eshop\DB\Customer> $customers
	 * @param array<string|int> $customerGroups
	 * @param array<string|int> $merchants
	 */
	public function warmUpCacheTable(array $customers = [], array $customerGroups = [], array $merchants = []): void;

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
	 * @throws \StORM\Exception\NotFoundException|\Throwable
	 */
	public function getProductsFromCacheTable(
		array $filters,
		string|null $orderByName = null,
		string $orderByDirection = 'ASC',
		array $priceLists = [],
		array $visibilityLists = [],
	): array|false;
}
