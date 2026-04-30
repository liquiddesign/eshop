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
	 * Vrací seznam PKs produktů, kteří jsou alespoň pro jednu existující kombinaci
	 * (customer × pricelist × visibilityList × merchant) prodejní — tedy mají platnou
	 * cenu v nějaké aktivní cenové hladině, která se na někoho vztahuje.
	 *
	 * Určeno pro externí exportéry (Algolia, feed generátory), které musí vyloučit
	 * produkty, za které by zbytečně platili poplatky za indexaci.
	 * @return list<string> PKs produktů v `eshop_product.uuid`
	 */
	public function getSellableProductPKs(): array;

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
	 * @throws \StORM\Exception\NotFoundException|\Throwable
	 */
	public function getProductsFromCacheTable(
		array $filters,
		string|null $orderByName = null,
		string $orderByDirection = 'ASC',
		array $priceLists = [],
		array $visibilityLists = [],
		bool $debug = false,
		bool $countCategories = false,
	): array|false;

	/**
	 * @param array<mixed> $filters
	 * @param array<string|int, \Eshop\DB\Pricelist> $priceLists
	 * @param array<string|int, \Eshop\DB\VisibilityList> $visibilityLists
	 */
	public function getCategoryCount(
		array $filters,
		array $priceLists = [],
		array $visibilityLists = [],
		bool $debug = false,
	): int|null;

	public function getIndexByCustomer(Customer|Merchant $customerMerchant): string;

	/**
	 * Nastaví pořadí UUIDů pro `uuidField` ordering (assistant create-order, UUID-based listing).
	 * Volá se místo `addCollectionOrderExpression('uuidField', closure)`.
	 * @param list<string> $uuids
	 */
	public function setUuidOrdering(array $uuids): void;

	/**
	 * Fire-and-forget požadavek na rebuild snapshotu produktové cache. Volat na konci úspěšné
	 * mutační cesty (po commit/save) — import cen, hromadná admin akce, QI sync. Drift probe
	 * v Rust daemonu nemá visibility na in-place UPDATE existujících řádků v `eshop_price`,
	 * tohle je explicitní wakeup ze strany PHP.
	 *
	 * Implementace musí být no-op pro neaktivní providery a tichá při výpadku daemonu —
	 * caller nemá důvod o tom vědět ani exception řešit.
	 */
	public function requestSnapshotRebuild(): void;
}
