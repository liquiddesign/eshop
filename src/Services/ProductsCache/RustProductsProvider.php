<?php

declare(strict_types=1);

namespace Eshop\Services\ProductsCache;

use Eshop\Controls\ProductFilter;
use Eshop\DB\AttributeRepository;
use Eshop\DB\AttributeValueRepository;
use Eshop\DB\CategoryRepository;
use Eshop\DB\Customer;
use Eshop\DB\Merchant;
use Eshop\DB\Pricelist;
use Eshop\DB\ProductRepository;
use Eshop\DB\VisibilityList;
use Eshop\ShopperUser;
use Nette\Caching\Cache;
use Nette\Caching\Storage;
use Nette\Utils\Arrays;
use Nette\Utils\Strings;
use Tracy\Debugger;

/**
 * Provider forwarding product catalog queries to the Rust daemon (`abel-products-daemon`).
 * Daemon errors propagate to the caller — no fallback, no LiveProductsProvider dependency.
 * @internal Use {@see GeneralProductsCacheProvider} for injection.
 */
final class RustProductsProvider implements GeneralProductsCacheProvider
{
	/**
	 * Agregovaný per-request log volání pro {@see RustDaemonBarPanel}. Klíč je `method|status|reason`.
	 * @var array<string, array{method: string, status: string, reason: string, count: int, totalMs: float, maxMs: float}>
	 */
	public static array $callLog = [];

	/**
	 * Persistent Nette cache pro `getCategoryCount` výsledky. Mapa `categoryUuid → count`
	 * pro daný filter-set. Tagy `['products', 'categories']` — invaliduje spolu s produkty.
	 */
	private readonly Cache $categoryCountCache;

	/**
	 * Per-request memoizace `getCategoryCount` — celá mapa `categoryUuid → count` na filter-set.
	 * @var array<string, array<string, int>>
	 */
	private array $cachedCategoryCountsMap = [];

	/**
	 * @var array<string, array{batchCacheKey: string, priceListPKs: list<string>, visibilityListPKs: list<string>, priceVisibility: array<string, bool>, filtersForBatch: array<mixed>}>
	 */
	private array $resolvedBatchStateCache = [];

	/** @var list<string>|null Per-request memoizace favourite pricelist UUIDů zákazníka. */
	private array|null $favouritePricelistUuidsCache = null;

	/** @var array<string, string|null> */
	private array $categoryPathToUuidCache = [];

	/** @var list<string> UUID pořadí pro `uuidField` ordering — nastaveno přes setUuidOrdering(). */
	private array $pendingOrderUuids = [];

	public function __construct(
		private readonly RustDaemonClient $client,
		private readonly ShopperUser $shopperUser,
		private readonly ProductRepository $productRepository,
		private readonly CategoryRepository $categoryRepository,
		private readonly AttributeRepository $attributeRepository,
		private readonly AttributeValueRepository $attributeValueRepository,
		Storage $storage,
	) {
		$this->categoryCountCache = new Cache($storage, 'rustProductsCategoryCounts');
	}

	/**
	 * @return list<string>
	 */
	public function getSellableProductPKs(): array
	{
		return $this->client->getSellableProductPKs();
	}

	public function getProductsFromCacheTable(
		array $filters,
		string|null $orderByName = null,
		string $orderByDirection = 'ASC',
		array $priceLists = [],
		array $visibilityLists = [],
		bool $debug = false,
		bool $countCategories = false,
	): array|false {
		$startNs = \hrtime(true);

		[$priceLists, $visibilityLists] = $this->resolveListsFromShopperUser($priceLists, $visibilityLists, $filters);
		unset($filters['pricelist']);

		$attributesExpansionError = $this->expandAttributesFilter($filters);

		if ($attributesExpansionError !== null) {
			throw new \RuntimeException('RustProductsProvider: attributes expansion failed: ' . $attributesExpansionError);
		}

		foreach (['query', 'query2'] as $unsupportedKey) {
			if (\array_key_exists($unsupportedKey, $filters)) {
				throw new \RuntimeException("RustProductsProvider: filter '$unsupportedKey' is not supported — use a search backend (Algolia/Meilisearch)");
			}
		}

		// V dev módu vždy zapneme `debug: true` aby daemon vracel `timings` breakdown — Tracy
		// panel je registrovaný jen mimo production mode (viz `ShopperDI::afterCompile()`),
		// takže overhead měření se neprojeví v produkci. Volající explicitní `$debug=true`
		// respektujeme i bez Tracy.
		$wantTimings = $debug || !Debugger::$productionMode;

		$request = $this->buildRequest($filters, $orderByName, $orderByDirection, $priceLists, $visibilityLists, $wantTimings, $countCategories);
		$response = $this->client->getProducts($request);
		$decoded = $this->decodeGetProductsResponse($response);

		if (isset($response['timings']) && \is_array($response['timings'])) {
			$this->recordTimings($response['timings']);
		}

		$summary = [
			'request' => $request,
			'response_summary' => [
				'productPKs_count' => \count($decoded['productPKs']),
				'priceMin' => $decoded['priceMin'],
				'priceMax' => $decoded['priceMax'],
				'priceVatMin' => $decoded['priceVatMin'],
				'priceVatMax' => $decoded['priceVatMax'],
				'producersCounts_keys' => \count($decoded['producersCounts']),
				'attributeValuesCounts_keys' => \count($decoded['attributeValuesCounts']),
				'displayAmountsCounts_keys' => \count($decoded['displayAmountsCounts']),
				'displayDeliveriesCounts_keys' => \count($decoded['displayDeliveriesCounts']),
				'categoriesCounts_keys' => isset($decoded['categoriesCounts']) ? \count($decoded['categoriesCounts']) : null,
			],
			'first_10_productPKs' => \array_slice($decoded['productPKs'], 0, 10),
		];

		Debugger::log('rust-daemon.trace ' . \json_encode($summary, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES), 'rust-daemon');

		self::recordCall('getProductsFromCacheTable', 'daemon', '', (\hrtime(true) - $startNs) / 1000000);

		return $decoded;
	}

	public function getCategoryCount(
		array $filters,
		array $priceLists = [],
		array $visibilityLists = [],
		bool $debug = false,
	): int|null {
		unset($debug);

		$startNs = \hrtime(true);

		$categoryPath = isset($filters['category']) && $filters['category'] !== null
			? (string) $filters['category']
			: null;

		if ($categoryPath === null) {
			return 0;
		}

		$filtersForBatch = $filters;
		unset($filtersForBatch['category'], $filtersForBatch['pricelist']);

		$stateFingerprint = \serialize($filtersForBatch)
			. '|' . ($priceLists === [] ? '*' : \implode(',', \array_map(\spl_object_id(...), $priceLists)))
			. '|' . ($visibilityLists === [] ? '*' : \implode(',', \array_map(\spl_object_id(...), $visibilityLists)));

		if (isset($this->resolvedBatchStateCache[$stateFingerprint])) {
			$state = $this->resolvedBatchStateCache[$stateFingerprint];
		} else {
			[$priceLists, $visibilityLists] = $this->resolveListsFromShopperUser($priceLists, $visibilityLists, $filters);

			$priceListPKs = \array_values(\array_map(static fn (Pricelist $p): string => (string) $p->getPK(), $priceLists));
			$visibilityListPKs = \array_values(\array_map(static fn (VisibilityList $v): string => (string) $v->getPK(), $visibilityLists));
			$priceVisibility = [
				'showZeroPrices' => $this->shopperUser->getShowZeroPrices(),
				'showVat' => $this->shopperUser->getShowVat(),
				'showWithoutVat' => $this->shopperUser->getShowWithoutVat(),
				'includeHiddenPrices' => $this->shopperUser->canViewHiddenPrices(),
			];
			$batchCacheKey = \md5(\serialize([$filtersForBatch, $priceListPKs, $visibilityListPKs, $priceVisibility]));

			$state = [
				'batchCacheKey' => $batchCacheKey,
				'priceListPKs' => $priceListPKs,
				'visibilityListPKs' => $visibilityListPKs,
				'priceVisibility' => $priceVisibility,
				'filtersForBatch' => $filtersForBatch,
			];

			$this->resolvedBatchStateCache[$stateFingerprint] = $state;
		}

		$batchCacheKey = $state['batchCacheKey'];

		$categoryUuid = $this->resolveCategoryPathToUuid($categoryPath);

		if ($categoryUuid === null) {
			return 0;
		}

		if (isset($this->cachedCategoryCountsMap[$batchCacheKey])) {
			self::recordCall('getCategoryCount', 'daemon', 'memo_hit', (\hrtime(true) - $startNs) / 1000000);

			return $this->cachedCategoryCountsMap[$batchCacheKey][$categoryUuid] ?? 0;
		}

		/** @var array<string, int>|null $cached */
		$cached = $this->categoryCountCache->load($batchCacheKey);

		if ($cached !== null && \is_array($cached)) {
			$this->cachedCategoryCountsMap[$batchCacheKey] = $cached;
			self::recordCall('getCategoryCount', 'daemon', 'cache_hit', (\hrtime(true) - $startNs) / 1000000);

			return $cached[$categoryUuid] ?? 0;
		}

		$request = [
			'pricelistPks' => $state['priceListPKs'],
			'visibilityListPks' => $state['visibilityListPKs'],
			'filters' => $this->mapFilters($state['filtersForBatch']),
			'dynamicFilterAttributes' => isset($state['filtersForBatch']['attributeValue']) && \is_array($state['filtersForBatch']['attributeValue'])
				? $state['filtersForBatch']['attributeValue']
				: null,
			'priceVisibility' => $state['priceVisibility'],
		];

		$map = $this->client->getAllCategoryCounts($request);

		$this->cachedCategoryCountsMap[$batchCacheKey] = $map;
		$this->categoryCountCache->save($batchCacheKey, $map, [
			Cache::Expire => '1 hour',
			Cache::Tags => ['products', 'categories'],
		]);

		self::recordCall('getCategoryCount', 'daemon', '', (\hrtime(true) - $startNs) / 1000000);

		return $map[$categoryUuid] ?? 0;
	}

	public function getIndexByCustomer(Customer|Merchant $customerMerchant): string
	{
		unset($customerMerchant);

		return 'rust';
	}

	/**
	 * Nastaví pořadí UUIDů pro `uuidField` ordering. Volá se místo `addCollectionOrderExpression('uuidField', ...)`.
	 * @param list<string> $uuids
	 */
	public function setUuidOrdering(array $uuids): void
	{
		$this->pendingOrderUuids = \array_values($uuids);
	}

	public function requestSnapshotRebuild(): void
	{
		$startNs = \hrtime(true);
		$accepted = $this->client->requestRebuild();
		$durationMs = (\hrtime(true) - $startNs) / 1000000;

		Debugger::log(\sprintf(
			'rust-daemon.requestRebuild accepted=%s durationMs=%.3f',
			$accepted ? 'yes' : 'no',
			$durationMs,
		), 'rust-daemon');

		self::recordCall('requestSnapshotRebuild', 'daemon', $accepted ? '' : 'unavailable', $durationMs);
	}

	public static function recordCall(string $method, string $status, string $reason, float $durationMs): void
	{
		$key = $method . '|' . $status . '|' . $reason;

		if (!isset(self::$callLog[$key])) {
			self::$callLog[$key] = [
				'method' => $method,
				'status' => $status,
				'reason' => $reason,
				'count' => 0,
				'totalMs' => 0.0,
				'maxMs' => 0.0,
			];
		}

		++self::$callLog[$key]['count'];
		self::$callLog[$key]['totalMs'] += $durationMs;

		if ($durationMs <= self::$callLog[$key]['maxMs']) {
			return;
		}

		self::$callLog[$key]['maxMs'] = $durationMs;
	}

	/**
	 * Zaloguje per-step timings z daemonu jako sub-rows v Tracy panelu. Klíče matchují
	 * `TimingsBreakdown` v daemonovém protokolu. Status `daemon-step` je distinct od
	 * `daemon` — panel řadí podle totalMs, takže sub-rows skončí logicky pod hlavním row.
	 * @param array<string, mixed> $timings
	 */
	private function recordTimings(array $timings): void
	{
		$keys = ['parseMs', 'baseMaskMs', 'bitmapFiltersMs', 'pricingMs', 'facetsMs', 'orderingMs', 'serializeMs'];

		foreach ($keys as $key) {
			if (!isset($timings[$key]) || !\is_numeric($timings[$key])) {
				continue;
			}

			$label = 'getProductsFromCacheTable.' . Strings::substring($key, 0, -2);
			self::recordCall($label, 'daemon-step', '', (float) $timings[$key]);
		}
	}

	private function resolveCategoryPathToUuid(string $path): string|null
	{
		if (\array_key_exists($path, $this->categoryPathToUuidCache)) {
			return $this->categoryPathToUuidCache[$path];
		}

		$uuids = $this->resolveCategoryPathsToUuids([$path]);

		return $uuids === [] ? null : $uuids[0];
	}

	/**
	 * @param array<string|int, \Eshop\DB\Pricelist> $priceLists
	 * @param array<string|int, \Eshop\DB\VisibilityList> $visibilityLists
	 * @param array<mixed> $filters
	 * @return array{0: list<\Eshop\DB\Pricelist>, 1: list<\Eshop\DB\VisibilityList>}
	 */
	private function resolveListsFromShopperUser(array $priceLists, array $visibilityLists, array $filters): array
	{
		if ($priceLists === []) {
			$priceLists = $this->shopperUser->getPriceListsCached();
		}

		if ($visibilityLists === []) {
			$visibilityLists = $this->shopperUser->getVisibilityLists();
		}

		if (isset($filters['pricelist'])) {
			$allowed = (array) $filters['pricelist'];
			$priceLists = \array_filter($priceLists, static fn (Pricelist $p): bool => Arrays::contains($allowed, (string) $p->getPK()));
		}

		$priceLists = \array_values($priceLists);
		$visibilityLists = \array_values($visibilityLists);

		\usort($priceLists, static fn (Pricelist $a, Pricelist $b): int => ($a->priority ?? 10) <=> ($b->priority ?? 10));

		return [$priceLists, $visibilityLists];
	}

	/**
	 * @param array<mixed> $filters
	 * @param array<string|int, \Eshop\DB\Pricelist> $priceLists
	 * @param array<string|int, \Eshop\DB\VisibilityList> $visibilityLists
	 * @return array<string, mixed>
	 */
	private function buildRequest(
		array $filters,
		string|null $orderByName,
		string $orderByDirection,
		array $priceLists,
		array $visibilityLists,
		bool $debug,
		bool $countCategories,
	): array {
		$contractRibbonPk = $this->extractRibbonPk($filters['contract'] ?? null);
		$notPublicRibbonPk = $this->extractRibbonPk($filters['notPublic'] ?? null);
		$projectFilter = $this->extractProjectFilter($filters['project'] ?? null);
		$orderUuids = $this->extractOrderUuids($filters, $orderByName);
		$needsFavourites = $contractRibbonPk !== null || $notPublicRibbonPk !== null;
		$favouritePricelistPks = $needsFavourites ? $this->resolveFavouritePricelistUuids() : [];

		return [
			'pricelistPks' => \array_values(\array_map(static fn (Pricelist $p): string => (string) $p->getPK(), $priceLists)),
			'visibilityListPks' => \array_values(\array_map(static fn (VisibilityList $v): string => (string) $v->getPK(), $visibilityLists)),
			'filters' => $this->mapFilters($filters),
			'dynamicFilterAttributes' => isset($filters['attributeValue']) && \is_array($filters['attributeValue'])
				? $filters['attributeValue']
				: null,
			'priceModifiers' => $this->buildPriceModifiers(),
			'priceVisibility' => [
				'showZeroPrices' => $this->shopperUser->getShowZeroPrices(),
				'showVat' => $this->shopperUser->getShowVat(),
				'showWithoutVat' => $this->shopperUser->getShowWithoutVat(),
				'includeHiddenPrices' => $this->shopperUser->canViewHiddenPrices(),
			],
			'orderBy' => $orderByName,
			'orderDirection' => Strings::upper($orderByDirection) === 'DESC' ? 'DESC' : 'ASC',
			'countCategories' => $countCategories,
			'debug' => $debug,
			'orderUuids' => $orderUuids,
			'favouritePricelistPks' => $favouritePricelistPks,
			'contractRibbonPk' => $contractRibbonPk,
			'notPublicRibbonPk' => $notPublicRibbonPk,
			'projectFilter' => $projectFilter,
		];
	}

	/**
	 * @param mixed $filterValue Očekávaná struktura: `array{0: mixed, 1: object}` s metodou `getPK()`.
	 */
	private function extractRibbonPk(mixed $filterValue): string|null
	{
		if (!\is_array($filterValue) || !isset($filterValue[1])) {
			return null;
		}

		$ribbon = $filterValue[1];

		if (!\is_object($ribbon) || !\method_exists($ribbon, 'getPK')) {
			return null;
		}

		$pk = $ribbon->getPK();

		return $pk !== null ? (string) $pk : null;
	}

	/**
	 * @param mixed $filterValue
	 * @return array{customerIc: string|null, isMerchant: bool}|null
	 */
	private function extractProjectFilter(mixed $filterValue): array|null
	{
		if (!\is_array($filterValue)) {
			return null;
		}

		$customerIc = isset($filterValue['customerIc']) && \is_string($filterValue['customerIc'])
			? $filterValue['customerIc']
			: null;
		$isMerchant = isset($filterValue['isMerchant']) && $filterValue['isMerchant'] === true;

		return ['customerIc' => $customerIc, 'isMerchant' => $isMerchant];
	}

	/**
	 * Extract seznam UUIDů pro `uuidField` ordering. Dva zdroje (první, který má hodnotu, vyhrává):
	 * 1. `$filters['_orderUuids']` — explicit kontrakt z presenteru.
	 * 2. `$this->pendingOrderUuids` — nastaveno přes `setUuidOrdering()`.
	 * @param array<mixed> $filters
	 * @return list<string>|null
	 */
	private function extractOrderUuids(array $filters, string|null $orderByName): array|null
	{
		if ($orderByName !== 'uuidField') {
			return null;
		}

		$raw = $filters['_orderUuids'] ?? null;

		if (\is_array($raw)) {
			$out = [];

			foreach ($raw as $uuid) {
				if (\is_string($uuid) && $uuid !== '') {
					$out[] = $uuid;
				}
			}

			if ($out !== []) {
				return $out;
			}
		}

		return $this->pendingOrderUuids !== [] ? $this->pendingOrderUuids : null;
	}

	/** @return list<string> */
	private function resolveFavouritePricelistUuids(): array
	{
		if ($this->favouritePricelistUuidsCache !== null) {
			return $this->favouritePricelistUuidsCache;
		}

		$customer = $this->shopperUser->getCustomer();

		if ($customer === null) {
			$this->favouritePricelistUuidsCache = [];

			return [];
		}

		$pricelists = $customer->favouritePriceLists->toArray();
		$uuids = [];

		foreach ($pricelists as $pricelist) {
			$pk = $pricelist->getPK();

			if ($pk === '') {
				continue;
			}

			$uuids[] = (string) $pk;
		}

		$this->favouritePricelistUuidsCache = $uuids;

		return $uuids;
	}

	/**
	 * @return array{
	 *     discountLevelPct: int,
	 *     maxProductDiscountLevel: int,
	 *     surchargeLevelPct: float,
	 *     currencyRate: float|null,
	 *     calculationPrecision: int
	 * }
	 */
	private function buildPriceModifiers(): array
	{
		$discountCoupon = $this->shopperUser->getCheckoutManager()->getDiscountCoupon();
		$customer = $this->shopperUser->getCustomer();
		$customerGroup = $this->shopperUser->getCustomerGroup();

		$discountLevelPct = $this->productRepository->getDiscountPct($customer, $customerGroup, $discountCoupon);
		$maxProductDiscountLevel = (int) ($customer->maxDiscountProductPct ?? $customerGroup->defaultMaxDiscountProductPct ?? 100);
		$surchargeLevelPct = $this->productRepository->getSurchargePct($customer);

		$currency = $this->shopperUser->getCurrency();
		$convertRatio = $currency->isConversionEnabled() ? (float) $currency->convertRatio : null;

		return [
			'discountLevelPct' => $discountLevelPct,
			'maxProductDiscountLevel' => $maxProductDiscountLevel,
			'surchargeLevelPct' => $surchargeLevelPct,
			'currencyRate' => $convertRatio,
			'calculationPrecision' => (int) $currency->calculationPrecision,
		];
	}

	/**
	 * @param list<string> $paths
	 * @return list<string>
	 */
	private function resolveCategoryPathsToUuids(array $paths): array
	{
		$out = [];
		$missing = [];

		foreach ($paths as $path) {
			if (\array_key_exists($path, $this->categoryPathToUuidCache)) {
				$uuid = $this->categoryPathToUuidCache[$path];

				if ($uuid !== null) {
					$out[] = $uuid;
				}

				continue;
			}

			$missing[] = $path;
		}

		if ($missing !== []) {
			/** @var array<string, string> $rows path → uuid */
			$rows = $this->categoryRepository->many()
				->where('path', $missing)
				->select(['path', 'uuid'])
				->setIndex('path')
				->toArrayOf('uuid');

			foreach ($missing as $path) {
				$uuid = $rows[$path] ?? null;
				$this->categoryPathToUuidCache[$path] = $uuid;

				if ($uuid === null) {
					continue;
				}

				$out[] = $uuid;
			}
		}

		return $out;
	}

	/**
	 * @param array<mixed> $filters
	 */
	private function mapFilters(array $filters): \stdClass
	{
		$out = new \stdClass();

		if (isset($filters['category'])) {
			$rawPaths = \is_array($filters['category']) ? \array_values($filters['category']) : [(string) $filters['category']];
			$out->categoryUuids = $this->resolveCategoryPathsToUuids($rawPaths);
		}

		$producerUuids = $this->collectUuids($filters, ['producer', 'producers', 'systemicAttributes.producer']);

		if ($producerUuids !== []) {
			$out->producerUuids = $producerUuids;
		}

		$displayAmountUuids = $this->collectUuids($filters, ['displayAmount', 'systemicAttributes.availability']);

		if ($displayAmountUuids !== []) {
			$out->displayAmountUuids = $displayAmountUuids;
		}

		$displayDeliveryUuids = $this->collectUuids($filters, ['displayDelivery', 'systemicAttributes.delivery']);

		if ($displayDeliveryUuids !== []) {
			$out->displayDeliveryUuids = $displayDeliveryUuids;
		}

		if (isset($filters['priceFrom'])) {
			$out->priceFrom = (float) $filters['priceFrom'];
		}

		if (isset($filters['priceTo'])) {
			$out->priceTo = (float) $filters['priceTo'];
		}

		if (isset($filters['priceGt'])) {
			$out->priceGt = (float) $filters['priceGt'];
		}

		foreach ([
				'ribbon' => 'ribbonUuids',
				'notRibbon' => 'notRibbonUuids',
				'internalRibbon' => 'internalRibbonUuids',
				'notInternalRibbon' => 'notInternalRibbonUuids',
			] as $key => $wireField
		) {
			if (!isset($filters[$key])) {
				continue;
			}

			$ribbons = \array_values(\array_map('strval', (array) $filters[$key]));

			if ($ribbons === []) {
				continue;
			}

			$out->{$wireField} = $ribbons;
		}

		if (isset($filters['uuids'])) {
			$uuids = \array_values(\array_map('strval', (array) $filters['uuids']));

			if ($uuids !== []) {
				$out->uuids = $uuids;
			}
		}

		foreach (['hidden', 'hiddenInMenu', 'recommended', 'unavailable'] as $boolKey) {
			if (!\array_key_exists($boolKey, $filters)) {
				continue;
			}

			$raw = $filters[$boolKey];
			$out->{$boolKey} = $raw === true || $raw === 1 || $raw === '1';
		}

		if (isset($filters['isSold'])) {
			$out->isSold = (int) $filters['isSold'];
		}

		if (\array_key_exists('masterProduct', $filters)) {
			$raw = $filters['masterProduct'];

			if ($raw === true) {
				$out->masterProduct = true;
			} elseif ($raw === false) {
				$out->masterProduct = false;
			}
		}

		if (isset($filters['inStock']) && $filters['inStock']) {
			$out->inStock = true;
		}

		if (isset($filters['related']) && \is_array($filters['related'])) {
			$raw = $filters['related'];
			$exclude = isset($raw['uuid']) && \is_string($raw['uuid']) ? $raw['uuid'] : null;
			$category = isset($raw['category']) && \is_string($raw['category']) ? $raw['category'] : null;

			if ($exclude !== null && $category !== null && $exclude !== '' && $category !== '') {
				$out->related = [
					'excludeUuid' => $exclude,
					'primaryCategoryUuid' => $category,
					'mainCategoryTypePk' => (string) $this->shopperUser->getMainCategoryType()->getPK(),
				];
			}
		}

		if (isset($filters['relatedSlave']) && \is_array($filters['relatedSlave'])) {
			$raw = $filters['relatedSlave'];
			$typeUuid = isset($raw[0]) ? (string) $raw[0] : '';
			$masterUuid = isset($raw[1]) ? (string) $raw[1] : '';

			if ($typeUuid !== '' && $masterUuid !== '') {
				$out->relatedSlave = [
					'typeUuid' => $typeUuid,
					'masterUuid' => $masterUuid,
				];
			}
		}

		if (isset($filters['crossSellFilter']) && \is_array($filters['crossSellFilter'])) {
			$raw = $filters['crossSellFilter'];
			$path = isset($raw[0]) ? (string) $raw[0] : '';
			$excludeUuid = isset($raw[1]) ? (string) $raw[1] : '';

			if ($path !== '' && $excludeUuid !== '') {
				$out->crossSell = [
					'path' => $path,
					'excludeUuid' => $excludeUuid,
				];
			}
		}

		return $out;
	}

	/**
	 * @param array<mixed> $filters Modifikuje se in-place.
	 */
	private function expandAttributesFilter(array &$filters): string|null
	{
		if (!isset($filters['attributes']) || !\is_array($filters['attributes'])) {
			return null;
		}

		$attributes = $filters['attributes'];
		unset($filters['attributes']);

		foreach ($attributes as $attributeKey => $selectedValues) {
			if (Arrays::contains(\array_keys(ProductFilter::SYSTEMIC_ATTRIBUTES), $attributeKey)) {
				$filters["systemicAttributes.$attributeKey"] = \is_array($selectedValues)
					? $selectedValues
					: [(string) $selectedValues];

				continue;
			}

			try {
				/** @var \Eshop\DB\Attribute $attribute */
				$attribute = $this->attributeRepository->one((string) $attributeKey, true);
			} catch (\Throwable $e) {
				return 'attributes_expansion_failed:unknown_attribute:' . (string) $attributeKey;
			}

			$attributePk = (string) $attribute->getPK();
			$values = [];

			try {
				if ($attribute->showRange) {
					$rangeIds = \is_array($selectedValues) ? $selectedValues : [$selectedValues];
					$values = \array_values($this->attributeValueRepository->getConnection()
						->rows(['eshop_attributevalue'])
						->where('eshop_attributevalue.fk_attributevaluerange', $rangeIds)
						->where('eshop_attributevalue.fk_attribute', $attributePk)
						->toArrayOf('uuid'));
				} elseif ($attribute->showNumericSlider && \is_array($selectedValues)) {
					$query = $this->attributeValueRepository->many();

					if (isset($selectedValues['from'])) {
						$query->where('this.number >= :from OR this.numberFrom >= :from', ['from' => $selectedValues['from']]);
					}

					if (isset($selectedValues['to'])) {
						$query->where('this.number <= :to OR this.numberTo <= :to', ['to' => $selectedValues['to']]);
					}

					$values = \array_values($query->where('this.fk_attribute', $attributePk)
						->toArrayOf('uuid', toArrayValues: true));

					if ($values === []) {
						continue;
					}
				} else {
					$values = \is_array($selectedValues)
						? \array_values(\array_map(static fn ($v): string => (string) $v, $selectedValues))
						: [(string) $selectedValues];
				}
			} catch (\Throwable $e) {
				return 'attributes_expansion_failed:value_lookup:' . (string) $attributeKey;
			}

			if ($values === []) {
				continue;
			}

			if (!isset($filters['attributeValue']) || !\is_array($filters['attributeValue'])) {
				$filters['attributeValue'] = [];
			}

			if (!isset($filters['attributeValue'][$attributePk]) || !\is_array($filters['attributeValue'][$attributePk])) {
				$filters['attributeValue'][$attributePk] = [];
			}

			foreach ($values as $v) {
				$filters['attributeValue'][$attributePk][] = (string) $v;
			}

			$filters['attributeValue'][$attributePk] = \array_values(\array_unique($filters['attributeValue'][$attributePk]));
		}

		return null;
	}

	/**
	 * @param array<mixed> $filters
	 * @param list<string> $keys
	 * @return list<string>
	 */
	private function collectUuids(array $filters, array $keys): array
	{
		$merged = [];

		foreach ($keys as $key) {
			if (!isset($filters[$key])) {
				continue;
			}

			$value = $filters[$key];

			if (\is_array($value)) {
				foreach ($value as $item) {
					if (\is_string($item) && $item !== '') {
						$merged[] = $item;
					}
				}
			} elseif (\is_string($value) && $value !== '') {
				$merged[] = $value;
			}
		}

		return \array_values(\array_unique($merged));
	}

	/**
	 * @param array<string, mixed> $resp
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
	 * }
	 */
	private function decodeGetProductsResponse(array $resp): array
	{
		$out = [
			'productPKs' => \array_values((array) ($resp['productPks'] ?? [])),
			'attributeValuesCounts' => (array) ($resp['attributeValuesCounts'] ?? []),
			'displayAmountsCounts' => (array) ($resp['displayAmountsCounts'] ?? []),
			'displayDeliveriesCounts' => (array) ($resp['displayDeliveriesCounts'] ?? []),
			'producersCounts' => (array) ($resp['producersCounts'] ?? []),
			'priceMin' => (float) ($resp['priceMin'] ?? 0),
			'priceMax' => (float) ($resp['priceMax'] ?? 0),
			'priceVatMin' => (float) ($resp['priceVatMin'] ?? 0),
			'priceVatMax' => (float) ($resp['priceVatMax'] ?? 0),
		];

		if (isset($resp['categoriesCounts']) && \is_array($resp['categoriesCounts'])) {
			$out['categoriesCounts'] = $resp['categoriesCounts'];
		}

		return $out;
	}
}
