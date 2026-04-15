<?php

declare(strict_types=1);

namespace Eshop\Services\ProductsCache;

use Eshop\DB\AttributeRepository;
use Eshop\DB\AttributeValueRepository;
use Eshop\DB\CategoryRepository;
use Eshop\DB\Customer;
use Eshop\DB\DisplayAmountRepository;
use Eshop\DB\DisplayDeliveryRepository;
use Eshop\DB\Merchant;
use Eshop\DB\PricelistRepository;
use Eshop\DB\PriceRepository;
use Eshop\DB\ProducerRepository;
use Eshop\DB\ProductRepository;
use Eshop\DB\VisibilityListItemRepository;
use Eshop\DB\VisibilityListRepository;
use Eshop\ShopperUser;
use Nette\Caching\Cache;
use Nette\Caching\Storage;
use Nette\Utils\Arrays;
use StORM\DIConnection;
use StORM\ICollection;
use Tracy\Debugger;

/**
 * Live provider produktů — alternativa k {@see ProductsCacheProvider}, která místo čtení z oddělené cache DB
 * pracuje přímo s produkční databází a denormalizovanými sloupci na eshop_product.
 *
 * Filozofie: základní rychlá filtrace v SQL (fáze 1), batch-fetch cen (fáze 2),
 * zbytek (výpočet ceny, facet počty, dynamické filtry atributů, řazení, paginace) v PHP (fáze 3).
 *
 * Předpoklad: na eshop_product jsou vyplněné sloupce `denormalizedAttributeValues` a `denormalizedCategories`
 * (aktualizované cronem rebuildProductDenormalization).
 *
 * Podporovaná řazení: priority (výchozí, "Doporučujeme"), price ASC/DESC, name.
 * Nepodporováno ve v1: relatedTypeMaster/Slave, countCategories, primaryCategoryByCategoryType, availabilityAndPrice, custom order expressions.
 */
class LiveProductsProvider implements GeneralProductsCacheProvider
{
	public bool $debug = false;

	/**
	 * Per-request memoizace počtů produktů per category pro daný set filtrů + pricelists + visibilityLists.
	 * Stejný pattern jako ProductsCacheProvider::$cachedCategoryCounts — první volání spočítá counts
	 * pro všechny kategorie najednou, další volání vrací z paměti bez SQL.
	 * @var array<string, array<string, int>>
	 */
	protected array $cachedCategoryCounts = [];

	/**
	 * Collection filters — SQL WHERE aplikované na hlavní dotaz fáze 1.
	 * @var array<string, string>
	 */
	protected array $allowedCollectionFilterColumns = [
		'hidden' => 'visibilityListItem.hidden',
		'hiddenInMenu' => 'visibilityListItem.hiddenInMenu',
		'priority' => 'visibilityListItem.priority',
		'recommended' => 'visibilityListItem.recommended',
		'unavailable' => 'visibilityListItem.unavailable',
		'name' => 'this.name' . '',
		'isSold' => 'displayAmount.isSold',
	];

	/**
	 * @var array<string, callable(
	 *   \StORM\ICollection<\Eshop\DB\Product> $productsCollection,
	 *   mixed $value,
	 *   array<\Eshop\DB\VisibilityList> $visibilityLists,
	 *   array<\Eshop\DB\Pricelist> $priceLists
	 * ): void>
	 */
	protected array $allowedCollectionFilterExpressions = [];

	/**
	 * Dynamic filters — v PHP na fetched produktech.
	 * @var array<string, string>
	 */
	protected array $allowedDynamicFilterColumns = [
		'systemicAttributes.producer' => 'producer',
		'systemicAttributes.availability' => 'displayAmount',
		'systemicAttributes.delivery' => 'displayDelivery',
	];

	/**
	 * @var array<string, callable(\stdClass $product, mixed $value, array<\Eshop\DB\VisibilityList> $visibilityLists, array<\Eshop\DB\Pricelist> $priceLists): bool>
	 */
	protected array $allowedDynamicFilterExpressions = [];

	/**
	 * Order columns — mapa user-facing názvu na stdClass property.
	 * @var array<string, string>
	 */
	protected array $allowedCollectionOrderColumns = [
		'priority' => 'priority',
		'price' => 'price',
		'name' => 'name',
	];

	/**
	 * @var array<string, callable(
	 *   \StORM\ICollection<\stdClass> $productsCollection,
	 *   'ASC'|'DESC' $direction,
	 *   array<\Eshop\DB\VisibilityList> $visibilityLists,
	 *   array<\Eshop\DB\Pricelist> $priceLists,
	 * ): void>
	 */
	protected array $allowedCollectionOrderExpressions = [];

	protected readonly Cache $cache;

	public function __construct(
		protected readonly ProductRepository $productRepository,
		protected readonly CategoryRepository $categoryRepository,
		protected readonly PriceRepository $priceRepository,
		/** @var \Eshop\DB\PricelistRepository<\Eshop\DB\Pricelist> */
		protected readonly PricelistRepository $pricelistRepository,
		protected readonly VisibilityListItemRepository $visibilityListItemRepository,
		protected readonly VisibilityListRepository $visibilityListRepository,
		protected readonly AttributeRepository $attributeRepository,
		protected readonly AttributeValueRepository $attributeValueRepository,
		protected readonly ProducerRepository $producerRepository,
		protected readonly DisplayAmountRepository $displayAmountRepository,
		protected readonly DisplayDeliveryRepository $displayDeliveryRepository,
		protected readonly ShopperUser $shopperUser,
		protected readonly DIConnection $connection,
		Storage $storage,
	) {
		$this->cache = new Cache($storage, 'liveProductsProvider');
		$this->startUp();
	}

	// phpcs:ignore
	public function warmUpCacheTable(array $customers = [], array $customerGroups = [], array $merchants = []): void
	{
		// LiveProductsProvider nepoužívá cache — no-op.
	}

	// phpcs:ignore
	public function updatePricesCacheTable(array $customers = [], array $customerGroups = [], array $merchants = []): void
	{
		// LiveProductsProvider nepoužívá cache — no-op.
	}

	public function addCollectionOrderExpression(string $name, callable $callback): void
	{
		$this->allowedCollectionOrderExpressions[$name] = $callback;
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

		return 'live-' . \implode(',', \array_keys($visibilityLists)) . '-' . \implode(',', \array_keys($priceLists));
	}

	public function hasInternalCategoryCountCache(): bool
	{
		// LP má v `getCategoryCount` per-request memoizaci přes `$this->cachedCategoryCounts` —
		// jedním dotazem `fetchAllCategoryCountsDirect` spočítá counts pro všechny kategorie,
		// následné volání per kategorie jsou pole-lookupy. Externí Nette Cache v `CategoryRepository::getCounts`
		// by naopak vytvářela zbytečnou SQLite I/O pro každou kategorii zvlášť (~500 volání v menu
		// templatech × ~50ms/write na DDEV overlayfs = desítky sekund latence).
		return true;
	}

	public function getCategoryCount(array $filters, array $priceLists = [], array $visibilityLists = [], bool $debug = false): int|null // phpcs:ignore
	{
		$priceLists = $priceLists ?: $this->shopperUser->getPriceListsCached();
		$visibilityLists = $visibilityLists ?: $this->shopperUser->getVisibilityLists();

		if (!$visibilityLists || !$priceLists) {
			return null;
		}

		if (!isset($filters['category'])) {
			return 0;
		}

		$categoryPath = (string) $filters['category'];

		/** @var \Eshop\DB\Category|null $category */
		$category = $this->categoryRepository->many()
			->where('this.path', $categoryPath)
			->first();

		if ($category === null) {
			return 0;
		}

		// Per-request memoizace — klíč sestavený ze zbylých filtrů + pricelists + visibility.
		// Stejný pattern jako ProductsCacheProvider::getCategoryCount: první volání pro daný filter-set
		// spočítá counts pro všechny kategorie (jedním odlehčeným dotazem), další volání vrací z paměti.
		$filtersForCache = $filters;
		unset($filtersForCache['category']);

		$cacheIndex = \md5(
			\serialize($filtersForCache)
			. '_' . \serialize(\array_keys($priceLists))
			. '_' . \serialize(\array_keys($visibilityLists)),
		);

		if (!isset($this->cachedCategoryCounts[$cacheIndex])) {
			$this->cachedCategoryCounts[$cacheIndex] = $this->fetchAllCategoryCountsDirect($filtersForCache, $visibilityLists, $priceLists);
		}

		return $this->cachedCategoryCounts[$cacheIndex][$category->getPK()] ?? 0;
	}

	/**
	 * Spočítá počty produktů per kategorie najednou (jedním lehkým SQL dotazem) a vrátí mapu UUID kategorie → count.
	 *
	 * Záměrně ignoruje "dynamic" filtry (contract, project, ribbon, …) — ty by vyžadovaly full pipeline.
	 * Pro menu stromek (preloadCategoryCounts) je malá nepřesnost přijatelná — stejně cache provider počítá
	 * jen podle dat v cache schématu, což taky není 100% přesné při runtime změnách.
	 * @param array<mixed> $filters Filtry bez `category` klíče — použijí se jen SQL-reprezentovatelné.
	 * @param array<string|int, \Eshop\DB\VisibilityList> $visibilityLists
	 * @param array<\Eshop\DB\Pricelist> $priceLists
	 * @return array<string, int> UUID kategorie → count produktů
	 */
	protected function fetchAllCategoryCountsDirect(array $filters, array $visibilityLists, array $priceLists): array
	{
		$visibilityListPKs = \array_map(static fn($v) => (string) $v->getPK(), $visibilityLists);
		$priceListPKs = \array_map(static fn($p) => (string) $p->getPK(), $priceLists);

		$cacheKey = 'categoryCounts_' . \md5(\serialize($filters) . '|' . \serialize($visibilityListPKs) . '|' . \serialize($priceListPKs));

		/** @var array<string, int>|null $cached */
		$cached = $this->cache->load($cacheKey);

		if ($cached !== null) {
			return $cached;
		}

		$params = [];

		$priceListPlaceholders = [];

		foreach ($priceListPKs as $pk) {
			$key = 'pl' . $pk;
			$priceListPlaceholders[] = ':' . $key;
			$params[$key] = $pk;
		}

		$whereClauses = [
			'this.deletedTs IS NULL',
			'this.denormalizedCategories IS NOT NULL',
			"this.denormalizedCategories <> ''",
			'vli.hidden = 0',
		];

		// Single VL: direct JOIN (no correlated subquery — ~5x faster).
		// Multiple VLs: correlated subquery to pick highest-priority VL per product.
		if (\count($visibilityListPKs) === 1) {
			$params['vl0'] = \reset($visibilityListPKs);
			$visibilityListItemJoin = 'INNER JOIN eshop_visibilitylistitem AS vli
				ON vli.fk_product = this.uuid AND vli.fk_visibilityList = :vl0';
		} else {
			$visibilityListPlaceholders = [];

			foreach ($visibilityListPKs as $i => $pk) {
				$key = 'vl' . $i;
				$visibilityListPlaceholders[] = ':' . $key;
				$params[$key] = $pk;
			}

			$visibilityListItemJoin = 'JOIN eshop_visibilitylistitem AS vli ON vli.fk_product = this.uuid
				AND vli.fk_visibilityList = (
					SELECT fk_visibilityList FROM eshop_visibilitylistitem
					JOIN eshop_visibilitylist ON eshop_visibilitylist.uuid = eshop_visibilitylistitem.fk_visibilityList
					WHERE fk_product = this.uuid AND eshop_visibilitylist.uuid IN (' . \implode(',', $visibilityListPlaceholders) . ')
					ORDER BY eshop_visibilitylist.priority ASC
					LIMIT 1
				)';
		}

		foreach (['hidden', 'hiddenInMenu', 'recommended', 'unavailable'] as $vliFilter) {
			if (!isset($filters[$vliFilter])) {
				continue;
			}

			$whereClauses[] = 'vli.' . $vliFilter . ' = ' . ((int) (bool) $filters[$vliFilter]);
		}

		if (isset($filters['masterProduct'])) {
			$whereClauses[] = $filters['masterProduct']
				? 'this.fk_masterProduct IS NULL'
				: 'this.fk_masterProduct IS NOT NULL';
		}

		// IN subquery instead of EXISTS — avoids semi-join materialization (~5x faster on 200k+ products).
		$priceConditions = ['fk_pricelist IN (' . \implode(',', $priceListPlaceholders) . ')'];

		if (!$this->shopperUser->canViewHiddenPrices()) {
			$priceConditions[] = 'hidden = 0';
		}

		if (!$this->shopperUser->getShowZeroPrices()) {
			if ($this->shopperUser->getShowVat()) {
				$priceConditions[] = 'priceVat > 0';
			}

			if ($this->shopperUser->getShowWithoutVat()) {
				$priceConditions[] = 'price > 0';
			}
		}

		$whereClauses[] = 'this.uuid IN (SELECT fk_product FROM eshop_price WHERE ' . \implode(' AND ', $priceConditions) . ')';

		$sql = 'SELECT this.denormalizedCategories AS categories
			FROM eshop_product AS this
			' . $visibilityListItemJoin . '
			WHERE ' . \implode(' AND ', $whereClauses);

		$statement = $this->connection->query($sql, $params);

		$counts = [];

		foreach ($statement->fetchAll(\PDO::FETCH_ASSOC) as $row) {
			foreach (\explode(',', (string) $row['categories']) as $catUuid) {
				if ($catUuid === '') {
					continue;
				}

				$counts[$catUuid] = ($counts[$catUuid] ?? 0) + 1;
			}
		}

		$this->cache->save($cacheKey, $counts, [
			Cache::Expire => '1 hour',
			Cache::Tags => ['products', 'categories'],
		]);

		return $counts;
	}

	// phpcs:disable
	public function getProductsFromCacheTable(
		array $filters,
		string|null $orderByName = null,
		string $orderByDirection = 'ASC',
		array $priceLists = [],
		array $visibilityLists = [],
		bool $debug = false,
		bool $countCategories = false,
	): array|false {
		// phpcs:enable
		$priceLists = $priceLists ?: $this->shopperUser->getPriceListsCached();
		$visibilityLists = $visibilityLists ?: $this->shopperUser->getVisibilityLists();

		if (!$visibilityLists) {
			throw new \Exception('No VisibilityLists supplied.');
		}

		if (!$priceLists) {
			throw new \Exception('No PriceLists supplied.');
		}

		Debugger::timer('liveProductsProvider.total');
		$this->debug = $debug;

		if (isset($filters['pricelist'])) {
			$priceLists = \array_filter($priceLists, static fn($priceList) => Arrays::contains((array) $filters['pricelist'], $priceList->getPK()), \ARRAY_FILTER_USE_BOTH);
		}

		unset($filters['pricelist']);

		// Pricelists seřazené podle priority ASC (první = nejvyšší priorita, nejnižší číslo).
		\usort($priceLists, static fn($a, $b) => ($a->priority ?? 10) <=> ($b->priority ?? 10));
		$pricelistPKs = \array_map(static fn($pricelist) => (string) $pricelist->getPK(), $priceLists);
		$visibilityListPKs = \array_map(static fn($vl) => (string) $vl->getPK(), \array_values($visibilityLists));

		// Cross-request cache — zásadní pro asistent workflow (rychlé přepínání mezi customery se stejným pricelist-setem)
		// i běžný eshop (opakovaná navigace mezi stránkami se stejnými filtry). Klíč: filters + pricelisty + visibility
		// listy + orderBy. TTL 5 minut, invalidace přes tagy 'products'/'pricelists' (ty už cache provider invaliduje
		// při změnách; sdílíme stejný signál).
		//
		// Pro unbounded dotazy (bez category filtru) po aplikaci pricelist EXISTS prefiltru v `fetchCandidateProducts`
		// klesla velikost candidate setu o 50–65 %, takže serializace přes FileStorage je zvládnutelná. Pojistka
		// `$cacheWriteGuardLimit` níže zapíše výsledek jen když candidate set nepřekročí bezpečný prah
		// (default 100k PKs ≈ ~3 MB serialized). Používáme explicitní load + save místo `cache->load($key, $generator)`,
		// protože generator-variant pro Nette cache nepodporuje conditional skip-write (null dependencies tam
		// neznamená "neukládat", jen completeDependencies dodá defaulty).
		$cacheKey = 'lpResult_' . \md5(
			\serialize($filters)
			. '|' . \implode(',', $pricelistPKs)
			. '|' . \implode(',', $visibilityListPKs)
			. '|' . ($orderByName ?? 'NONE')
			. '|' . $orderByDirection
			. '|' . ($countCategories ? '1' : '0'),
		);

		$cacheWriteGuardLimit = 100000;

		/**
		 * @var array{
		 *     productPKs: list<string>,
		 *     attributeValuesCounts: array<string|int, int>,
		 *     displayAmountsCounts: array<string|int, int>,
		 *     displayDeliveriesCounts: array<string|int, int>,
		 *     producersCounts: array<string|int, int>,
		 *     categoriesCounts?: array<string|int, int>,
		 *     priceMin: float,
		 *     priceMax: float,
		 *     priceVatMin: float,
		 *     priceVatMax: float,
		 * }|null $cached
		 */
		$cached = $bypassAllCaches ? null : $this->cache->load($cacheKey);

		if ($cached !== null) {
			Debugger::barDump(Debugger::timer('liveProductsProvider.total'), 'liveProductsProvider.total (cache hit)');

			return $cached;
		}

		$output = $this->computeProviderOutput($filters, $visibilityLists, $priceLists, $pricelistPKs, $orderByName, $orderByDirection);

		if (!$bypassAllCaches && \count($output['productPKs']) <= $cacheWriteGuardLimit) {
			$this->cache->save($cacheKey, $output, [
				Cache::Tags => ['products', 'pricelists', ProductsCacheProvider::PRODUCTS_PROVIDER_CACHE_TAG],
				Cache::Expire => '5 minutes',
			]);
		}

		Debugger::barDump(Debugger::timer('liveProductsProvider.total'), 'liveProductsProvider.total');

		return $output;
	}

	/**
	 * Vlastní výpočet: fetch kandidátů, batch cen, PHP filtrace + facet počty, ordering, output shaping.
	 * Extrahováno z `getProductsFromCacheTable`, aby šlo volat jak v cache callbacku, tak přímo (bez cache).
	 * @param array<mixed> $filters
	 * @param array<string|int, \Eshop\DB\VisibilityList> $visibilityLists
	 * @param array<\Eshop\DB\Pricelist> $priceLists
	 * @param list<string> $pricelistPKs
	 * @return array{
	 *   productPKs: list<string>,
	 *   attributeValuesCounts: array<string|int, int>,
	 *   displayAmountsCounts: array<string|int, int>,
	 *   displayDeliveriesCounts: array<string|int, int>,
	 *   producersCounts: array<string|int, int>,
	 *   priceMin: float, priceMax: float, priceVatMin: float, priceVatMax: float,
	 * }
	 */
	protected function computeProviderOutput(
		array $filters,
		array $visibilityLists,
		array $priceLists,
		array $pricelistPKs,
		string|null $orderByName,
		string $orderByDirection,
	): array {
		// Baseline (customer-independent): fetchCandidateProducts + mergeRibbons. Shared mezi customery — pro asistenty,
		// kteří často přepínají zákazníky, dramaticky zrychluje cold cache per-customer. Klíč: visibility + non-customer
		// filtry + orderBy (priority/name řazení je customer-independent; price ordering se aplikuje až per-customer).
		$visibilityListPKsSorted = \array_map(static fn($vl) => (string) $vl->getPK(), \array_values($visibilityLists));
		\sort($visibilityListPKsSorted);

		$baselineKey = 'lpBase_' . \md5(
			\serialize($filters)
			. '|' . \implode(',', $visibilityListPKsSorted)
			. '|' . ($orderByName ?? 'NONE')
			. '|' . $orderByDirection,
		);

		/** @var array{candidates: list<\stdClass>, categoryUuids: list<string>|null}|null $baseline */
		$baseline = $this->cache->load($baselineKey);

		if ($baseline === null) {
			Debugger::timer('LP.fetch');
			$categoryUuids = null;
			$baselineCandidates = $this->fetchCandidateProducts($filters, $visibilityLists, $priceLists, $orderByName, $orderByDirection, $categoryUuids);
			$tFetch = \round((float) Debugger::timer('LP.fetch') * 1000, 1);

			if ($baselineCandidates !== []) {
				$baselineUuids = [];

				foreach ($baselineCandidates as $product) {
					$baselineUuids[] = $product->uuid;
				}

				$this->mergeRibbons($baselineCandidates, $baselineUuids);
			}

			$baseline = ['candidates' => $baselineCandidates, 'categoryUuids' => $categoryUuids];

			// Guard: pro enormní baseline (např. /produkty bez filtrů ~131k) se serializace přes FileStorage
			// vyplatí — je to typicky jednou za 5 min a payload ~20 MB. Přes 200k raději necachovat (overhead
			// serializace překročí úsporu).
			if (\count($baselineCandidates) <= 200000) {
				$this->cache->save($baselineKey, $baseline, [
					Cache::Tags => ['products', ProductsCacheProvider::PRODUCTS_PROVIDER_CACHE_TAG],
					Cache::Expire => '5 minutes',
				]);
			}

			Debugger::log(\sprintf('LP baseline MISS count=%d fetch+ribbons=%sms', \count($baselineCandidates), $tFetch), 'liveprovider');
		}

		$fetchedProducts = $baseline['candidates'];
		$categoryUuids = $baseline['categoryUuids'];

		if ($fetchedProducts === []) {
			return $this->emptyResult();
		}

		// Per-customer pricelist filter — produkty musí mít cenu v alespoň jednom z customer pricelistů.
		// Pricelist membership je shared cache (fetchPricelistMembership), takže PHP intersect je rychlý.
		Debugger::timer('LP.custFilter');
		$pricelistMembership = $this->fetchPricelistMembership();
		$pricelistPKsFlipped = \array_flip($pricelistPKs);
		$filteredProducts = [];
		$productUuids = [];

		foreach ($fetchedProducts as $product) {
			$productPricelists = $pricelistMembership[$product->uuid] ?? [];

			foreach ($productPricelists as $plPK) {
				if (isset($pricelistPKsFlipped[$plPK])) {
					// Clone zajistí, že per-customer mutace (price, priceList) neovlivní sdílenou baseline cache.
					$filteredProducts[] = clone $product;
					$productUuids[] = $product->uuid;

					break;
				}
			}
		}

		$tCustFilter = \round((float) Debugger::timer('LP.custFilter') * 1000, 1);

		if ($filteredProducts === []) {
			return $this->emptyResult();
		}

		Debugger::timer('LP.prices');
		$pricesByProduct = $this->fetchPricesByProduct($productUuids, $pricelistPKs, $categoryUuids, $visibilityLists);
		$this->computeEffectivePrices($filteredProducts, $pricesByProduct, $priceLists);
		$tPrices = \round((float) Debugger::timer('LP.prices') * 1000, 1);

		[$dynamicFiltersAttributes, $dynamicFilters, $allAttributes] = $this->resolveDynamicFilters($filters);

		Debugger::timer('LP.filter');
		$result = $this->filterAndCount(
			$filteredProducts,
			$dynamicFiltersAttributes,
			$dynamicFilters,
			$allAttributes,
			$visibilityLists,
			$priceLists,
		);

		$this->applyOrdering($result['filtered'], $orderByName, $orderByDirection);
		$output = $this->buildOutput($result);
		$tFilter = \round((float) Debugger::timer('LP.filter') * 1000, 1);

		Debugger::log(\sprintf(
			'LP per-cust baseline=%d filtered=%d custFilter=%sms prices=%sms filter=%sms filters=%s',
			\count($fetchedProducts),
			\count($filteredProducts),
			$tCustFilter,
			$tPrices,
			$tFilter,
			\json_encode(\array_keys($filters)),
		), 'liveprovider');

		return $output;
	}

	/**
	 * Shared cache: pro každý produkt množina pricelist PKs, ve kterých má nezavřenou platnou cenu (>0).
	 *
	 * Jeden GROUP_CONCAT dotaz přes celou `eshop_price`, výsledek cachován 5 min přes `pricelists` tag.
	 * Invalidace při změně cen se děje automaticky. Umožňuje per-customer pricelist filter v PHP (intersect
	 * mapa × customer pricelists) bez potřeby customer-specific EXISTS v hlavním dotazu.
	 * @return array<string, list<string>> fk_product → list of pricelist PKs
	 */
	protected function fetchPricelistMembership(): array
	{
		$cacheKey = 'lpPricelistMembership_v1';
		$cached = $this->cache->load($cacheKey);

		if ($cached !== null) {
			return $cached;
		}

		/** @var array<string, list<string>> $membership */
		$membership = [];

		$statement = $this->connection->query(
			'SELECT fk_product, GROUP_CONCAT(DISTINCT fk_pricelist) AS pls '
			. 'FROM eshop_price WHERE hidden = 0 AND price > 0 GROUP BY fk_product',
		);

		foreach ($statement->fetchAll(\PDO::FETCH_ASSOC) as $row) {
			$membership[$row['fk_product']] = \explode(',', $row['pls']);
		}

		$this->cache->save($cacheKey, $membership, [
			Cache::Tags => ['pricelists', ProductsCacheProvider::PRODUCTS_PROVIDER_CACHE_TAG],
			Cache::Expire => '5 minutes',
		]);

		return $membership;
	}

	/**
	 * Fáze 1: Jediný SQL dotaz — kandidáti + ceny + ribbony.
	 *
	 * Ribbony a internalRibbony se načtou jako correlated GROUP_CONCAT subqueries (index lookup na fk_product).
	 * Best-price se načte jako correlated subquery s ORDER BY priority LIMIT 1 — priority-first sémantika
	 * identická s `computeEffectivePrices` iterací, ale bez 2. round-tripu + PHP loopů.
	 *
	 * Discount/surcharge/currency conversion se aplikuje až v PHP (`applyDiscountSurcharge`) — potřebuje
	 * entity metadata (allowDiscountLevel, allowSurchargeLevel), které v SQL nejsou dostupné.
	 * @param array<mixed> $filters
	 * @param array<string|int, \Eshop\DB\VisibilityList> $visibilityLists
	 * @param array<\Eshop\DB\Pricelist> $priceLists
	 * @param list<string>|null $categoryUuidsOut Out-parametr: pokud byl category filter, obsahuje expanded category UUIDs.
	 * @return list<\stdClass>
	 */
	protected function fetchCandidateProducts(
		array $filters,
		array $visibilityLists,
		array $priceLists,
		string|null $orderByName,
		string $orderByDirection,
		array|null &$categoryUuidsOut = null,
	): array {
		$collection = $this->productRepository->many();
		$collection->setSmartJoin(false);
		$collection->where('this.deletedTs IS NULL');

		$this->productRepository->joinVisibilityListItemToProductCollection($collection, $visibilityLists);

		$collection->join(['displayAmount' => 'eshop_displayamount'], 'this.fk_displayAmount = displayAmount.uuid', type: 'LEFT');

		$collection->setSelect([
			'uuid' => 'this.uuid',
			'id' => 'this.id',
			'producer' => 'this.fk_producer',
			'displayAmount' => 'this.fk_displayAmount',
			'displayDelivery' => 'this.fk_displayDelivery',
			'masterProduct' => 'this.fk_masterProduct',
			'attributeValues' => 'this.denormalizedAttributeValues',
			'displayAmount_isSold' => 'displayAmount.isSold',
			'discountLevelPct' => 'this.discountLevelPct',
			'isProjectProduct' => 'this.isProjectProduct',
			'projectIc' => 'this.projectIc',
			'hidden' => 'visibilityListItem.hidden',
			'hiddenInMenu' => 'visibilityListItem.hiddenInMenu',
			'unavailable' => 'visibilityListItem.unavailable',
			'recommended' => 'visibilityListItem.recommended',
			'priority' => 'visibilityListItem.priority',
		]);

		// `name` selectujeme jen když podle něj řadíme — jinak ušetříme transfer 11k názvů.
		if ($orderByName === 'name') {
			$collection->select(['name' => 'this.name' . $this->connection->getMutationSuffix()]);
		}

		$this->applyCategoryFilter($collection, $filters, $categoryUuidsOut);
		unset($filters['category']);

		// Pricelist filtrace se řeší mimo SQL — per-customer PHP-side přes `fetchPricelistMembership()` map, která
		// je shared mezi customery. Dřívější EXISTS prefilter tady naopak zpomaloval (optimizer si vynutil semijoin
		// s Start/End temporary), takže kandidátský dotaz je teď generic (customer-independent) a cachovatelný
		// shared mezi všemi asistent/eshop requesty.
		$this->applyCollectionFilters($collection, $filters, $visibilityLists, $priceLists);

		// Řazení priority/name lze aplikovat v SQL, price se řeší v PHP po výpočtu ceny.
		if ($orderByName === 'priority') {
			$collection->orderBy(['visibilityListItem.priority' => $orderByDirection, 'this.name' . $this->connection->getMutationSuffix() => 'ASC']);
		} elseif ($orderByName === 'name') {
			$collection->orderBy(['this.name' . $this->connection->getMutationSuffix() => $orderByDirection]);
		}

		$collection->setGroupBy(['this.uuid']);

		$fetched = [];

		foreach ($collection->fetchArray(\stdClass::class) as $row) {
			$fetched[] = $row;
		}

		return $fetched;
	}

	/**
	 * Fáze 2: batch-fetch cen ze všech pricelistů pro nalezené produkty.
	 *
	 * Pro velké product sety (>1000) používá server-side EXISTS filtr místo `IN (...)` s tisíci UUIDy —
	 * SQL string pak není 400 KB + 11k PDO bindings, ale pár stovek bytů a několik parametrů.
	 * @param list<string> $productUuids UUIDy nalezených produktů (pro small sets nebo když není category context).
	 * @param list<string> $pricelistPKs Seznam povolených pricelist PKs.
	 * @param list<string>|null $categoryUuids Pokud byl category filter, expanded category UUIDs — dovoluje server-side EXISTS.
	 * @param array<string|int, \Eshop\DB\VisibilityList> $visibilityLists Pro server-side visibility filtr.
	 * @return array<string, list<\stdClass>> product UUID => list of price rows
	 */
	protected function fetchPricesByProduct(array $productUuids, array $pricelistPKs, array|null $categoryUuids = null, array $visibilityLists = []): array
	{
		if (!$productUuids || !$pricelistPKs) {
			return [];
		}

		$includeHiddenPrices = $this->shopperUser->canViewHiddenPrices();

		// Pro velké product sety vždy preferujeme server-side filter (EXISTS visibility + volitelně category) —
		// i bez category filtru (např. /produkty) by IN(205k UUIDs) MariaDB nezvládla.
		$useServerSideFilter = $visibilityLists !== [] && \count($productUuids) > 1000;

		if ($useServerSideFilter) {
			return $this->fetchPricesByServerSideFilter($pricelistPKs, $categoryUuids ?? [], $visibilityLists, $includeHiddenPrices);
		}

		$pricesQuery = $this->priceRepository->many()
			->setSelect([
				'fk_product' => 'this.fk_product',
				'fk_pricelist' => 'this.fk_pricelist',
				'price' => 'this.price',
				'priceVat' => 'this.priceVat',
				'priceBefore' => 'this.priceBefore',
				'priceVatBefore' => 'this.priceVatBefore',
				'hidden' => 'this.hidden',
			])
			->where('this.fk_product', $productUuids)
			->where('this.fk_pricelist', $pricelistPKs);

		if (!$includeHiddenPrices) {
			$pricesQuery->where('this.hidden = 0');
		}

		/** @var array<string, list<\stdClass>> $byProduct */
		$byProduct = [];

		foreach ($pricesQuery->fetchArray(\stdClass::class) as $row) {
			$byProduct[$row->fk_product][] = $row;
		}

		return $byProduct;
	}

	/**
	 * Vrátí pro každý produkt best-price (priority-first) jako mapu fk_product → stdClass{price,priceVat,priceBefore,priceVatBefore,fk_pricelist}.
	 *
	 * Používá ROW_NUMBER OVER (PARTITION BY fk_product ORDER BY FIELD(fk_pricelist, ...)) — priority-first
	 * selection v SQL, takže vrátí max 1 řádek per produkt. Transfer ~11k řádků místo ~55k.
	 * Visibility + category EXISTS filtry zajistí, že vrátíme ceny jen pro relevantní produkty.
	 * @param list<string> $pricelistPKs Seřazené podle priority ASC.
	 * @param list<string>|null $categoryUuids Expanded category UUIDs (z applyCategoryFilter).
	 * @param array<string|int, \Eshop\DB\VisibilityList> $visibilityLists
	 * @return array<string, \stdClass> fk_product → price row
	 */
	protected function fetchBestPricesByProduct(array $pricelistPKs, array|null $categoryUuids, array $visibilityLists): array
	{
		if ($pricelistPKs === []) {
			return [];
		}

		$includeHiddenPrices = $this->shopperUser->canViewHiddenPrices();
		$params = [];

		$plQuoted = [];

		foreach ($pricelistPKs as $i => $pk) {
			$key = 'pl' . $i;
			$plQuoted[] = ':' . $key;
			$params[$key] = $pk;
		}

		$plInClause = \implode(',', $plQuoted);

		$catExists = '';

		if ($categoryUuids !== null && $categoryUuids !== []) {
			$catPlaceholders = [];

			foreach ($categoryUuids as $i => $uuid) {
				$key = 'cat' . $i;
				$catPlaceholders[] = ':' . $key;
				$params[$key] = $uuid;
			}

			$catExists = ' AND EXISTS (SELECT 1 FROM eshop_product_nxn_eshop_category pnc WHERE pnc.fk_product = pr.fk_product AND pnc.fk_category IN (' . \implode(',', $catPlaceholders) . '))';
		}

		$vlPlaceholders = [];

		foreach (\array_values($visibilityLists) as $i => $vl) {
			$key = 'vl' . $i;
			$vlPlaceholders[] = ':' . $key;
			$params[$key] = (string) $vl->getPK();
		}

		$vlExists = $vlPlaceholders !== []
			? ' AND EXISTS (SELECT 1 FROM eshop_visibilitylistitem vli WHERE vli.fk_product = pr.fk_product AND vli.fk_visibilityList IN (' . \implode(',', $vlPlaceholders) . '))'
			: '';

		$hiddenCondition = $includeHiddenPrices ? '' : ' AND pr.hidden = 0';

		$sql = 'SELECT ranked.fk_product, ranked.price, ranked.priceVat, ranked.priceBefore, ranked.priceVatBefore, ranked.fk_pricelist'
			. ' FROM ('
			. '   SELECT pr.fk_product, pr.price, pr.priceVat, pr.priceBefore, pr.priceVatBefore, pr.fk_pricelist,'
			. '     ROW_NUMBER() OVER (PARTITION BY pr.fk_product ORDER BY FIELD(pr.fk_pricelist, ' . $plInClause . ')) AS rn'
			. '   FROM eshop_price pr'
			. '   WHERE pr.fk_pricelist IN (' . $plInClause . ') AND pr.price IS NOT NULL' . $hiddenCondition . $catExists . $vlExists
			. ' ) AS ranked'
			. ' WHERE ranked.rn = 1';

		$statement = $this->connection->query($sql, $params);

		/** @var array<string, \stdClass> $byProduct */
		$byProduct = [];

		foreach ($statement->fetchAll(\PDO::FETCH_OBJ) as $row) {
			$byProduct[$row->fk_product] = $row;
		}

		return $byProduct;
	}

	/**
	 * @deprecated Nahrazeno fetchBestPricesByProduct + applyDiscountSurcharge pipeline.
	 * Server-side varianta `fetchPricesByProduct` — priority-first vítěz přes `ROW_NUMBER() OVER (PARTITION BY fk_product ORDER BY pl.priority)`.
	 *
	 * Vrací pro každý produkt **jediný řádek** — cenu z pricelistu s nejvyšší prioritou (nejnižší priority value),
	 * kde má produkt `price IS NOT NULL`. Priority-first semantika: i cena `0` se respektuje jako platná,
	 * filtr na zero prices (showZeroPrices) se aplikuje v PHP na finální hodnotu.
	 *
	 * Oproti původnímu fetchPriceByProduct které vrátilo všechny ceny per produkt (5× řádků), toto vrací 1× řádek
	 * per produkt — server-side redukce transferu.
	 * @param list<string> $pricelistPKs
	 * @param list<string> $categoryUuids
	 * @param array<string|int, \Eshop\DB\VisibilityList> $visibilityLists
	 * @return array<string, list<\stdClass>> Pro každý produkt list s 1 cenou (priority winner) pro kompatibilitu s `computeEffectivePrices`.
	 */
	protected function fetchPricesByServerSideFilter(array $pricelistPKs, array $categoryUuids, array $visibilityLists, bool $includeHiddenPrices): array
	{
		$params = [];
		$plPlaceholders = [];

		foreach ($pricelistPKs as $i => $pk) {
			$key = 'plPk' . $i;
			$plPlaceholders[] = ':' . $key;
			$params[$key] = $pk;
		}

		$catPlaceholders = [];

		foreach ($categoryUuids as $i => $uuid) {
			$key = 'catPk' . $i;
			$catPlaceholders[] = ':' . $key;
			$params[$key] = $uuid;
		}

		$vlPlaceholders = [];

		foreach (\array_values($visibilityLists) as $i => $vl) {
			$key = 'vlPk' . $i;
			$vlPlaceholders[] = ':' . $key;
			$params[$key] = (string) $vl->getPK();
		}

		$hiddenCondition = $includeHiddenPrices ? '' : ' AND this.hidden = 0';

		// Fetch všech cen pro produkty matchující visibility (+ volitelně category) constraint.
		// Priority-first výběr se pak aplikuje v PHP v `computeEffectivePrices`.
		// Category EXISTS přidáme jen pokud je relevant (např. /produkty bez category → zahrnujeme vše).
		$categoryExists = $categoryUuids === []
			? ''
			: ' AND EXISTS (SELECT 1 FROM eshop_product_nxn_eshop_category pnc WHERE pnc.fk_product = this.fk_product AND pnc.fk_category IN (' . \implode(',', $catPlaceholders) . '))';

		$sql = 'SELECT this.fk_product, this.fk_pricelist, this.price, this.priceVat, this.priceBefore, this.priceVatBefore, this.hidden
			FROM eshop_price AS this
			WHERE this.fk_pricelist IN (' . \implode(',', $plPlaceholders) . ')'
			. $hiddenCondition
			. $categoryExists
			. ' AND EXISTS (SELECT 1 FROM eshop_visibilitylistitem vli
				JOIN eshop_visibilitylist vl ON vl.uuid = vli.fk_visibilityList
				WHERE vli.fk_product = this.fk_product AND vl.uuid IN (' . \implode(',', $vlPlaceholders) . '))';

		$statement = $this->connection->query($sql, $params);

		/** @var array<string, list<\stdClass>> $byProduct */
		$byProduct = [];

		foreach ($statement->fetchAll(\PDO::FETCH_OBJ) as $row) {
			$byProduct[$row->fk_product][] = $row;
		}

		return $byProduct;
	}

	/**
	 * Spočítá efektivní cenu pro každý produkt (PHP ekvivalent SQL `sqlHandlePrice` z ProductRepository::getProducts):
	 * LEAST přes pricelists podle priority, discount, surcharge, currency. Produkty bez platné ceny zůstanou bez `price`.
	 *
	 * SQL variantu (LEAST+CONCAT_WS+sqlExplode) jsme zkoušeli, ale je ~5× pomalejší: MariaDB musí pro každý
	 * z 11k řádků vyhodnotit 5 LEFT JOIN+nested IF+SUBSTRING_INDEX. PHP loop přes 11k produktů je ~15ms.
	 * @param list<\stdClass> $products
	 * @param array<string, list<\stdClass>> $pricesByProduct
	 * @param array<\Eshop\DB\Pricelist> $priceLists Ordered podle priority ASC (první = nejvyšší priorita).
	 */
	protected function computeEffectivePrices(array &$products, array $pricesByProduct, array $priceLists): void
	{
		$discountCoupon = $this->shopperUser->getCheckoutManager()->getDiscountCoupon();
		$customer = $this->shopperUser->getCustomer();
		$customerGroup = $this->shopperUser->getCustomerGroup();

		$discountLevelPct = $this->productRepository->getDiscountPct($customer, $customerGroup, $discountCoupon);
		$maxProductDiscountLevel = (int) ($customer->maxDiscountProductPct ?? $customerGroup->defaultMaxDiscountProductPct ?? 100);
		$surchargeLevelPct = $this->productRepository->getSurchargePct($customer);

		$currency = $this->shopperUser->getCurrency();
		$prec = $currency->calculationPrecision;
		$convertRatio = $currency->isConversionEnabled() ? $currency->convertRatio : null;

		// Precompute pricelist PKs a flags jednou před outer loopem — původní kód volal
		// $pricelist->getPK() v inner loopu pro každý produkt, což na produktovém výpisu s 47k
		// produkty a ~11 pricelistech generovalo půl milionu volání StORM\Entity::getPK
		// (reflection-based) a zbytečný CPU čas (~0.9 s self v xhprof).
		$pricelistMeta = [];

		foreach ($priceLists as $pricelist) {
			$pricelistMeta[] = [
				'pk' => $pricelist->getPK(),
				'allowSurchargeLevel' => $pricelist->allowSurchargeLevel,
				'allowDiscountLevel' => $pricelist->allowDiscountLevel,
			];
		}

		foreach ($products as $product) {
			/** @var list<\stdClass> $productPrices */
			$productPrices = $pricesByProduct[$product->uuid] ?? [];

			if ($productPrices === []) {
				$product->price = null;
				$product->priceVat = null;
				$product->priceBefore = 0.0;
				$product->priceVatBefore = 0.0;
				$product->priceList = null;

				continue;
			}

			// Index cen podle pricelist PK — inner loop pak dělá O(1) lookup místo linear scan (11×11 = 121 porovnání/produkt).
			$pricesByPricelist = [];

			foreach ($productPrices as $row) {
				$pricesByPricelist[$row->fk_pricelist] = $row;
			}

			$effectiveDiscount = \max(\min((int) ($product->discountLevelPct ?? 0), $maxProductDiscountLevel), $discountLevelPct);
			$best = null;

			// Priority-first: iterujeme pricelisty v pořadí priority ASC (nejnižší priority = nejvyšší přednost)
			// a vezmeme cenu z PRVNÍHO pricelistu, kde produkt má platnou cenu. Nevybíráme MIN(price) napříč.
			// Tím respektujeme, že customer-specific ceník přebije obecný, i když má vyšší cenu.
			// Odpovídá SQL LEAST(IF(price IS NULL,'X',CONCAT_WS('|',priority,...))) v ProductRepository::getProducts().
			foreach ($pricelistMeta as $meta) {
				$pricelistPK = $meta['pk'];
				$priceRow = $pricesByPricelist[$pricelistPK] ?? null;

				if ($priceRow === null || $priceRow->price === null) {
					continue;
				}

				$price = $convertRatio === null ? (float) $priceRow->price : \round(((float) $priceRow->price) * $convertRatio, $prec);
				$priceVat = $priceRow->priceVat === null
					? $price
					: ($convertRatio === null ? (float) $priceRow->priceVat : \round(((float) $priceRow->priceVat) * $convertRatio, $prec));
				$priceBeforeRaw = $priceRow->priceBefore === null
					? 0.0
					: ($convertRatio === null ? (float) $priceRow->priceBefore : \round(((float) $priceRow->priceBefore) * $convertRatio, $prec));
				$priceVatBeforeRaw = $priceRow->priceVatBefore === null
					? 0.0
					: ($convertRatio === null ? (float) $priceRow->priceVatBefore : \round(((float) $priceRow->priceVatBefore) * $convertRatio, $prec));

				if ($surchargeLevelPct > 0 && $meta['allowSurchargeLevel']) {
					$surchargeDivisor = 1 - ($surchargeLevelPct / 100);

					if ($surchargeDivisor > 0) {
						$price = \round($price / $surchargeDivisor, $prec);
						$priceVat = \round($priceVat / $surchargeDivisor, $prec);
					}
				}

				if ($meta['allowDiscountLevel'] && $effectiveDiscount > 0) {
					$discountFactor = (100 - $effectiveDiscount) / 100;
					$price = \round($price * $discountFactor, $prec);
					$priceVat = \round($priceVat * $discountFactor, $prec);

					if ($priceBeforeRaw === 0.0) {
						$priceBeforeRaw = \round($price / $discountFactor, $prec);
						$priceVatBeforeRaw = \round($priceVat / $discountFactor, $prec);
					}
				}

				$best = [
					'price' => $price,
					'priceVat' => $priceVat,
					'priceBefore' => $priceBeforeRaw,
					'priceVatBefore' => $priceVatBeforeRaw,
					'pricelist' => $pricelistPK,
				];

				break;
			}

			if ($best === null) {
				$product->price = null;
				$product->priceVat = null;
				$product->priceBefore = 0.0;
				$product->priceVatBefore = 0.0;
				$product->priceList = null;

				continue;
			}

			$product->price = $best['price'];
			$product->priceVat = $best['priceVat'];
			$product->priceBefore = $best['priceBefore'];
			$product->priceVatBefore = $best['priceVatBefore'];
			$product->priceList = $best['pricelist'];
		}
	}

	/**
	 * Aplikuje discount/surcharge/currency conversion na produkty, které už mají raw price + priceList z SQL.
	 *
	 * Lehká varianta dřívějšího `computeEffectivePrices` — priority-first výběr pricelistu proběhl v SQL
	 * (correlated subquery ORDER BY pl.priority ASC LIMIT 1), takže PHP jen aplikuje modifikátory na jednu cenu.
	 * @param list<\stdClass> $products Produkty (uuid → stdClass).
	 * @param array<string, \stdClass> $pricesByProduct fk_product → price row z fetchBestPricesByProduct.
	 * @param array<\Eshop\DB\Pricelist> $priceLists Indexed by PK.
	 */
	protected function applyDiscountSurcharge(array &$products, array $pricesByProduct, array $priceLists): void
	{
		$discountCoupon = $this->shopperUser->getCheckoutManager()->getDiscountCoupon();
		$customer = $this->shopperUser->getCustomer();
		$customerGroup = $this->shopperUser->getCustomerGroup();

		$discountLevelPct = $this->productRepository->getDiscountPct($customer, $customerGroup, $discountCoupon);
		$maxProductDiscountLevel = (int) ($customer->maxDiscountProductPct ?? $customerGroup->defaultMaxDiscountProductPct ?? 100);
		$surchargeLevelPct = $this->productRepository->getSurchargePct($customer);

		$currency = $this->shopperUser->getCurrency();
		$prec = $currency->calculationPrecision;
		$convertRatio = $currency->isConversionEnabled() ? $currency->convertRatio : null;

		// Index pricelistů pro rychlý lookup allowDiscountLevel/allowSurchargeLevel.
		$pricelistMap = [];

		foreach ($priceLists as $pl) {
			$pricelistMap[(string) $pl->getPK()] = $pl;
		}

		foreach ($products as $product) {
			$priceRow = $pricesByProduct[$product->uuid] ?? null;

			if ($priceRow === null || $priceRow->price === null) {
				$product->price = null;
				$product->priceVat = null;
				$product->priceBefore = 0.0;
				$product->priceVatBefore = 0.0;
				$product->priceList = null;

				continue;
			}

			$product->priceList = $priceRow->fk_pricelist;

			$price = $convertRatio === null ? (float) $priceRow->price : \round(((float) $priceRow->price) * $convertRatio, $prec);
			$priceVat = $priceRow->priceVat === null
				? $price
				: ($convertRatio === null ? (float) $priceRow->priceVat : \round(((float) $priceRow->priceVat) * $convertRatio, $prec));
			$priceBefore = $priceRow->priceBefore === null
				? 0.0
				: ($convertRatio === null ? (float) $priceRow->priceBefore : \round(((float) $priceRow->priceBefore) * $convertRatio, $prec));
			$priceVatBefore = $priceRow->priceVatBefore === null
				? 0.0
				: ($convertRatio === null ? (float) $priceRow->priceVatBefore : \round(((float) $priceRow->priceVatBefore) * $convertRatio, $prec));

			$pricelist = $pricelistMap[$product->priceList] ?? null;

			if ($pricelist !== null && $surchargeLevelPct > 0 && $pricelist->allowSurchargeLevel) {
				$surchargeDivisor = 1 - ($surchargeLevelPct / 100);

				if ($surchargeDivisor > 0) {
					$price = \round($price / $surchargeDivisor, $prec);
					$priceVat = \round($priceVat / $surchargeDivisor, $prec);
				}
			}

			$effectiveDiscount = \max(\min((int) ($product->discountLevelPct ?? 0), $maxProductDiscountLevel), $discountLevelPct);

			if ($pricelist !== null && $pricelist->allowDiscountLevel && $effectiveDiscount > 0) {
				$discountFactor = (100 - $effectiveDiscount) / 100;
				$price = \round($price * $discountFactor, $prec);
				$priceVat = \round($priceVat * $discountFactor, $prec);

				if ($priceBefore === 0.0) {
					$priceBefore = \round($price / $discountFactor, $prec);
					$priceVatBefore = \round($priceVat / $discountFactor, $prec);
				}
			}

			$product->price = $price;
			$product->priceVat = $priceVat;
			$product->priceBefore = $priceBefore;
			$product->priceVatBefore = $priceVatBefore;
		}
	}

	/**
	 * Aplikuje filtr kategorie — expanduje category včetně descendants (respektující showDescendantProducts/showProductsInAncestors)
	 * a aplikuje EXISTS přes nxn tabulku.
	 * @param \StORM\ICollection<\Eshop\DB\Product> $collection
	 * @param array<mixed> $filters
	 * @param list<string>|null $categoryUuidsOut Vrací expanded category UUIDs pro reuse v jiných metodách.
	 */
	protected function applyCategoryFilter(ICollection $collection, array $filters, array|null &$categoryUuidsOut = null): void
	{
		if (!isset($filters['category'])) {
			return;
		}

		/** @var \Eshop\DB\Category|null $category */
		$category = $this->categoryRepository->many()
			->where('this.path', $filters['category'])
			->first();

		if ($category === null) {
			$collection->where('1=0');

			return;
		}

		$categoryUuids = [$category->getPK()];

		if ($category->showDescendantProducts) {
			$descendantUuids = $category->getDescendants()
				->where('showProductsInAncestors', true)
				->toArrayOf('uuid', toArrayValues: true);

			foreach ($descendantUuids as $uuid) {
				$categoryUuids[] = $uuid;
			}
		}

		// EXISTS přes NxN tabulku — používá indexy `fk_product` i `fk_category`, O(1) per produkt.
		// Oproti dřívějšímu `FIND_IN_SET(...) OR ...` (30× neindexovatelné podmínky na 205k řádků = ~1s)
		// je to řádově rychlejší.
		// Ancestor resolution: `$categoryUuids` obsahuje direct + descendants, takže produkty přiřazené
		// k libovolnému z nich (přes admin/import) matchnou — `denormalizedCategories` v SQL tu nepotřebujeme.
		$params = [];
		$placeholders = [];

		foreach ($categoryUuids as $i => $uuid) {
			$key = 'catUuid' . $i;
			$placeholders[] = ':' . $key;
			$params[$key] = $uuid;
		}

		$collection->where(
			'EXISTS (SELECT 1 FROM eshop_product_nxn_eshop_category AS pnc WHERE pnc.fk_product = this.uuid AND pnc.fk_category IN (' . \implode(',', $placeholders) . '))',
			$params,
		);

		$categoryUuidsOut = $categoryUuids;
	}

	/**
	 * @param \StORM\ICollection<\Eshop\DB\Product> $collection
	 * @param array<mixed> $filters
	 * @param array<string|int, \Eshop\DB\VisibilityList> $visibilityLists
	 * @param array<\Eshop\DB\Pricelist> $priceLists
	 */
	protected function applyCollectionFilters(ICollection $collection, array $filters, array $visibilityLists, array $priceLists): void
	{
		foreach ($filters as $filter => $value) {
			if ($filter === 'attributes') {
				// Atributy jsou dynamické — zpracují se v PHP fázi.
				continue;
			}

			if (isset($this->allowedDynamicFilterExpressions[$filter]) || isset($this->allowedDynamicFilterColumns[$filter])) {
				// Dynamické filtry se zpracují v PHP fázi.
				continue;
			}

			if (isset($this->allowedCollectionFilterColumns[$filter])) {
				$collection->where($this->allowedCollectionFilterColumns[$filter], $value);

				continue;
			}

			if (isset($this->allowedCollectionFilterExpressions[$filter])) {
				$this->allowedCollectionFilterExpressions[$filter]($collection, $value, $visibilityLists, $priceLists);

				continue;
			}

			throw new \Exception("Filter '$filter' is not supported by LiveProductsProvider! You can add it manually with 'addAllowedFilterColumn' or 'addFilterExpression' functions.");
		}
	}

	/**
	 * Batch-fetch ribbons a internalRibbons pro dané produkty a namerguje je jako CSV do `ribbons`/`internalRibbons`
	 * properties na stdClass objektech. Dva jednoduché GROUP_CONCAT dotazy místo dvou korelovaných subselektů
	 * na každý řádek hlavního dotazu — zásadní zrychlení pro velké datasety.
	 *
	 * Pro velké product sety (>5000) vynecháme `IN (?, ?, …)` s tisíci parametry — místo toho načteme
	 * všechny řádky nxn tabulky a v PHP je zfiltrujeme podle map produktů. Důvod: 131k pozičních parametrů
	 * pro PDO + StORM debug log je neúnosné (Tracy bar pak trvá minuty při dump vars).
	 *
	 * Produkty bez ribbonů/internalRibbonů dostanou `null`, aby custom dynamic filter expressions
	 * (např. 'contract' ve FrontendPresenter) mohly spoléhat na existenci property.
	 * @param list<\stdClass> $products
	 * @param list<string> $productUuids
	 */
	protected function mergeRibbons(array &$products, array $productUuids): void
	{
		$ribbonsByProduct = [];
		$internalRibbonsByProduct = [];

		if ($productUuids !== []) {
			$useFullScan = \count($productUuids) > 5000;
			$productMap = $useFullScan ? \array_flip($productUuids) : [];

			if ($useFullScan) {
				// Full-scan: UNION ALL obou NxN tabulek v jednom dotazu — ušetří 1 DB round-trip (~25ms v DDEV).
				$statement = $this->connection->query(
					"SELECT 'R' AS t, fk_product, GROUP_CONCAT(fk_ribbon) AS v FROM eshop_product_nxn_eshop_ribbon GROUP BY fk_product
					UNION ALL
					SELECT 'I' AS t, fk_product, GROUP_CONCAT(fk_internalribbon) AS v FROM eshop_product_nxn_eshop_internalribbon GROUP BY fk_product",
				);

				foreach ($statement->fetchAll(\PDO::FETCH_ASSOC) as $row) {
					if (!isset($productMap[$row['fk_product']])) {
						continue;
					}

					if ($row['t'] === 'R') {
						$ribbonsByProduct[$row['fk_product']] = $row['v'];
					} else {
						$internalRibbonsByProduct[$row['fk_product']] = $row['v'];
					}
				}
			} else {
				$placeholders = \implode(',', \array_fill(0, \count($productUuids), '?'));

				$statement = $this->connection->query(
					"SELECT 'R' AS t, fk_product, GROUP_CONCAT(fk_ribbon) AS v FROM eshop_product_nxn_eshop_ribbon WHERE fk_product IN ($placeholders) GROUP BY fk_product
					UNION ALL
					SELECT 'I' AS t, fk_product, GROUP_CONCAT(fk_internalribbon) AS v FROM eshop_product_nxn_eshop_internalribbon WHERE fk_product IN ($placeholders) GROUP BY fk_product",
					[...$productUuids, ...$productUuids],
				);

				foreach ($statement->fetchAll(\PDO::FETCH_ASSOC) as $row) {
					if ($row['t'] === 'R') {
						$ribbonsByProduct[$row['fk_product']] = $row['v'];
					} else {
						$internalRibbonsByProduct[$row['fk_product']] = $row['v'];
					}
				}
			}
		}

		foreach ($products as $product) {
			$product->ribbons = $ribbonsByProduct[$product->uuid] ?? null;
			$product->internalRibbons = $internalRibbonsByProduct[$product->uuid] ?? null;
		}
	}

	/**
	 * Rozebere $filters['attributes'] a další dynamické filtry stejně jako ProductsCacheGetterService (řádky 405-467).
	 * @param array<mixed> $filters
	 * @return array{0: array<string, array<int|string, list<string>>>, 1: array<string, mixed>, 2: array<string, \Eshop\DB\Attribute>}
	 */
	protected function resolveDynamicFilters(array $filters): array
	{
		/** @var array<string, \Eshop\DB\Attribute> $allAttributes */
		$allAttributes = [];
		/** @var array<string, array<int|string, list<string>>> $dynamicFiltersAttributes */
		$dynamicFiltersAttributes = [];
		/** @var array<string, mixed> $dynamicFilters */
		$dynamicFilters = [];

		foreach ($filters as $filter => $value) {
			if ($filter === 'attributes') {
				foreach ($value as $subKey => $subValue) {
					if ($subKey === 'availability') {
						$dynamicFilters["systemicAttributes.$subKey"] = \array_flip((array) $subValue);

						continue;
					}

					if ($subKey === 'producer') {
						$dynamicFilters["systemicAttributes.$subKey"] = \array_flip((array) $subValue);

						continue;
					}

					if ($subKey === 'delivery') {
						$dynamicFilters["systemicAttributes.$subKey"] = \array_flip((array) $subValue);

						continue;
					}

					/** @var \Eshop\DB\Attribute $attribute */
					$attribute = $this->attributeRepository->many()->where('this.uuid', $subKey)->first(true);
					$attributePK = (string) $attribute->getPK();
					$allAttributes[$attributePK] = $attribute;

					if ($attribute->showRange) {
						$attributeValues = $this->attributeValueRepository->many()
							->where('this.fk_attributevaluerange', $subValue)
							->toArray();

						foreach ($attributeValues as $attributeValue) {
							$dynamicFiltersAttributes[$attributePK][(string) $attributeValue->getValue('attributeValueRange')][] = (string) $attributeValue->getPK();
						}
					} elseif ($attribute->showNumericSlider) {
						$query = $this->attributeValueRepository->many();

						if (isset($subValue['from'])) {
							$query->where('this.number >= :from OR this.numberFrom >= :from', ['from' => $subValue['from']]);
						}

						if (isset($subValue['to'])) {
							$query->where('this.number <= :to OR this.numberTo <= :to', ['to' => $subValue['to']]);
						}

						$dynamicFiltersAttributes[$attributePK] = $query
							->where('this.fk_attribute', $attributePK)
							->toArrayOf('uuid', toArrayValues: true);
					} else {
						$dynamicFiltersAttributes[$attributePK] = (array) $subValue;
					}
				}

				continue;
			}

			if (!isset($this->allowedDynamicFilterExpressions[$filter]) && !isset($this->allowedDynamicFilterColumns[$filter])) {
				continue;
			}

			$dynamicFilters[$filter] = $value;
		}

		return [$dynamicFiltersAttributes, $dynamicFilters, $allAttributes];
	}

	/**
	 * Fáze 3: iterace produktů v PHP — dynamické filtry atributů, systemic atributy, facet počty, price range.
	 * @param list<\stdClass> $products
	 * @param array<string, array<int|string, list<string>>> $dynamicFiltersAttributes Attribute PK => [valueList] (pro showRange: [rangeKey => [valuePKs]])
	 * @param array<string, mixed> $dynamicFilters Filter name => value
	 * @param array<string, \Eshop\DB\Attribute> $allAttributes Attribute PK => Attribute
	 * @param array<string|int, \Eshop\DB\VisibilityList> $visibilityLists
	 * @param array<\Eshop\DB\Pricelist> $priceLists
	 * @return array{
	 *   filtered: list<\stdClass>,
	 *   attributeValuesCounts: array<string, int>,
	 *   displayAmountsCounts: array<string, int>,
	 *   displayDeliveriesCounts: array<string, int>,
	 *   producersCounts: array<string, int>,
	 *   priceMin: float, priceMax: float, priceVatMin: float, priceVatMax: float,
	 * }
	 */
	protected function filterAndCount(
		array $products,
		array $dynamicFiltersAttributes,
		array $dynamicFilters,
		array $allAttributes,
		array $visibilityLists,
		array $priceLists,
	): array {
		$filtered = [];
		$attributeValuesCounts = [];
		$displayAmountsCounts = [];
		$displayDeliveriesCounts = [];
		$producersCounts = [];

		$priceMin = \PHP_FLOAT_MAX;
		$priceMax = \PHP_FLOAT_MIN;
		$priceVatMin = \PHP_FLOAT_MAX;
		$priceVatMax = \PHP_FLOAT_MIN;

		$showZeroPrices = $this->shopperUser->getShowZeroPrices();
		$showVat = $this->shopperUser->getShowVat();
		$showWithoutVat = $this->shopperUser->getShowWithoutVat();

		foreach ($products as $product) {
			if (!$showZeroPrices) {
				if ($showVat && ($product->priceVat === null || $product->priceVat <= 0)) {
					continue;
				}

				if ($showWithoutVat && ($product->price === null || $product->price <= 0)) {
					continue;
				}
			}

			$attributeValues = $product->attributeValues ? \array_flip(\explode(',', (string) $product->attributeValues)) : [];

			// Filtr atributů (OR/AND/range/numericSlider)
			$failed = false;

			foreach ($dynamicFiltersAttributes as $attributePK => $attributeValuesPKs) {
				if (\count($attributeValuesPKs) === 0) {
					continue;
				}

				$attribute = $allAttributes[$attributePK];

				if ($attribute->showRange) {
					foreach ($attributeValuesPKs as $attributeValueRange) {
						$found = false;

						foreach ($attributeValueRange as $attributeValueUuid) {
							if (isset($attributeValues[$attributeValueUuid])) {
								$found = true;

								break;
							}
						}

						if (!$found) {
							$failed = true;

							break 2;
						}
					}

					continue;
				}

				if ($attribute->filterType === 'or' || $attribute->showNumericSlider) {
					$found = false;

					foreach ($attributeValuesPKs as $attributeValueUuid) {
						/** @var string $attributeValueUuid */
						if (isset($attributeValues[$attributeValueUuid])) {
							$found = true;

							break;
						}
					}

					if (!$found) {
						$failed = true;

						break;
					}

					continue;
				}

				foreach ($attributeValuesPKs as $attributeValueUuid) {
					/** @var string $attributeValueUuid */
					if (!isset($attributeValues[$attributeValueUuid])) {
						$failed = true;

						break 2;
					}
				}
			}

			if ($failed) {
				continue;
			}

			// Dynamic filters (non-attribute): price, systemic, ribbon, masterProduct, ...
			// Pro facet počty: pro každý filtr X spočítáme produkty, které projdou VŠEMI filtry kromě X.
			$dynamicallyCountedFilters = [];

			foreach (\array_keys($dynamicFilters) as $filter) {
				$subDynamicFilters = $dynamicFilters;
				unset($subDynamicFilters[$filter]);

				if (!$this->productPassesDynamicFilters($product, $subDynamicFilters, $visibilityLists, $priceLists)) {
					continue;
				}

				if ($filter === 'priceFrom') {
					$dynamicallyCountedFilters[$filter] = true;

					if ($product->price !== null && $product->price < $priceMin) {
						$priceMin = (float) $product->price;
					}

					if ($product->priceVat !== null && $product->priceVat < $priceVatMin) {
						$priceVatMin = (float) $product->priceVat;
					}
				}

				if ($filter === 'priceTo') {
					$dynamicallyCountedFilters[$filter] = true;

					if ($product->price !== null && $product->price > $priceMax) {
						$priceMax = (float) $product->price;
					}

					if ($product->priceVat !== null && $product->priceVat > $priceVatMax) {
						$priceVatMax = (float) $product->priceVat;
					}
				}

				if ($filter === 'systemicAttributes.availability' && $product->displayAmount) {
					$dynamicallyCountedFilters[$filter] = true;
					$displayAmountsCounts[$product->displayAmount] = ($displayAmountsCounts[$product->displayAmount] ?? 0) + 1;
				}

				if ($filter === 'systemicAttributes.delivery' && $product->displayDelivery) {
					$dynamicallyCountedFilters[$filter] = true;
					$displayDeliveriesCounts[$product->displayDelivery] = ($displayDeliveriesCounts[$product->displayDelivery] ?? 0) + 1;
				}

				if ($filter !== 'systemicAttributes.producer' || !$product->producer) {
					continue;
				}

				$dynamicallyCountedFilters[$filter] = true;
				$producersCounts[$product->producer] = ($producersCounts[$product->producer] ?? 0) + 1;
			}

			// Základní verze: všechny filtry musí projít, aby se produkt zařadil do výsledku.
			if (!$this->productPassesDynamicFilters($product, $dynamicFilters, $visibilityLists, $priceLists)) {
				continue;
			}

			if (!isset($dynamicallyCountedFilters['systemicAttributes.availability']) && $product->displayAmount) {
				$displayAmountsCounts[$product->displayAmount] = ($displayAmountsCounts[$product->displayAmount] ?? 0) + 1;
			}

			if (!isset($dynamicallyCountedFilters['systemicAttributes.delivery']) && $product->displayDelivery) {
				$displayDeliveriesCounts[$product->displayDelivery] = ($displayDeliveriesCounts[$product->displayDelivery] ?? 0) + 1;
			}

			if (!isset($dynamicallyCountedFilters['systemicAttributes.producer']) && $product->producer) {
				$producersCounts[$product->producer] = ($producersCounts[$product->producer] ?? 0) + 1;
			}

			if (!isset($dynamicallyCountedFilters['priceFrom']) && $product->price !== null) {
				if ($product->price < $priceMin) {
					$priceMin = (float) $product->price;
				}

				if ($product->priceVat !== null && $product->priceVat < $priceVatMin) {
					$priceVatMin = (float) $product->priceVat;
				}
			}

			if (!isset($dynamicallyCountedFilters['priceTo']) && $product->price !== null) {
				if ($product->price > $priceMax) {
					$priceMax = (float) $product->price;
				}

				if ($product->priceVat !== null && $product->priceVat > $priceVatMax) {
					$priceVatMax = (float) $product->priceVat;
				}
			}

			foreach (\array_keys($attributeValues) as $attributeValueUuid) {
				$attributeValuesCounts[(string) $attributeValueUuid] = ($attributeValuesCounts[(string) $attributeValueUuid] ?? 0) + 1;
			}

			$filtered[] = $product;
		}

		return [
			'filtered' => $filtered,
			'attributeValuesCounts' => $attributeValuesCounts,
			'displayAmountsCounts' => $displayAmountsCounts,
			'displayDeliveriesCounts' => $displayDeliveriesCounts,
			'producersCounts' => $producersCounts,
			'priceMin' => $priceMin,
			'priceMax' => $priceMax,
			'priceVatMin' => $priceVatMin,
			'priceVatMax' => $priceVatMax,
		];
	}

	/**
	 * @param array<string, mixed> $dynamicFilters
	 * @param array<string|int, \Eshop\DB\VisibilityList> $visibilityLists
	 * @param array<\Eshop\DB\Pricelist> $priceLists
	 */
	protected function productPassesDynamicFilters(\stdClass $product, array $dynamicFilters, array $visibilityLists, array $priceLists): bool
	{
		foreach ($dynamicFilters as $filter => $value) {
			if (isset($this->allowedDynamicFilterColumns[$filter])) {
				$column = $this->allowedDynamicFilterColumns[$filter];

				if ($product->{$column} === null || $product->{$column} === '') {
					return false;
				}

				if (!isset($value[$product->{$column}])) {
					return false;
				}

				continue;
			}

			if (!isset($this->allowedDynamicFilterExpressions[$filter])) {
				continue;
			}

			if (!$this->allowedDynamicFilterExpressions[$filter]($product, $value, $visibilityLists, $priceLists)) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Aplikuje řazení v PHP. Nativně: priority, price ASC/DESC, name. Ostatní přes expressions (zatím nepodporováno pro live — expressions generovaly SQL).
	 * @param list<\stdClass> $products
	 */
	protected function applyOrdering(array &$products, string|null $orderByName, string $orderByDirection): void
	{
		if ($orderByName === null) {
			return;
		}

		if ($orderByName === 'priority') {
			// Priority je už seřazené z SQL (ORDER BY visibilityListItem.priority).
			return;
		}

		if ($orderByName === 'name') {
			// Name je taky seřazené z SQL.
			return;
		}

		$dir = $orderByDirection === 'DESC' ? -1 : 1;

		if ($orderByName === 'price') {
			\usort($products, static function (\stdClass $a, \stdClass $b) use ($dir): int {
				$aPrice = $a->price ?? \PHP_FLOAT_MAX;
				$bPrice = $b->price ?? \PHP_FLOAT_MAX;

				return $dir * ($aPrice <=> $bPrice);
			});

			return;
		}

		// Default ordering "Doporučujeme": priority → availability → price.
		// Odpovídá cache-side expression 'priorityAvailabilityPrice' v ProductsCacheGetterService.
		if ($orderByName === 'priorityAvailabilityPrice') {
			// array_multisort jede nativně v C a je násobně rychlejší než usort s PHP closure comparator
			// (xhprof na 47k produktech: usort-based verze ~1 s self+closure, array_multisort redukuje pod 100 ms).
			// Availability weight: 0 = in stock, 2 = unknown, 1 = sold out (odpovídá původnímu match expression).
			$priorities = [];
			$availabilities = [];
			$prices = [];

			foreach ($products as $product) {
				$priorities[] = $product->priority ?? \PHP_INT_MAX;
				$availabilities[] = match ((int) ($product->displayAmount_isSold ?? 2)) {
					0 => 0,
					2 => 1,
					default => 2,
				};
				$prices[] = $product->price ?? \PHP_FLOAT_MAX;
			}

			$sortDir = $orderByDirection === 'DESC' ? \SORT_DESC : \SORT_ASC;
			\array_multisort(
				$priorities,
				$sortDir,
				\SORT_NUMERIC,
				$availabilities,
				$sortDir,
				\SORT_NUMERIC,
				$prices,
				$sortDir,
				\SORT_NUMERIC,
				$products,
			);

			return;
		}

		if (isset($this->allowedCollectionOrderColumns[$orderByName])) {
			$column = $this->allowedCollectionOrderColumns[$orderByName];

			\usort($products, static function (\stdClass $a, \stdClass $b) use ($column, $dir): int {
				$aVal = $a->{$column} ?? null;
				$bVal = $b->{$column} ?? null;

				return $dir * ($aVal <=> $bVal);
			});

			return;
		}

		if (isset($this->allowedCollectionOrderExpressions[$orderByName])) {
			// Expression-based ordery byly cache-only (generovaly SQL nad cache schématem). Není cesta je přímo aplikovat zde.
			// V budoucnu by šlo interpretovat, zatím to necháme bez efektu.
			return;
		}

		throw new \Exception("Order '$orderByName' is not supported by LiveProductsProvider.");
	}

	/**
	 * @param array{
	 *   filtered: list<\stdClass>,
	 *   attributeValuesCounts: array<string, int>,
	 *   displayAmountsCounts: array<string, int>,
	 *   displayDeliveriesCounts: array<string, int>,
	 *   producersCounts: array<string, int>,
	 *   priceMin: float, priceMax: float, priceVatMin: float, priceVatMax: float,
	 * } $result
	 * @return array{
	 *   productPKs: list<string>,
	 *   attributeValuesCounts: array<string|int, int>,
	 *   displayAmountsCounts: array<string|int, int>,
	 *   displayDeliveriesCounts: array<string|int, int>,
	 *   producersCounts: array<string|int, int>,
	 *   priceMin: float, priceMax: float, priceVatMin: float, priceVatMax: float,
	 * }
	 */
	protected function buildOutput(array $result): array
	{
		$productPKs = [];

		foreach ($result['filtered'] as $product) {
			// Drop-in kompatibilita s cache providerem: ProductList.php:255 dělá `$source->where('this.id', $pagedProducts)`,
			// StORM akceptuje string hodnoty pro integer sloupce, cast na string splňuje interface contract (list<string>).
			$productPKs[] = (string) $product->id;
		}

		// AttributeValues: aggregate range-typed hodnoty do fk_attributevaluerange (stejně jako cache řádky 794-813).
		$attributeValuesCounts = $result['attributeValuesCounts'];

		if ($attributeValuesCounts) {
			$attributeValueMeta = $this->attributeValueRepository->many()
				->setSelect([
					'uuid' => 'this.uuid',
					'rangePK' => 'this.fk_attributevaluerange',
					'showRange' => 'attribute.showRange',
				])
				->join(['attribute' => 'eshop_attribute'], 'this.fk_attribute = attribute.uuid')
				->where('this.uuid', \array_keys($attributeValuesCounts))
				->fetchArray(\stdClass::class);

			foreach ($attributeValueMeta as $meta) {
				if ($meta->showRange && $meta->rangePK !== null) {
					$attributeValuesCounts[$meta->rangePK] = ($attributeValuesCounts[$meta->rangePK] ?? 0) + $attributeValuesCounts[$meta->uuid];
					unset($attributeValuesCounts[$meta->uuid]);
				}
			}
		}

		return [
			'productPKs' => $productPKs,
			'attributeValuesCounts' => $attributeValuesCounts,
			'displayAmountsCounts' => $result['displayAmountsCounts'],
			'displayDeliveriesCounts' => $result['displayDeliveriesCounts'],
			'producersCounts' => $result['producersCounts'],
			'priceMin' => $result['priceMin'] && $result['priceMin'] < \PHP_FLOAT_MAX ? \floor($result['priceMin']) : 0.0,
			'priceMax' => $result['priceMax'] && $result['priceMax'] > \PHP_FLOAT_MIN ? \ceil($result['priceMax']) : 0.0,
			'priceVatMin' => $result['priceVatMin'] && $result['priceVatMin'] < \PHP_FLOAT_MAX ? \floor($result['priceVatMin']) : 0.0,
			'priceVatMax' => $result['priceVatMax'] && $result['priceVatMax'] > \PHP_FLOAT_MIN ? \ceil($result['priceVatMax']) : 0.0,
		];
	}

	/**
	 * @return array{
	 *   productPKs: list<string>,
	 *   attributeValuesCounts: array<string|int, int>,
	 *   displayAmountsCounts: array<string|int, int>,
	 *   displayDeliveriesCounts: array<string|int, int>,
	 *   producersCounts: array<string|int, int>,
	 *   priceMin: float, priceMax: float, priceVatMin: float, priceVatMax: float,
	 * }
	 */
	protected function emptyResult(): array
	{
		return [
			'productPKs' => [],
			'attributeValuesCounts' => [],
			'displayAmountsCounts' => [],
			'displayDeliveriesCounts' => [],
			'producersCounts' => [],
			'priceMin' => 0.0,
			'priceMax' => 0.0,
			'priceVatMin' => 0.0,
			'priceVatMax' => 0.0,
		];
	}

	/**
	 * Registruje built-in filter/order expressions. Blízká kopie ProductsCacheGetterService::startUp(),
	 * přizpůsobená tomu, že tady pracujeme s UUIDy a přímými sloupci (ne s cache ID schématem).
	 */
	protected function startUp(): void
	{
		$this->allowedCollectionFilterExpressions['uuids'] = static function (ICollection $collection, array $uuids): void {
			$collection->where('this.uuid', $uuids);
		};

		$this->allowedCollectionFilterExpressions['producer'] = static function (ICollection $collection, string|null|array $producer): void {
			if ($producer !== null) {
				$collection->where('this.fk_producer', \is_array($producer) ? \array_values($producer) : $producer);
			} else {
				$collection->where('this.fk_producer IS NULL');
			}
		};

		$this->allowedCollectionFilterExpressions['producers'] = static function (ICollection $collection, array $producer): void {
			$collection->where('this.fk_producer', $producer);
		};

		$connection = $this->connection;
		$this->allowedCollectionFilterExpressions['query2'] = static function (ICollection $collection, string $query) use ($connection): void {
			$suffix = $connection->getMutationSuffix();
			$orConditions = [
				'IF(this.subCode, CONCAT(this.code, this.subCode), this.code) LIKE :qlikeq',
				'this.externalCode LIKE :qlike',
				'this.ean LIKE :qlike',
				'this.name' . $suffix . ' LIKE :qlike COLLATE utf8_general_ci',
				'this.name' . $suffix . ' LIKE :qlikeq COLLATE utf8_general_ci',
				'MATCH(this.name' . $suffix . ') AGAINST (:q)',
			];

			$collection->where(\implode(' OR ', $orConditions), [
				'q' => $query,
				'qlike' => $query . '%',
				'qlikeq' => '%' . $query . '%',
			]);
		};

		$shopperUser = $this->shopperUser;

		$this->allowedDynamicFilterExpressions['priceFrom'] = static function (\stdClass $product, mixed $value) use ($shopperUser): bool {
			$showVat = $shopperUser->getMainPriceType() === 'withVat';
			$compare = $showVat ? ($product->priceVat ?? null) : ($product->price ?? null);

			return $compare !== null && $compare >= $value;
		};

		$this->allowedDynamicFilterExpressions['priceTo'] = static function (\stdClass $product, mixed $value) use ($shopperUser): bool {
			$showVat = $shopperUser->getMainPriceType() === 'withVat';
			$compare = $showVat ? ($product->priceVat ?? null) : ($product->price ?? null);

			return $compare !== null && $compare <= $value;
		};

		$this->allowedDynamicFilterExpressions['priceGt'] = static function (\stdClass $product, mixed $value) use ($shopperUser): bool {
			$showVat = $shopperUser->getMainPriceType() === 'withVat';
			$compare = $showVat ? ($product->priceVat ?? null) : ($product->price ?? null);

			return $compare !== null && $compare > $value;
		};

		$this->allowedDynamicFilterExpressions['ribbon'] = static function (\stdClass $product, mixed $value): bool {
			$ribbons = \array_flip(\explode(',', (string) ($product->ribbons ?? '')));

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

		$this->allowedDynamicFilterExpressions['notRibbon'] = static function (\stdClass $product, mixed $value): bool {
			$ribbons = \array_flip(\explode(',', (string) ($product->ribbons ?? '')));

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

		$this->allowedDynamicFilterExpressions['internalRibbon'] = static function (\stdClass $product, mixed $value): bool {
			$ribbons = \array_flip(\explode(',', (string) ($product->internalRibbons ?? '')));

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

		$this->allowedDynamicFilterExpressions['notInternalRibbon'] = static function (\stdClass $product, mixed $value): bool {
			$ribbons = \array_flip(\explode(',', (string) ($product->internalRibbons ?? '')));

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

		$this->allowedDynamicFilterExpressions['masterProduct'] = static function (\stdClass $product, mixed $value): bool {
			if ($value === true) {
				return $product->masterProduct === null;
			}

			if ($value === false) {
				return $product->masterProduct !== null;
			}

			return true;
		};
	}
}
