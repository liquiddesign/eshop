<?php

declare(strict_types=1);

/**
 * E2E parity check — daemon vs LiveProductsProvider against the real DB.
 *
 * Spouštění v DDEV (předpokládá běžící Rust daemon + `eshop.productsProvider: rust` v configu):
 *
 *     # 1) Spusť daemon (v jiném terminálu nebo na pozadí):
 *     /home/petr/eshop/bin/products-daemon-linux-x86_64 --socket /tmp/abel-products-daemon.sock
 *
 *     # 2) Spusť parity check proti live DB:
 *     ddev exec php vendor/liquiddesign/eshop/products-daemon/tests/scripts/e2e-parity-check.php
 *
 * Výstup: řádek per scenario s "OK" nebo "DIFF: ...". Exit code 1 při jakémkoli DIFF.
 *
 * Skript:
 * 1. Bootrapne Abel Nette container.
 * 2. Získá `LiveProductsProvider` (jako ground truth) a `RustDaemonClient` (přímá cesta k daemonu, obchází proxy fallback).
 * 3. Pro každý scenario (varianty filtrů) volá obě cesty a diffuje `productPKs`, `priceMin/Max`, counts.
 */

use Eshop\DB\CategoryRepository;
use Eshop\DB\CustomerRepository;
use Eshop\DB\PricelistRepository;
use Eshop\DB\VisibilityListRepository;
use Eshop\Services\ProductsCache\LiveProductsProvider;
use Eshop\Services\ProductsCache\RustDaemonClient;
use Eshop\Services\ProductsCache\RustDaemonException;
use Eshop\Services\ProductsCache\RustProxyProductsProvider;
use Eshop\ShopperUser;
use StORM\DIConnection;

$autoloaderCandidates = [
	__DIR__ . '/../../../../../autoload.php',              // vendor path (balík v abel/vendor)
	__DIR__ . '/../../../../vendor/autoload.php',          // pokud skript spouštěn z eshop repa
	__DIR__ . '/../../../vendor/autoload.php',
];

$loaded = false;

foreach ($autoloaderCandidates as $candidate) {
	if (\is_file($candidate)) {
		require $candidate;
		$loaded = true;

		break;
	}
}

if (!$loaded) {
	\fwrite(\STDERR, "Nepodařilo se najít autoload.php.\n");
	exit(1);
}

// Boot Nette container — Abel používá `App\Bootstrap`.
$bootstrapClass = '\\App\\Bootstrap';

if (!\class_exists($bootstrapClass)) {
	\fwrite(\STDERR, "App\\Bootstrap nenalezen — skript musí být spuštěn v Abel repu nebo Abel vendoru.\n");
	exit(1);
}

/** @var object $bootstrap */
$bootstrap = new $bootstrapClass();
$container = \method_exists($bootstrap, 'bootForCli')
	? $bootstrap->bootForCli()
	: $bootstrap->boot();

if ($container instanceof \Nette\Bootstrap\Configurator) {
	$container = $container->createContainer();
}

/** @var \Nette\DI\Container $container */
if (!$container instanceof \Nette\DI\Container) {
	\fwrite(\STDERR, "Bootstrap nevrátil Nette\\DI\\Container.\n");
	exit(1);
}

// --- Services ---------------------------------------------------------------------------
$live = null;

/** @var list<object> $allDefinitions */
$serviceTypes = $container->findByType(LiveProductsProvider::class);

foreach ($serviceTypes as $serviceName) {
	/** @var LiveProductsProvider $candidate */
	$candidate = $container->getService($serviceName);

	if ($candidate instanceof LiveProductsProvider) {
		$live = $candidate;

		break;
	}
}

if ($live === null) {
	\fwrite(\STDERR, "LiveProductsProvider není registrován v DI.\n");
	exit(1);
}

$client = null;
$rustServiceNames = $container->findByType(RustDaemonClient::class);

foreach ($rustServiceNames as $serviceName) {
	$candidate = $container->getService($serviceName);

	if ($candidate instanceof RustDaemonClient) {
		$client = $candidate;

		break;
	}
}

if ($client === null) {
	\fwrite(\STDERR, "RustDaemonClient není registrován — nastav `eshop.productsProvider: rust` v NEON configu a spusť ddev composer clear-nette-cache.\n");
	exit(1);
}

$shopperUser = $container->getByType(ShopperUser::class);
$pricelistRepo = $container->getByType(PricelistRepository::class);
$visibilityRepo = $container->getByType(VisibilityListRepository::class);

// --- Resolve guest context for realistic pricelists/visibility ---------------------------
$customer = $shopperUser->getCustomer();
$currency = $shopperUser->getCurrency();
$country = $shopperUser->getCountry();

$priceLists = $customer !== null
	? $pricelistRepo->getCustomerPricelists($customer, $currency, $country)->toArray()
	: $pricelistRepo->many()->toArray();
$visibilityLists = $customer !== null
	? $visibilityRepo->getVisibilityListsByCustomer($customer)->toArray()
	: $visibilityRepo->many()->toArray();

if ($priceLists === [] || $visibilityLists === []) {
	\fwrite(\STDERR, "Guest kontext vrátil prázdné pricelists/visibility. DB fixtures nejsou připraveny.\n");
	exit(1);
}

// --- Rust proxy path — postavíme novou instanci tak, abychom uměli detekovat fallback ---
$proxy = new RustProxyProductsProvider($live, $client, $shopperUser, $container->getByType(Eshop\DB\ProductRepository::class));

// --- Scenarios ---------------------------------------------------------------------------
//
// We intentionally mix guest + authed + filter variants so the diff actually exercises:
// - apply_modifiers (needs discount/surcharge/currency > 0 — authed customer catches this)
// - facet leave-one-out (needs at least one filter applied — category/producer scenarios)
// - priority-first + currency conversion on a real dataset
//
// The guest-only, no-filter scenario from M1 remains for the smoke-baseline.

// Find a category that actually has products in the live DB (top by product count).
// PHP `LiveProductsProvider::applyCategoryFilter` expects `filters.category = path` (e.g. "01.02"),
// not UUID — `CategoryRepository::many()->where('path', $filters['category'])`. The Rust daemon's
// `RustProxyProductsProvider::mapFilters` currently forwards the raw value as `categoryUuids[]`,
// so this skript uses path to stay aligned with PHP semantics.
// TODO(#M2-mapfilters): resolve path → UUID inside RustProxy::mapFilters so both paths read the
// same shape (UI always sends path; daemon needs UUID for its intern pool lookup).
$connection = $container->getByType(DIConnection::class);
$categoryRepo = $container->getByType(CategoryRepository::class);
$topCategory = $connection->rows(['c' => 'eshop_category'], ['c.uuid', 'c.path'])
	->join(['p' => 'eshop_product'], 'FIND_IN_SET(c.uuid, p.denormalizedCategories) > 0')
	->where('c.hidden = 0 AND p.deletedTs IS NULL')
	->setGroupBy(['c.uuid', 'c.path'])
	->setOrderBy(['COUNT(DISTINCT p.uuid)' => 'DESC'])
	->setTake(1)
	->fetch();

$categoryPath = $topCategory !== null && isset($topCategory->path) ? (string) $topCategory->path : null;

// Try to find any customer with an assigned customer group (proxy for "has a discount tier").
$customerRepo = $container->getByType(CustomerRepository::class);
$firstCustomer = $customerRepo->many()->where('fk_group IS NOT NULL')->setTake(1)->first();

/** @var list<array{label: string, filters: array<string, mixed>, orderBy: string|null, orderDir: string, customerUuid: string|null}> $scenarios */
$scenarios = [
	['label' => 'guest-default', 'filters' => [], 'orderBy' => null, 'orderDir' => 'ASC', 'customerUuid' => null],
	['label' => 'guest-priority-desc', 'filters' => [], 'orderBy' => 'priority', 'orderDir' => 'DESC', 'customerUuid' => null],
	['label' => 'guest-price-asc', 'filters' => [], 'orderBy' => 'price', 'orderDir' => 'ASC', 'customerUuid' => null],
];

if ($categoryPath !== null) {
	$scenarios[] = [
		'label' => 'guest-category-filter',
		'filters' => ['category' => $categoryPath],
		'orderBy' => null,
		'orderDir' => 'ASC',
		'customerUuid' => null,
	];
	$scenarios[] = [
		'label' => 'guest-category-price-desc',
		'filters' => ['category' => $categoryPath],
		'orderBy' => 'price',
		'orderDir' => 'DESC',
		'customerUuid' => null,
	];
}

if ($firstCustomer !== null) {
	$scenarios[] = [
		'label' => 'customer-default',
		'filters' => [],
		'orderBy' => null,
		'orderDir' => 'ASC',
		'customerUuid' => (string) $firstCustomer->getPK(),
	];

	if ($categoryPath !== null) {
		$scenarios[] = [
			'label' => 'customer-category-filter',
			'filters' => ['category' => $categoryPath],
			'orderBy' => 'price',
			'orderDir' => 'ASC',
			'customerUuid' => (string) $firstCustomer->getPK(),
		];
	}
} else {
	echo "[warn] no customer with account — authed scenarios skipped (apply_modifiers with discount>0 NOT covered)\n";
}

$hasDiff = false;

echo "Running E2E parity check with " . \count($scenarios) . " scenarios...\n\n";

foreach ($scenarios as $scenario) {
	$label = $scenario['label'];
	$filters = $scenario['filters'];
	$orderBy = $scenario['orderBy'];
	$orderDir = $scenario['orderDir'];
	$customerUuid = $scenario['customerUuid'];

	// Per-scenario ShopperUser state — authed scenarios need the customer set so
	// RustProxyProductsProvider::buildPriceModifiers() sees the right discount/surcharge.
	// LiveProductsProvider reads from the same $shopperUser, so both paths agree by construction.
	$scenarioCustomer = $customerUuid !== null ? $customerRepo->one($customerUuid) : null;
	$shopperUser->setCustomer($scenarioCustomer);

	$scenarioPriceLists = $scenarioCustomer !== null
		? $pricelistRepo->getCustomerPricelists($scenarioCustomer, $shopperUser->getCurrency(), $shopperUser->getCountry())->toArray()
		: $priceLists;
	$scenarioVisibilityLists = $scenarioCustomer !== null
		? $visibilityRepo->getVisibilityListsByCustomer($scenarioCustomer)->toArray()
		: $visibilityLists;

	try {
		$rustOut = $proxy->getProductsFromCacheTable($filters, $orderBy, $orderDir, $scenarioPriceLists, $scenarioVisibilityLists, false, false);
	} catch (RustDaemonException $e) {
		\fwrite(\STDERR, \sprintf("[%s] daemon failed: %s\n", $label, $e->getMessage()));
		$hasDiff = true;

		continue;
	}

	$phpOut = $live->getProductsFromCacheTable($filters, $orderBy, $orderDir, $scenarioPriceLists, $scenarioVisibilityLists, false, false);

	if ($rustOut === false || $phpOut === false) {
		\fwrite(\STDERR, \sprintf("[%s] one of the providers returned false (rust=%s php=%s)\n", $label, \var_export($rustOut, true), \var_export($phpOut, true)));
		$hasDiff = true;

		continue;
	}

	$diff = diffOutputs($rustOut, $phpOut);

	if ($diff === []) {
		\printf("[%s] OK (%d products)\n", $label, \count($rustOut['productPKs']));
	} else {
		$hasDiff = true;

		\printf("[%s] DIFF: %s\n", $label, \implode('; ', $diff));
	}
}

exit($hasDiff ? 1 : 0);

/**
 * Compare two responses from `GeneralProductsCacheProvider::getProductsFromCacheTable`.
 *
 * @param array{productPKs: list<string>, priceMin: float, priceMax: float, priceVatMin: float, priceVatMax: float, attributeValuesCounts: array<string|int, int>, displayAmountsCounts: array<string|int, int>, displayDeliveriesCounts: array<string|int, int>, producersCounts: array<string|int, int>} $rust
 * @param array{productPKs: list<string>, priceMin: float, priceMax: float, priceVatMin: float, priceVatMax: float, attributeValuesCounts: array<string|int, int>, displayAmountsCounts: array<string|int, int>, displayDeliveriesCounts: array<string|int, int>, producersCounts: array<string|int, int>} $php
 * @return list<string>
 */
function diffOutputs(array $rust, array $php): array
{
	$diff = [];

	if ($rust['productPKs'] !== $php['productPKs']) {
		$rustCount = \count($rust['productPKs']);
		$phpCount = \count($php['productPKs']);
		$missingInRust = \array_diff($php['productPKs'], $rust['productPKs']);
		$missingInPhp = \array_diff($rust['productPKs'], $php['productPKs']);
		$diff[] = \sprintf(
			'productPKs: rust=%d php=%d, missing_in_rust=%d missing_in_php=%d',
			$rustCount,
			$phpCount,
			\count($missingInRust),
			\count($missingInPhp),
		);
	}

	foreach (['priceMin', 'priceMax', 'priceVatMin', 'priceVatMax'] as $key) {
		$r = (float) ($rust[$key] ?? 0);
		$p = (float) ($php[$key] ?? 0);

		if (\abs($r - $p) > 0.01) {
			$diff[] = \sprintf('%s: rust=%.4f php=%.4f', $key, $r, $p);
		}
	}

	foreach (['producersCounts', 'displayAmountsCounts', 'displayDeliveriesCounts', 'attributeValuesCounts'] as $key) {
		$r = (array) ($rust[$key] ?? []);
		$p = (array) ($php[$key] ?? []);

		\ksort($r);
		\ksort($p);

		if ($r !== $p) {
			$diff[] = \sprintf('%s: rust keys=%d php keys=%d', $key, \count($r), \count($p));
		}
	}

	return $diff;
}
