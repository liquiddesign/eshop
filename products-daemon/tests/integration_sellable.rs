//! Ruční check `sellable_product_pks()` proti lokální produkční kopii DB v DDEV.
//!
//! Spuštění (DDEV musí běžet, MariaDB exponovaná na 127.0.0.1):
//! ```
//! ABEL_TEST_DSN=1 cargo test --test integration_sellable -- --ignored --nocapture
//! ```
//!
//! Ladicí env proměnné (volitelné — default trefí standardní DDEV setup):
//! - `ABEL_TEST_HOST` (default `127.0.0.1`)
//! - `ABEL_TEST_PORT` (default `32812`)
//! - `ABEL_TEST_USER` (default `db`)
//! - `ABEL_TEST_PASSWORD` (default `db`)
//! - `ABEL_TEST_DATABASE` (default `db`)
//! - `ABEL_TEST_CACHE_DATABASE` (default `cache`) — druhá DB, kde žijí `prices_*` tabulky
//!
//! Test nepíše assertiony na konkrétní čísla — vypisuje výsledky a očekává, že čtenář je
//! porovná s produkčním ground-truth (cca 66 278 ze `DISTINCT product` přes cache tabulky).

use std::{env, time::{Duration, Instant}};

use abel_products_daemon::{
	config::DatabaseConfig,
	db::Pool,
	query,
	snapshot::CatalogSnapshot,
};
use mysql_async::prelude::*;
use roaring::RoaringBitmap;

fn test_config() -> DatabaseConfig {
	DatabaseConfig {
		host: env::var("ABEL_TEST_HOST").unwrap_or_else(|_| "127.0.0.1".into()),
		port: env::var("ABEL_TEST_PORT")
			.ok()
			.and_then(|s| s.parse().ok())
			.unwrap_or(32812),
		user: env::var("ABEL_TEST_USER").unwrap_or_else(|_| "db".into()),
		password: env::var("ABEL_TEST_PASSWORD").unwrap_or_else(|_| "db".into()),
		database: env::var("ABEL_TEST_DATABASE").unwrap_or_else(|_| "db".into()),
		connect_timeout: Duration::from_secs(10),
		pool_max: 8,
	}
}

#[tokio::test(flavor = "multi_thread", worker_threads = 4)]
#[ignore = "needs running DDEV MariaDB; run with ABEL_TEST_DSN=1 cargo test --test integration_sellable -- --ignored --nocapture"]
async fn sellable_product_pks_against_ddev() {
	if env::var("ABEL_TEST_DSN").is_err() {
		eprintln!("skipping: ABEL_TEST_DSN not set");
		return;
	}

	let cfg = test_config();
	eprintln!("connecting to {}:{} db={} user={}", cfg.host, cfg.port, cfg.database, cfg.user);

	let pool = Pool::connect(&cfg).await.expect("connect to DDEV MariaDB");

	// --- 1. Snapshot build timing ---
	let t0 = Instant::now();
	let snap = CatalogSnapshot::load_from_pool(&pool)
		.await
		.expect("snapshot build");
	let build_elapsed = t0.elapsed();
	eprintln!(
		"snapshot built in {:?}: products={} prices={} vlists={} vitems={}",
		build_elapsed,
		snap.product_count(),
		snap.price_count(),
		snap.visibility_lists.len(),
		snap.visibility_items.len(),
	);

	// --- 2. sellable_product_pks() — nová implementace s visibility filterem ---
	let t1 = Instant::now();
	let pks = query::sellable_product_pks(&snap);
	let query_elapsed = t1.elapsed();
	eprintln!("sellable_product_pks: {} products in {:?}", pks.len(), query_elapsed);

	// --- 3. Porovnání: jen price filter (původní naivní Rust logika, bez visibility) ---
	let t2 = Instant::now();
	let mut price_only = RoaringBitmap::new();
	for price in &snap.prices {
		let pl_meta = &snap.pricelists[usize::from(price.pricelist)];
		if !pl_meta.is_active {
			continue;
		}
		if price.price <= 0.0 && price.price_vat <= 0.0 {
			continue;
		}
		price_only.insert(price.product);
	}
	let price_only_count = price_only.len();
	let price_only_elapsed = t2.elapsed();
	eprintln!(
		"price-only filter (old Rust logic): {} products in {:?}",
		price_only_count, price_only_elapsed
	);

	// --- 4. Kolik jsme vyřízli visibility filterem ---
	let removed = price_only_count.saturating_sub(pks.len() as u64);
	eprintln!(
		"visibility filter removed {} products ({:.1}%)",
		removed,
		if price_only_count > 0 {
			removed as f64 / price_only_count as f64 * 100.0
		} else {
			0.0
		}
	);

	// --- 5. Ground truth proti DB: DISTINCT product ze všech prices_*/cache_prices_* tabulek ---
	// Cache tabulky žijí v samostatné DB `cache` (viz general.local.neon → storm.connections.cache).
	let cache_db = env::var("ABEL_TEST_CACHE_DATABASE").unwrap_or_else(|_| "cache".into());
	let mut cache_cfg = cfg.clone();
	cache_cfg.database = cache_db.clone();
	eprintln!("connecting to cache db: {}", cache_db);

	match Pool::connect(&cache_cfg).await {
		Ok(cache_pool) => {
			let mut conn = cache_pool.conn().await.expect("conn to cache db");
			let tables: Vec<String> = conn
				.query(
					r"SELECT TABLE_NAME FROM information_schema.tables
					 WHERE TABLE_SCHEMA = DATABASE()
					   AND (table_name LIKE 'prices\_%' OR table_name LIKE 'cache\_prices\_%')",
				)
				.await
				.expect("list cache tables");
			eprintln!("found {} cache tables (prices_* + cache_prices_*)", tables.len());

			if !tables.is_empty() {
				let t3 = Instant::now();
				// Perzistentní `sellable_ground_truth` tabulka (naplněná ručně proceduarou) drží
				// `eshop_product.id`. Pokud existuje, použij ji — plnění přes 10k cache tabulek
				// trvá 7 min. Jinak ji napln inline.
				let has_gt_table: Option<u8> = conn
					.query_first(
						"SELECT 1 FROM information_schema.tables
						 WHERE table_schema=DATABASE() AND table_name='sellable_ground_truth'",
					)
					.await
					.unwrap_or(None);

				if has_gt_table.is_none() {
					eprintln!("sellable_ground_truth není — plním (trvá ~7 min pro 10k tabulek)…");
					conn.query_drop(
						"CREATE TABLE sellable_ground_truth (product BIGINT UNSIGNED NOT NULL, PRIMARY KEY(product)) ENGINE=InnoDB",
					)
					.await
					.expect("create ground truth table");
					for table in &tables {
						let sql = format!("INSERT IGNORE INTO sellable_ground_truth SELECT DISTINCT product FROM `{table}`");
						conn.query_drop(sql).await.ok();
					}
				}

				// Cache tabulky mají `product bigint(20)` = `eshop_product.id`, NE `uuid`.
				// Mapování na UUID přes cross-database JOIN do hlavní DB.
				let main_db = &cfg.database;
				let gt_sql = format!(
					"SELECT p.uuid FROM `{main_db}`.eshop_product p
					 INNER JOIN sellable_ground_truth t ON t.product = p.id"
				);
				let ground_truth_pks: Vec<String> = conn.query(gt_sql).await.unwrap_or_default();
				let gt_elapsed = t3.elapsed();
				eprintln!(
					"ground truth (DISTINCT product z cache tabulek): {} products in {:?}",
					ground_truth_pks.len(),
					gt_elapsed
				);

				// Symmetric diff pro rychlou diagnostiku:
				let pks_set: std::collections::HashSet<String> = pks.iter().cloned().collect();
				let gt_set: std::collections::HashSet<String> = ground_truth_pks.into_iter().collect();
				let in_daemon_not_in_cache = pks_set.difference(&gt_set).count();
				let in_cache_not_in_daemon = gt_set.difference(&pks_set).count();
				eprintln!(
					"diff proti cache: daemon-only={} cache-only={}",
					in_daemon_not_in_cache, in_cache_not_in_daemon
				);
			} else {
				eprintln!("no cache tables — ground-truth porovnání vynecháno");
			}
			drop(conn);
		}
		Err(e) => eprintln!("cache db připojení selhalo: {e} — ground-truth porovnání vynecháno"),
	}

	// Sanity: daemon musí vracet nějaký výsledek.
	assert!(!pks.is_empty(), "sellable_product_pks returned 0 products — something is off");
}
