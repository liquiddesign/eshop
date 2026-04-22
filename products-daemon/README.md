# abel-products-daemon

Long-running Rust daemon that holds the Abel eshop catalog (products, prices, visibility, attribute/category/producer inverted bitmaps) in RAM and answers `getProductsFromCacheTable`-shaped queries over a Unix domain socket.

Part of the `liquiddesign/eshop` vendor package (lives next to the existing Go `bin/cache-warmup`).

## Why
`Eshop\Services\ProductsCache\LiveProductsProvider` spends per-request time deserialising a baseline cache, iterating 47k products × N pricelists in PHP, and MD5-hashing filter combinations into cache keys that explode combinatorially. The daemon keeps the *inputs* (products, prices, visibility, attributes) in RAM, then merges them at request time using Roaring bitmap inverted indexes. Net effect: p95 < 50 ms on hot queries, constant ~150–200 MB RSS independent of customer-pricelist cardinality.

## Build (dev)

```bash
# 1. Install Rust (host or DDEV container — target is always x86_64-unknown-linux-gnu).
#    rust-toolchain.toml uses `channel = "stable"`, so `cargo` auto-fetches current stable on first run.
curl --proto '=https' --tlsv1.2 -sSf https://sh.rustup.rs | sh
source "$HOME/.cargo/env"

# 2. From /home/petr/eshop/products-daemon:
cargo fmt --check
cargo check --all-targets
cargo clippy --all-targets --all-features --locked -- -D warnings   # M2 gate — clean
cargo test
cargo build --release

# Binary lands at target/release/abel-products-daemon.
# For the deploy path, also build the static musl variant — it ships inside the vendor package
# alongside the Go binaries:
rustup target add x86_64-unknown-linux-musl
cargo build --release --target x86_64-unknown-linux-musl
cp target/x86_64-unknown-linux-musl/release/abel-products-daemon ../bin/products-daemon-linux-x86_64
chmod +x ../bin/products-daemon-linux-x86_64
```

## Configure

Copy `.env.example` → `.env` next to the binary. Fill DB credentials from `/home/petr/abel/config/general.local.neon` (section `database`):

```dotenv
DB_HOST=db
DB_PORT=3306
DB_USER=…
DB_PASSWORD=…
DB_NAME=…
SOCKET_PATH=/tmp/abel-products-daemon.sock
REFRESH_INTERVAL_SECS=600
LOG_LEVEL=info
```

The daemon reads `.env` via `dotenvy::dotenv()` on startup.

## Run manually (for debug)

```bash
./bin/products-daemon-linux-x86_64 --socket /tmp/abel-daemon.sock
# Ping:
printf '\x00\x00\x00\x13{"method":"ping"}' | nc -U /tmp/abel-daemon.sock | xxd | head
```

## Enable in Abel

`config/general.local.neon` (or any higher-priority overlay):

```yaml
eshop:
    productsProvider: rust
    rustDaemon:
        socketPath: /tmp/abel-products-daemon.sock
        binaryPath: %vendorDir%/liquiddesign/eshop/bin/products-daemon-linux-x86_64
        envPath: %vendorDir%/liquiddesign/eshop/products-daemon/.env
        timeoutMs: 500
        spawnOnDemand: true
```

Then `ddev composer clear-nette-cache`. The `RustProxyProductsProvider` takes over the `GeneralProductsCacheProvider` slot. On any daemon failure it transparently falls back to `LiveProductsProvider` — no user impact, logged as `Debugger::log(..., ILogger::WARNING)`.

Rollback: set `productsProvider: cache` or `live`.

## Status (2026-04-21)

This session produced:

- Full Rust scaffolding (workspace, module tree, types, error taxonomy, wire protocol, socket server, polling refresher).
- PHP proxy (`RustProxyProductsProvider`, `RustDaemonClient`) with complete fallback plumbing.
- DI integration (`ShopperDI`) — `productsProvider: rust` is live.
- **M2 pricing port**: `query::pricing::compute_price` mirrors `LiveProductsProvider::computeEffectivePrices` (PHP lines 1078–1199). 871-scenario golden parity test passes at f64 precision. Pipeline tests cover priority-first ASC selection, hidden skip, modifier stacking.
- `PriceFact` storage bumped to f64 (40 B per row; +25 MB for 1.6 M-row snapshot) — necessary for bit-equal parity with PHP `round()` output across corner cases.

What remains (per the plan's M2/M3/M4 milestones):

- **M2 facet leave-one-out** (`src/query/facets.rs`) — current skeleton uses the full surviving mask, which over-counts dimensions that the user has already filtered. Attribute-value facets need per-attribute pre-mask rebuild (TODO `#M2-facets`).
- **M2 name ordering** — needs `ProductRow::name: SmolStr` on the snapshot (not loaded today). Uses UUID tie-breaker meanwhile.
- **M3 E2E smoke** — `tests/scripts/e2e-parity-check.php` is ready but must be run manually against live DDEV DB (see "Parity validation" below). CI wiring deferred.
- **M4 production**: precompile musl binary, wire CI for the binary artifact, canary on `test.abel.cz` with the Tracy counters the plan specifies (`rustProxy.rpc_total_ms`, `rustProxy.fallback_count`).

## Parity validation

Two layers guard the Rust/PHP contract. **Both must stay green before flipping `productsProvider: rust` in production.**

### Layer 1 — golden numerical fixtures (automated)

871 scenarios covering the full matrix of discount × surcharge × currency rate × discount clamp × priceBefore semantics. Generated from PHP reference implementation (`tests/scripts/pricing_reference.php` — a byte-for-byte mirror of `LiveProductsProvider::computeEffectivePrices` inner loop) and consumed by a Rust test against `compute_price`.

```bash
# Regenerate fixtures when LiveProductsProvider::computeEffectivePrices changes:
php tests/scripts/generate-pricing-fixtures.php
# → wrote 871 scenarios to tests/fixtures/pricing/golden.json

# Run the parity test + pipeline suite:
cargo test --test integration_pricing
# → 6 passed (1 parity + 5 pipeline)
```

The reference file (`tests/scripts/pricing_reference.php`) carries a `SYNC ME` marker: when PHP changes the math, regenerate fixtures and re-run Rust before shipping.

### Layer 2 — E2E diff against real DB (manual smoke)

Requires a running daemon + `eshop.productsProvider: rust` in the active config.

```bash
# In one shell — run the daemon against the DDEV DB:
ddev exec /var/www/html/vendor/liquiddesign/eshop/bin/products-daemon-linux-x86_64 \
  --socket /tmp/abel-products-daemon.sock

# In another shell — run the diff script:
ddev exec php /var/www/html/vendor/liquiddesign/eshop/products-daemon/tests/scripts/e2e-parity-check.php
# → [guest-default] OK (N products)
# → [guest-priority-desc] OK (N products)
# → [guest-price-asc] OK (N products)
```

Script compares `productPKs`, `priceMin/Max`, `priceVatMin/Max`, and facet counts between `RustProxyProductsProvider` (daemon path) and `LiveProductsProvider` (ground truth). Exit code 1 on any divergence; add scenarios to the `$scenarios` array inside the script to cover your filter mix.

## Architecture recap

```
PHP worker
  └─ RustProxyProductsProvider
       ├─ RustDaemonClient — Unix socket, JSON frames
       │     └─ length-prefixed frame protocol v1
       └─ (on any failure) → LiveProductsProvider

abel-products-daemon (tokio multithreaded)
  ├─ UnixListener → per-conn spawn(handler::serve_connection)
  ├─ Refresher (every REFRESH_INTERVAL_SECS, drift-detected)
  └─ Arc<ArcSwap<CatalogSnapshot>>   ← hot-swapped on rebuild
        ├─ products: Vec<ProductRow>
        ├─ prices:   Vec<PriceFact>  (40 B Copy, sorted by product + priority ASC)
        ├─ prices_by_product: Vec<Range<u32>>  — O(1) per-product price slice
        ├─ *_bitmaps: BitmapIndex<RoaringBitmap>  — O(|candidates|) filter AND
        └─ intern pools for UUID → u32 indexes  — 8× memory win vs String
```

All reads are lock-free via `arc-swap` — writers (refresher) build a new snapshot offline, then `store()`. Existing readers keep working on the old `Arc`; it drops when the last reader releases it.

## Non-goals (explicit)

- Incremental snapshot updates. Full rebuild every `REFRESH_INTERVAL_SECS` or on drift. Simpler, always correct.
- Custom collection-order expressions and custom filter columns (`addCollectionOrderExpression`, `addFilterCollectionExpression`, `addFilterDynamicExpression`, `addAllowedCollectionFilterColumn`, `addAllowedDynamicFilterColumn`, `addAllowedCollectionOrderColumn`). The PHP proxy tracks the *names* of registered extensions and does **per-request detection**: only falls back when the current `$orderByName` or a key in `$filters` matches a registered name. Unrelated requests still reach the daemon. v2 could ship the column map over the wire for Type-A (structural) extensions so the daemon applies them natively.
    - **Known edge case:** if someone registers an extension name that collides with a default filter key (e.g. `addAllowedDynamicFilterColumn('producer', 'producer')`), every request referencing that key will fall back even though the daemon already handles the default semantics. Behavior-preserving, just wasteful. M2 disambiguation: keep a separate default-keys set and only mark truly new names as extension triggers.
- Cross-host state sharing. Each app server runs its own daemon and polls independently.

## Development tips

- `cargo flamegraph --bin abel-products-daemon -- --benchmark-mode --once` for hot-path profiling.
- `tracing-subscriber` is JSON-capable — pipe to `jq` when reproducing prod-style logs.
- PHP side logs fallbacks with `Debugger::log(..., ILogger::WARNING)` — tail `log/info.log` during rollout to watch the ratio.
