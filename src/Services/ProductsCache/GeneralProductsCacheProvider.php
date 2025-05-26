<?php

namespace Eshop\Services\ProductsCache;

use DaveLiddament\PhpLanguageExtensions\InjectableVersion;
use Eshop\DB\Customer;
use Eshop\DB\Merchant;

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
	 * @param array<string|\Eshop\DB\Customer> $customers
	 * @param array<string|int> $customerGroups
	 * @param array<string|int> $merchants
	 */
	public function updatePricesCacheTable(array $customers = [], array $customerGroups = [], array $merchants = []): void;

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
		bool $debug = false,
	): array|false;

	public function getIndexByCustomer(Customer|Merchant $customerMerchant): string;

	public function addCollectionOrderExpression(string $name, callable $callback): void;

	public function addAllowedCollectionFilterColumn(string $name, string $column): void;

	public function addFilterCollectionExpression(string $name, callable $callback): void;

	public function addAllowedDynamicFilterColumn(string $name, string $column): void;

	public function addFilterDynamicExpression(string $name, callable $callback): void;

	public function addAllowedCollectionOrderColumn(string $name, string $column): void;
}
