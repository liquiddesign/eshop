# Products Cache — public API

Veřejné rozhraní pro čtení produktového katalogu (filtrace, řazení, počty kategorií, přihlašované ceny) v `eshop` balíku.

## Public API = jeden interface

Konzumenti injektují **výhradně** interface:

```php
use Eshop\Services\ProductsCache\GeneralProductsCacheProvider;

public function __construct(
    private readonly GeneralProductsCacheProvider $productsProvider,
) {
}
```

Všechny třídy ve `Eshop\Services\ProductsCache\*` kromě `GeneralProductsCacheProvider` a `ProductsCacheNotReadyException` jsou označené `@internal` — nikdy je neinjektujte přímo. Přímá injektáž obchází abstrakci a rozbije přepínač providerů.

## Co interface poskytuje

Přesné signatury v PHPDoc [`GeneralProductsCacheProvider`](../src/Services/ProductsCache/GeneralProductsCacheProvider.php). Hlavní metody:

| Metoda | Použití |
|--------|---------|
| `getProductsFromCacheTable()` | Výpis produktů s filtry, řazením, facet counts (atributy, kategorie, producenti, dostupnost) |
| `getCategoryCount()` | Počet produktů v kategorii pro daný filter-set + pricelists/visibility |
| `getSellableProductPKs()` | PKs produktů, které jsou pro nějakou kombinaci session parametrů prodejní (Algolia, feed exportéry) |
| `getIndexByCustomer()` | Cache index pro daného `Customer`/`Merchant` |
| `warmUpCacheTable()` / `updatePricesCacheTable()` | Admin operace pro cache-based providera |
| `addCollectionOrderExpression()` / `addAllowedCollectionFilterColumn()` / další `add*()` | Registrace vlastních filtrů a řazení per eshop |

Jediná výjimka, kterou konzument musí chytat, je `ProductsCacheNotReadyException` (cache ještě nebyla naplněná).

## Provider switch

Který provider DI vytvoří, určuje NEON klíč:

```neon
parameters:
    productsProvider: cache   # | live | rust
```

| Hodnota | Implementace | Kdy použít |
|---------|--------------|-----------|
| `cache` (legacy) | `ProductsCacheProvider` | Starý režim. Data čtená z oddělené cache DB plněné Go warm-upem. `@deprecated`. |
| `live` | `LiveProductsProvider` | Přímé SQL dotazy nad produkční DB a denormalizovanými sloupci. Cache DB není potřeba. |
| `rust` | `RustProxyProductsProvider` | Dotazy přes Unix socket do daemona `abel-products-daemon`. Nejrychlejší varianta. Při jakémkoliv selhání transparentně padá na `LiveProductsProvider`. |

Přepnutí hodnoty je jediný způsob, jak změnit runtime chování — kód konzumentů se nemění, protože injektuje interface.

## Rust daemon — fallback

`RustProxyProductsProvider` chytá všechny `RustDaemon*Exception` a **automaticky** přesměruje volání na `LiveProductsProvider`. Fallback trigger:

- socket nedostupný (`ECONNREFUSED`, file missing) → `RustDaemonUnavailableException`
- protokolová chyba na drátě → `RustDaemonProtocolException`
- chybová obálka z daemona → `RustDaemonRequestException`
- explicit `fallback_required: true` (např. custom filter/order výraz neznámý daemonu) → `RustDaemonFallbackRequiredException`
- timeout (výchozí 500 ms)

Konzument fallback nevidí — vždy dostane výsledek. Log a Tracy bar panel (`RustDaemonBarPanel`) ukazují počet fallbacků per request pro diagnostiku.

## Související

- Architektonický kontext a širší pohled na migraci v `abel` repu: [`docs/business-logic/products-cache.md`](../../../abel/docs/business-logic/products-cache.md) (pokud existuje)
- Implementační plán refaktoru: [`abel/docs/implementation-plans/2026-04-23-products-cache-services-encapsulation.md`](../../../abel/docs/implementation-plans/2026-04-23-products-cache-services-encapsulation.md)
- Rust daemon: [`abel-products-daemon`](../../../abel-products-daemon/) repo
