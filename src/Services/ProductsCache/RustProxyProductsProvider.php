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
use Tracy\ILogger;

/**
 * Proxy provider that forwards product catalog queries to the Rust daemon (`abel-products-daemon`)
 * and transparently falls back to {@see LiveProductsProvider} on any daemon failure.
 *
 * Fallback triggers:
 * - Daemon socket unreachable / spawn failed.
 * - Daemon explicitly returns `fallback_required: true` (e.g. for `uuidField` custom order expression).
 * - Protocol error on the wire.
 * - Timeout (default 500 ms per request).
 * - The current request references a registered custom extension — orderByName matches a
 *   name registered via addCollectionOrderExpression / addAllowedCollectionOrderColumn, or a
 *   filter key matches a name registered via addAllowedCollectionFilterColumn /
 *   addFilterCollectionExpression / addAllowedDynamicFilterColumn / addFilterDynamicExpression.
 *   Per-request detection: unrelated requests (no custom key in $filters or $orderByName) still
 *   reach the daemon even when other extensions are registered.
 *
 * Failure never propagates to the caller: the fallback provider always runs and its result is returned.
 * @internal Not a part of the public API — use {@see GeneralProductsCacheProvider} instead.
 *           Direct injection of this class bypasses the provider abstraction and breaks
 *           the 'cache' / 'live' / 'rust' provider switch.
 */
final class RustProxyProductsProvider implements GeneralProductsCacheProvider
{
	/**
	 * Agregovaný per-request log volání pro {@see RustDaemonBarPanel}. Klíč je `method|status|reason`,
	 * hodnota akumuluje count / total ms / max ms. Static záměrně — panel k němu přistupuje bez
	 * DI závislosti, a lifecycle je přirozeně per-request (PHP-FPM worker resetuje při bootstrap).
	 * @var array<string, array{method: string, status: string, reason: string, count: int, totalMs: float, maxMs: float}>
	 */
	public static array $callLog = [];

	/**
	 * Registrované order-expression callbacks (legacy — daemon nativně podporuje `uuidField`
	 * přes `_orderUuids` key v `$filters`, ostatní forwardují do fallback providera pro PHP
	 * exekuci). Drží se pro případ, že uživatel zavolá provider bez přepnutí daemona.
	 * @var array<string, true>
	 */
	private array $registeredOrderExtensions = [];

	/**
	 * Callback pro `uuidField` ordering (assistant create-order flow). Uložen pro reflection
	 * introspekci captured `$this->selectedUuids` — daemon potřebuje seznam UUIDs v požadovaném
	 * pořadí. Alternativa (přímé vložení `_orderUuids` do filters v presenteru) by vyžadovala
	 * invazivní změny v AssistantCreateOrderTrait/ProductPresenter, což tímto shim obcházíme.
	 */
	private \Closure|null $uuidFieldCallback = null;

	/**
	 * Persistent Nette cache pro `getCategoryCount` výsledky — mirror
	 * {@see LiveProductsProvider::fetchAllCategoryCountsDirect} cache vrstvy. Tagy
	 * `['products', 'categories']` jsou identické, takže existující cache-clear flow
	 * (po product/category změně) invaliduje obě najednou.
	 *
	 * Hodnota pod klíčem je celá mapa `categoryUuid → count` pro daný filter-set (bez
	 * `category` klíče). Jedno cache-hit stačí pro všech ~100 getCategoryCount volání
	 * v rámci menu strom renderu. Namespace `rustProxyCategoryCounts` (plural) aby se
	 * nekolidovalo se starým per-category int cachem.
	 */
	private readonly Cache $categoryCountCache;

	/**
	 * Per-request memoizace `getCategoryCount` — celá mapa `categoryUuid → count` na filter-set.
	 * Menu strom volá getCategoryCount stovky × za request; první cold volání (daemon batch) si
	 * stáhne plnou mapu, ostatní volání jen indexují. Mirror
	 * {@see ProductsCacheProvider::getCategoryCount} pattern (řádky 254–285).
	 * @var array<string, array<string, int>>
	 */
	private array $cachedCategoryCountsMap = [];

	/**
	 * Per-request memoizace resolvovaného batch state (`batchCacheKey`, `priceListPKs`,
	 * `visibilityListPKs`, `priceVisibility`, `filtersForBatch`) pod lightweight fingerprintem
	 * vstupů. Menu rendering volá `getCategoryCount` per-category (100+ ×) se stále stejnými
	 * filters + lists — každé volání jinak znovu provádí `resolveListsFromShopperUser`,
	 * `array_map` PK extrakci, ShopperUser flag lookups a `md5(serialize(...))` přes celý
	 * filter-set. S touto cache se celý resolve provede jen jednou per filter-set.
	 * @var array<string, array{batchCacheKey: string, priceListPKs: list<string>, visibilityListPKs: list<string>, priceVisibility: array<string, bool>, filtersForBatch: array<mixed>}>
	 */
	private array $resolvedBatchStateCache = [];

	/** @var list<string>|null Per-request memoizace favourite pricelist UUIDů zákazníka. */
	private array|null $favouritePricelistUuidsCache = null;

	/**
	 * Per-request memoizace category path → UUID lookupu. `mapFilters` se volá jednou na
	 * getProductsFromCacheTable + jednou na každou facet leave-one-out permutaci v proxy,
	 * takže stejná path se řeší vícekrát. Cache je intentionally per-instance (ne static),
	 * protože katalog může přijít s novou kategorií mezi requesty a Nette DI drží proxy
	 * stateless napříč requesty.
	 * @var array<string, string|null>
	 */
	private array $categoryPathToUuidCache = [];

	public function __construct(
		private readonly GeneralProductsCacheProvider $fallback,
		private readonly RustDaemonClient $client,
		private readonly ShopperUser $shopperUser,
		private readonly ProductRepository $productRepository,
		private readonly CategoryRepository $categoryRepository,
		private readonly AttributeRepository $attributeRepository,
		private readonly AttributeValueRepository $attributeValueRepository,
		Storage $storage,
	) {
		$this->categoryCountCache = new Cache($storage, 'rustProxyCategoryCounts');
	}

	public function warmUpCacheTable(array $customers = [], array $customerGroups = [], array $merchants = []): void
	{
		$this->fallback->warmUpCacheTable($customers, $customerGroups, $merchants);
	}

	public function updatePricesCacheTable(array $customers = [], array $customerGroups = [], array $merchants = []): void
	{
		$this->fallback->updatePricesCacheTable($customers, $customerGroups, $merchants);
	}

	/**
	 * @return list<string>
	 */
	public function getSellableProductPKs(): array
	{
		try {
			return $this->client->getSellableProductPKs();
		} catch (RustDaemonException $e) {
			Debugger::log($e, ILogger::EXCEPTION);

			return $this->fallback->getSellableProductPKs();
		}
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

		// Mirror LiveProductsProvider:402–423 — když volající nepředá lists (typicky guest/ProductList),
		// doplníme je z aktuální session, jinak daemon dostane prázdno a has_any_price_mask([]) vrátí 0.
		[$priceLists, $visibilityLists] = $this->resolveListsFromShopperUser($priceLists, $visibilityLists, $filters);
		unset($filters['pricelist']);

		// Expand `attributes` filter 1:1 s LiveProvider::resolveDynamicFilters — systemic routuje do
		// existujících displayAmount/producer/displayDelivery, ostatní do `attributeValue`. Pokud
		// expansion selže (neexistující atribut, showRange/numericSlider DB query fail), vrátí důvod
		// a forwarduje na LiveProvider.
		$attributesExpansionError = $this->expandAttributesFilter($filters);

		if ($attributesExpansionError !== null) {
			return $this->delegateGetProducts($filters, $orderByName, $orderByDirection, $priceLists, $visibilityLists, $debug, $countCategories, 'pre_daemon: ' . $attributesExpansionError, $startNs);
		}

		$fallbackReason = $this->requiresFallback($filters, $orderByName);

		if ($fallbackReason !== null) {
			return $this->delegateGetProducts($filters, $orderByName, $orderByDirection, $priceLists, $visibilityLists, $debug, $countCategories, 'pre_daemon: ' . $fallbackReason, $startNs);
		}

		try {
			$request = $this->buildRequest($filters, $orderByName, $orderByDirection, $priceLists, $visibilityLists, $debug, $countCategories);
			$response = $this->client->getProducts($request);
			$decoded = $this->decodeGetProductsResponse($response);

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
		} catch (RustDaemonFallbackRequiredException $e) {
			return $this->delegateGetProducts($filters, $orderByName, $orderByDirection, $priceLists, $visibilityLists, $debug, $countCategories, 'fallback_required: ' . $e->getMessage(), $startNs);
		} catch (RustDaemonException $e) {
			return $this->delegateGetProducts(
				$filters,
				$orderByName,
				$orderByDirection,
				$priceLists,
				$visibilityLists,
				$debug,
				$countCategories,
				'daemon_exception: ' . $e::class . ': ' . $e->getMessage(),
				$startNs,
			);
		}
	}

	public function getCategoryCount(
		array $filters,
		array $priceLists = [],
		array $visibilityLists = [],
		bool $debug = false,
	): int|null {
		$startNs = \hrtime(true);

		try {
			// Strip `category` z filtrů — cache key i daemon request jsou per filter-set (bez
			// konkrétní kategorie), takže menu strom s volaním per-category sdílí jeden daemon
			// round-trip. Mirror `ProductsCacheProvider::getCategoryCount` (řádky 256–284)
			// a `LiveProductsProvider::getCategoryCount` (řádky 240–253).
			$categoryPath = isset($filters['category']) && $filters['category'] !== null
				? (string) $filters['category']
				: null;

			if ($categoryPath === null) {
				// Bez category filtru nemáme, k čemu count vztáhnout. LP + cache provider vrací 0.
				return 0;
			}

			$filtersForBatch = $filters;
			unset($filtersForBatch['category'], $filtersForBatch['pricelist']);

			// Lightweight fingerprint inputs — bez resolve ShopperUser state, bez array_map
			// PK extrakce, bez md5(serialize). `spl_object_id` je O(1) intrinsic nad object
			// headerem, `serialize` nad malým filters polem je rychlejší než md5+serialize
			// celého state. V menu rendering loopu (100+ volání se stejným filter-setem) vede
			// k jednomu cold resolve a zbytku přímých lookupů.
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

			// 1) Per-request memo plné mapy — po prvním daemon volání jsou všechny další
			//    getCategoryCount volání se stejným filter-setem jen indexace do pole.
			if (isset($this->cachedCategoryCountsMap[$batchCacheKey])) {
				self::recordCall('getCategoryCount', 'daemon', 'memo_hit', (\hrtime(true) - $startNs) / 1000000);

				return $this->cachedCategoryCountsMap[$batchCacheKey][$categoryUuid] ?? 0;
			}

			// 2) Perzistentní Nette cache (1h Expire, tagy ['products', 'categories']).
			/** @var array<string, int>|null $cached */
			$cached = $this->categoryCountCache->load($batchCacheKey);

			if ($cached !== null && \is_array($cached)) {
				$this->cachedCategoryCountsMap[$batchCacheKey] = $cached;
				self::recordCall('getCategoryCount', 'daemon', 'cache_hit', (\hrtime(true) - $startNs) / 1000000);

				return $cached[$categoryUuid] ?? 0;
			}

			// 3) Cache miss → jedno batched daemon volání pro celý filter-set.
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
		} catch (RustDaemonException $e) {
			$reason = $e instanceof RustDaemonFallbackRequiredException
				? 'fallback_required: ' . $e->getMessage()
				: 'daemon_exception: ' . $e::class . ': ' . $e->getMessage();

			self::recordCall('getCategoryCount', 'fallback', $reason, (\hrtime(true) - $startNs) / 1000000);

			return $this->fallback->getCategoryCount($filters, $priceLists, $visibilityLists, $debug);
		}
	}

	public function getIndexByCustomer(Customer|Merchant $customerMerchant): string
	{
		return 'rust-' . $this->fallback->getIndexByCustomer($customerMerchant);
	}

	public function addCollectionOrderExpression(string $name, callable $callback): void
	{
		$this->registeredOrderExtensions[$name] = true;

		// `uuidField` potřebuje explicit list UUIDs, který drží presenter přes `$this->selectedUuids`.
		// Captured closure si ho držíme pro pozdější reflection-based vytažení v `extractOrderUuids`.
		if ($name === 'uuidField' && $callback instanceof \Closure) {
			$this->uuidFieldCallback = $callback;
		}

		$this->fallback->addCollectionOrderExpression($name, $callback);
	}

	public function addAllowedCollectionFilterColumn(string $name, string $column): void
	{
		// Structural column mapping. Daemon doesn't know custom columns — v1 always falls back.
		// v2 could accept `customColumnMap` in-request and apply it to denormalized snapshot fields.
		$this->fallback->addAllowedCollectionFilterColumn($name, $column);
	}

	public function addFilterCollectionExpression(string $name, callable $callback): void
	{
		$this->fallback->addFilterCollectionExpression($name, $callback);
	}

	public function addAllowedDynamicFilterColumn(string $name, string $column): void
	{
		$this->fallback->addAllowedDynamicFilterColumn($name, $column);
	}

	public function addFilterDynamicExpression(string $name, callable $callback): void
	{
		$this->fallback->addFilterDynamicExpression($name, $callback);
	}

	public function addAllowedCollectionOrderColumn(string $name, string $column): void
	{
		$this->registeredOrderExtensions[$name] = true;
		$this->fallback->addAllowedCollectionOrderColumn($name, $column);
	}

	/**
	 * Aggregate call stat do {@see self::$callLog}. `$status` je `daemon` (úspěch) nebo `fallback`
	 * (daemon path selhala a vrátil se PHP výsledek). `$reason` je prázdný při úspěchu, jinak
	 * exception třída + zpráva nebo `requiresFallback` důvod.
	 */
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
	 * Resolvuje `eshop_category.path` na UUID přes per-request cache. Reuse `$this->categoryPathToUuidCache`,
	 * který vrstvu už drží (populovaný přes `resolveCategoryPathsToUuids` — menu rendering typicky stejnou
	 * cestu natáhne během `getProductsFromCacheTable`).
	 */
	private function resolveCategoryPathToUuid(string $path): string|null
	{
		if (\array_key_exists($path, $this->categoryPathToUuidCache)) {
			return $this->categoryPathToUuidCache[$path];
		}

		$uuids = $this->resolveCategoryPathsToUuids([$path]);

		return $uuids === [] ? null : $uuids[0];
	}

	/**
	 * Defaultuje prázdné lists na ShopperUser session (mirror LiveProductsProvider:402–423),
	 * aplikuje optional `$filters['pricelist']` subset filter a sortí pricelists podle priority ASC
	 * (first = highest priority, match daemon's priority-first best price logic).
	 *
	 * Pokud session nemá žádné visibility/price lists (např. maintenance mode, nebo bootstrap),
	 * vrátíme prázdná pole beze změny — volající vrstva (daemon) pak vrátí 0 produktů, což je
	 * konzistentní s PHP (LiveProvider tam throwne, ale my místo throw radši vracíme 0 — guest
	 * bez session nemá co vidět).
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
	 * Build a `GetProductsRequest` payload (camelCase keys matching the Rust serde struct).
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
	 * `GetValuesForFilterContract`/`GetValuesForFilterNotPublic` vrací `[$favouritePriceLists, $ribbon]`.
	 * Zajímá nás jen `$ribbon->getPK()` — favourites resolvujeme odděleně přes `resolveFavouritePricelistUuids`
	 * (tam dostaneme UUIDy, existující PHP shape má jen numeric ID a není pro daemon použitelný).
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
	 * `GetValuesForFilterProject::execute` vrací `['customerIc' => string|null, 'isMerchant' => bool]`.
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
	 * Extract seznam UUIDs pro `uuidField` ordering. Dva zdroje (první, který má hodnotu, vyhrává):
	 *
	 * 1. `$filters['_orderUuids']` — explicit kontrakt mezi presenterem a proxy. Preferovaný způsob,
	 *    pokud to presenter umí vyplnit přímo.
	 * 2. Reflection captured `$this->selectedUuids` v registrovaném `uuidField` closure. Fallback
	 *    bez invazivních úprav AssistantCreateOrderTrait/ProductPresenter.
	 *
	 * Vrací `null` pokud order není `uuidField` nebo když žádný zdroj nedal seznam.
	 * @param array<mixed> $filters
	 * @return list<string>|null
	 */
	private function extractOrderUuids(array $filters, string|null $orderByName): array|null
	{
		if ($orderByName !== 'uuidField') {
			return null;
		}

		// 1. Explicit filters key.
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

		// 2. Reflection na captured closure `$this->selectedUuids`.
		if ($this->uuidFieldCallback === null) {
			return null;
		}

		$refl = new \ReflectionFunction($this->uuidFieldCallback);
		$capturedThis = $refl->getClosureThis();

		if ($capturedThis === null || !\property_exists($capturedThis, 'selectedUuids')) {
			return null;
		}

		// `selectedUuids` je v base presenteru `protected array` — direct property access z téhle třídy
		// by narazil na visibility a Nette SmartObject by to v `ObjectHelpers::strictGet()` rebrandoval
		// na `MemberAccessException` ("undeclared property"). ReflectionProperty::getValue() bypassuje
		// visibility bez úprav presenteru.
		$prop = new \ReflectionProperty($capturedThis, 'selectedUuids');
		/** @var mixed $raw */
		$raw = $prop->getValue($capturedThis);

		if (!\is_array($raw)) {
			return null;
		}

		$out = [];

		foreach ($raw as $uuid) {
			if (\is_string($uuid) && $uuid !== '') {
				$out[] = $uuid;
			}
		}

		return $out !== [] ? $out : null;
	}

	/**
	 * @return list<string>
	 */
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
	 * Resolve per-session price modifiers into the daemon's `PriceModifiers` wire shape.
	 *
	 * Mirrors `LiveProductsProvider::computeEffectivePrices` lines 1080–1090 — same customer,
	 * customer group, discount coupon, and currency that the fallback path would read. Keeping
	 * both paths reading from the same `ShopperUser` instance guarantees a Rust/PHP flip does
	 * not change what the user sees.
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
	 * Resolve a list of `eshop_category.path` strings to UUIDs via one batched SELECT.
	 * Missing paths are silently dropped (daemon would reject them as UnknownCategory and
	 * force a fallback — cheaper to just hand it an empty set and let `has_any_price_mask`
	 * drop the products to zero).
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
	 * Map a PHP filters array to the wire `FilterPayload` shape. Vrací `\stdClass` aby
	 * `json_encode` vyprodukoval `{}` i pro prázdný filter (Rust `serde` deserializuje
	 * `FilterPayload` jako struct a odmítne `[]` s "invalid length 0, expected struct").
	 * @param array<mixed> $filters
	 */
	private function mapFilters(array $filters): \stdClass
	{
		$out = new \stdClass();

		if (isset($filters['category'])) {
			// PHP UI posílá `$filters['category']` jako `path` string (např. "01.02"), někdy i
			// jako pole paths. `LiveProductsProvider::applyCategoryFilter` ho hledá přes
			// `CategoryRepository::many()->where('path', $path)`. Rust daemon pracuje s UUID
			// přes vlastní intern pool, takže path je potřeba převést na UUID tady.
			$rawPaths = \is_array($filters['category']) ? \array_values($filters['category']) : [(string) $filters['category']];
			$out->categoryUuids = $this->resolveCategoryPathsToUuids($rawPaths);
		}

		// Union merge `producer` + `producers` + `systemicAttributes.producer` — LiveProvider (řádky 89-91, 1979)
		// je všechny routuje na stejný `this.fk_producer` filter, tak i daemon dostane jeden field.
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

		// Ribbon variants — pole nebo single UUID, empty array se neposílá (daemon by jinak vracel 0).
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

		// Visibility booleans — striktní konverze z mixed, aby PHPStan level 8 neřval na (bool) mixed.
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

		// `masterProduct` — PHP LiveProvider (řádky 2105–2110) akceptuje pouze strict `=== true` /
		// `=== false` (truthy string/int hodnoty ignoruje). Mirror přesně tuto sémantiku — daemon má
		// `master_product: Option<bool>` takže `null` = filter vypnutý.
		if (\array_key_exists('masterProduct', $filters)) {
			$raw = $filters['masterProduct'];

			if ($raw === true) {
				$out->masterProduct = true;
			} elseif ($raw === false) {
				$out->masterProduct = false;
			}
		}

		// `inStock` — PHP `filterInStock` je no-op pokud $value je falsy, jinak aktivuje.
		if (isset($filters['inStock']) && $filters['inStock']) {
			$out->inStock = true;
		}

		// `related` — array{uuid: string, category: string}. Primary category je per-categoryType,
		// PHP používá `shopperUser->getMainCategoryType()`. Forward jeho PK jako `mainCategoryTypePk`.
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

		// `relatedSlave` — array{0: typeUuid, 1: masterUuid}. Mirror PHP `filterRelatedSlave`.
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

		// `crossSellFilter` — array{0: path, 1: currentProduct}. Mirror PHP `filterCrossSellFilter`.
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
	 * Expanze `$filters['attributes']` na (a) systemicAttributes.{availability,producer,delivery}
	 * (mergne do existujících mapFilters fieldů) a (b) `$filters['attributeValue'][attrPk] = [...]`
	 * který daemon konzumuje přes `dynamicFilterAttributes`. Mirror
	 * {@see LiveProductsProvider::resolveDynamicFilters} řádky 1437–1500.
	 *
	 * Vrátí `null` při úspěchu, nebo stringový důvod pro fallback pokud expansion selže.
	 * @param array<mixed> $filters Modifikuje se in-place: `attributes` se odstraní, systemic subklíče
	 *                              se mergnou pod `systemicAttributes.{availability,producer,delivery}`,
	 *                              ostatní pod `attributeValue[attributePk]`.
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
				// systemicAttributes.availability → daemon displayAmountUuids (mapFilters union)
				// systemicAttributes.producer     → daemon producerUuids
				// systemicAttributes.delivery     → daemon displayDeliveryUuids
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
						// Stejně jako PHP `continue` — žádné hodnoty = atribut se do filtru nepřidává.
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
	 * Union-merge seznamů UUIDů z více aliasů do jednoho pole. Stringy i pole akceptujeme
	 * (PHP volající někdy posílá single UUID, někdy list). Prázdné/null hodnoty se tiše dropnou,
	 * výsledek je deduplikovaný a reindexovaný.
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
	 * Detekce filtrů a order expressions, které daemon neumí přeložit 1:1.
	 *
	 * Zdroje:
	 * - `query` / `query2` — fulltext search, daemon nemá MATCH() ani LIKE na denormalizovanou snapshot
	 * - Custom order expression — když je registrován přes `addCollectionOrderExpression`/`addAllowedCollectionOrderColumn`
	 *   a není to `uuidField` (ten umí daemon nativně přes `_orderUuids`), spadne to stejně až v daemonu
	 *   na `fallback_required`; odchytit ho tady ušetří round-trip.
	 * @param array<mixed> $filters
	 */
	private function requiresFallback(array $filters, string|null $orderByName): string|null
	{
		foreach (['query', 'query2'] as $unsupportedKey) {
			if (\array_key_exists($unsupportedKey, $filters)) {
				return 'custom_filter:' . $unsupportedKey;
			}
		}

		if ($orderByName !== null && $orderByName !== 'uuidField' && isset($this->registeredOrderExtensions[$orderByName])) {
			return 'custom_order:' . $orderByName;
		}

		return null;
	}

	/**
	 * Decode the Rust daemon's `GetProductsResponse` into the PHP return shape declared on
	 * {@see GeneralProductsCacheProvider::getProductsFromCacheTable}.
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

	/**
	 * @param array<mixed> $filters
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
	 */
	private function delegateGetProducts(
		array $filters,
		string|null $orderByName,
		string $orderByDirection,
		array $priceLists,
		array $visibilityLists,
		bool $debug,
		bool $countCategories,
		string $reason,
		int $startNs,
	): array|false {
		Debugger::log('RustProxyProductsProvider fallback: ' . $reason, ILogger::INFO);

		$direction = Strings::upper($orderByDirection) === 'DESC' ? 'DESC' : 'ASC';

		$result = $this->fallback->getProductsFromCacheTable(
			$filters,
			$orderByName,
			$direction,
			$priceLists,
			$visibilityLists,
			$debug,
			$countCategories,
		);

		self::recordCall('getProductsFromCacheTable', 'fallback', $reason, (\hrtime(true) - $startNs) / 1000000);

		return $result;
	}
}
