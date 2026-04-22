//! Raw row types + SELECT queries.
//!
//! Each loader corresponds to a PHP `*Repository` in `/home/petr/eshop/src/DB/`:
//!
//! | Loader               | PHP counterpart                                                     |
//! |----------------------|---------------------------------------------------------------------|
//! | `load_products`      | `ProductRepository::getProducts` (core SELECT, denormalized fields) |
//! | `load_prices`        | `PriceRepository::getPrices` (flat read of `eshop_price`)           |
//! | `load_pricelists`    | `PricelistRepository` (metadata: priority, rate, flags)             |
//! | `load_visibility`    | `VisibilityListItemRepository` (per-product visibility flags)       |
//! | `load_categories`    | `CategoryRepository` (parent → child closure for descendants)       |
//! | `load_attr_values`   | `AttributeValueRepository`                                          |
//! | `load_drift`         | six `COUNT/MAX` queries — cheaper than any single payload load      |
//!
//! ## SQL authoring notes
//! - Source of truth for business rules is in PHP — these queries mirror **reads only**.
//! - `denormalizedAttributeValues` / `denormalizedCategories` are CSV columns populated by
//!   cron `rebuildProductDenormalization`. See `ProductRepository::getProducts`.
//! - MariaDB-specific: avoid `CAST(... AS JSON)` (see project rule `mariadb-sql.md`).

use std::hash::Hash;

use futures::TryStreamExt;
use mysql_async::{prelude::*, Row};

use crate::{db::Pool, error::SnapshotBuildError};

/// Fan-out raw container; all intern/bitmap logic happens in `SnapshotBuilder`.
#[derive(Debug, Default)]
pub struct RawCatalog {
	pub products: Vec<RawProduct>,
	pub prices: Vec<RawPrice>,
	pub pricelists: Vec<RawPricelist>,
	pub visibility_items: Vec<RawVisibilityItem>,
	pub categories: Vec<RawCategory>,
	/// `eshop_attributevalue` — authoritative value→attribute membership for the facet
	/// leave-one-out calculation. Without this mapping, every attribute value would get
	/// counted against a mask that still includes its own filter.
	pub attribute_values: Vec<RawAttributeValue>,
	/// Low-cardinality rows for `displayAmount` — UUID + `isSold` flag. `isSold` drives the
	/// `priorityAvailabilityPrice` ordering and `is_sold` filter (PHP `displayAmount.isSold`).
	/// Values: `0` = in stock, `1` = sold out, `2` = unknown (SQL `COALESCE(isSold, 2)`).
	pub display_amounts: Vec<RawDisplayAmount>,
	/// Low-cardinality UUIDs for `displayDelivery` — enumerated so the response side can
	/// reverse hash → UUID. No availability flag needed here.
	pub display_deliveries: Vec<String>,
	/// UUID enumeration of `eshop_ribbon` — just the set of known ribbon PKs. Products already
	/// carry their ribbon refs via `denormalizedRibbons` CSV, so this exists purely to pre-seed
	/// the intern pool with a stable ordering for tests / future features.
	pub ribbons: Vec<String>,
	/// UUID enumeration of `eshop_internalribbon` (same rationale as `ribbons`).
	pub internal_ribbons: Vec<String>,
	/// `eshop_related` rows — for `relatedSlave` filter. Only rows with `fk_slave IS NOT NULL`.
	pub related_rows: Vec<RawRelated>,
	/// `eshop_productprimarycategory` rows — for `related` filter per `main_category_type_pk`.
	pub product_primary_categories: Vec<RawProductPrimaryCategory>,
	pub drift: DriftSignals,
}

/// `eshop_related` row — used by `relatedSlave` filter. Master/slave/type are all UUIDs.
#[derive(Debug)]
pub struct RawRelated {
	pub master_uuid: String,
	pub slave_uuid: String,
	pub type_uuid: String,
}

/// `eshop_productprimarycategory` row — primary category per (product, category type).
#[derive(Debug)]
pub struct RawProductPrimaryCategory {
	pub product_uuid: String,
	pub category_uuid: String,
	pub category_type_uuid: String,
}

/// `eshop_displayamount` row — UUID plus `isSold` flag used by availability ordering & filter.
#[derive(Debug)]
pub struct RawDisplayAmount {
	pub uuid: String,
	/// `0` = in stock, `1` = sold out, `2` = unknown (NULL coalesced). Matches PHP ordering
	/// expressions and the `displayAmount.isSold` collection-filter column.
	pub is_sold: u8,
}

#[derive(Debug)]
pub struct RawProduct {
	pub uuid: String,
	/// Auto-increment integer PK (`eshop_product.id`). Serialized into
	/// `GetProductsResponse::product_pks` as stringified int so PHP `ProductList.php:255`
	/// (`$source->where('this.id', $pagedProducts)`) gets int IDs, not UUIDs. See PHP
	/// `LiveProductsProvider::buildOutput` — `(string) $product->id`.
	pub id: u64,
	/// Localized name (`name_cs` — Abel is Czech-first). Empty/None when the column is NULL.
	/// TODO(#M2-multilang): carry all mutations and let the request pick the language.
	pub name: Option<String>,
	pub producer_uuid: Option<String>,
	pub display_amount: Option<String>,
	pub display_delivery: Option<String>,
	pub discount_level_pct: u8,
	pub is_project: bool,
	/// CSV seznam IČ, které smějí objednat tento project produkt. `None` nebo empty = bez omezení
	/// (filter `project` pak závisí na customer kontextu). Surový string; split v build.rs.
	pub project_ic: Option<String>,
	/// `this.fk_masterProduct`. `None` znamená master produkt (bez parenta), `Some(uuid)` slave.
	/// Používá filter `masterProduct` (PHP `allowedCollectionFilterExpressions['masterProduct']`).
	pub master_product_uuid: Option<String>,
	/// CSV parsed from `denormalizedAttributeValues`.
	pub attribute_value_uuids: Vec<String>,
	/// CSV parsed from `denormalizedCategories`.
	pub category_uuids: Vec<String>,
	/// CSV UUID stringů (`eshop_ribbon`). Dříve `Vec<u32>` — to byl bug, denormalizedRibbons v DB
	/// drží UUID, ne číselná ID.
	pub ribbon_uuids: Vec<String>,
	/// CSV UUID stringů (`eshop_internalribbon`). Používá se pro `contract`/`notPublic` filtry.
	pub internal_ribbon_uuids: Vec<String>,
}

#[derive(Debug)]
pub struct RawAttributeValue {
	pub uuid: String,
	pub attribute_uuid: String,
}

#[derive(Debug)]
pub struct RawPrice {
	pub product_uuid: String,
	pub pricelist_uuid: String,
	pub price: f64,
	pub price_vat: f64,
	pub price_before: f64,
	pub price_vat_before: f64,
	pub hidden: bool,
}

#[derive(Debug)]
pub struct RawPricelist {
	pub uuid: String,
	pub priority: i32,
	pub rate: f32,
	pub allow_discount_level: bool,
	pub allow_surcharge: bool,
	pub is_active: bool,
}

#[derive(Debug)]
pub struct RawVisibilityItem {
	pub product_uuid: String,
	pub visibility_list_uuid: String,
	pub hidden: bool,
	pub hidden_in_menu: bool,
	pub recommended: bool,
	pub unavailable: bool,
	pub priority: i32,
}

#[derive(Debug)]
pub struct RawCategory {
	pub uuid: String,
	pub parent_uuid: Option<String>,
	pub path: String,
}

/// Cheap-query signals the refresher polls to detect whether the snapshot is stale.
/// Hashed via `AHasher` into a `u64` stored on the snapshot (`CatalogSnapshot::schema_version`).
#[derive(Debug, Default, Hash)]
pub struct DriftSignals {
	pub price_count: u64,
	pub price_max_created: u64,
	pub product_count: u64,
	pub product_max_touched: u64,
	pub visibility_item_count: u64,
	pub customer_pricelist_count: u64,
	pub customer_favourite_pricelist_count: u64,
	pub pricelist_max_id: u64,
	pub denorm_hash_hex: String,
}

pub async fn load_catalog_raw(pool: &Pool) -> Result<RawCatalog, SnapshotBuildError> {
	// Fan out. Each loader takes its own connection from the pool so we run in parallel.
	let (
		products,
		prices,
		pricelists,
		visibility_items,
		categories,
		attribute_values,
		display_amounts,
		display_deliveries,
		ribbons,
		internal_ribbons,
		related_rows,
		product_primary_categories,
		drift,
	) = tokio::try_join!(
		load_products(pool),
		load_prices(pool),
		load_pricelists(pool),
		load_visibility(pool),
		load_categories(pool),
		load_attribute_values(pool),
		load_display_amounts(pool),
		load_display_lookup(pool, "eshop_displaydelivery"),
		load_ribbons(pool),
		load_internal_ribbons(pool),
		load_related(pool),
		load_product_primary_categories(pool),
		load_drift(pool),
	)?;

	Ok(RawCatalog {
		products,
		prices,
		pricelists,
		visibility_items,
		categories,
		attribute_values,
		display_amounts,
		display_deliveries,
		ribbons,
		internal_ribbons,
		related_rows,
		product_primary_categories,
		drift,
	})
}

/// Load `eshop_related` rows with non-null slave — feeds the `relatedSlave` filter.
async fn load_related(pool: &Pool) -> Result<Vec<RawRelated>, SnapshotBuildError> {
	let mut conn = pool.conn().await?;
	let sql = r#"
		SELECT
			fk_master AS master_uuid,
			fk_slave  AS slave_uuid,
			fk_type   AS type_uuid
		FROM eshop_related
		WHERE fk_slave IS NOT NULL AND fk_master IS NOT NULL AND fk_type IS NOT NULL
	"#;
	let stream = conn.query_stream::<Row, _>(sql).await?;
	stream
		.map_err(SnapshotBuildError::from)
		.and_then(|mut row| async move {
			Ok(RawRelated {
				master_uuid: row.take("master_uuid").ok_or(SnapshotBuildError::Decode {
					field: "related.master_uuid",
					reason: "missing".into(),
				})?,
				slave_uuid: row.take("slave_uuid").ok_or(SnapshotBuildError::Decode {
					field: "related.slave_uuid",
					reason: "missing".into(),
				})?,
				type_uuid: row.take("type_uuid").ok_or(SnapshotBuildError::Decode {
					field: "related.type_uuid",
					reason: "missing".into(),
				})?,
			})
		})
		.try_collect::<Vec<_>>()
		.await
}

/// Load `eshop_productprimarycategory` rows — primary category per (product, category_type).
/// Used by the `related` filter which matches on `productPrimaryCategory.fk_category = $category`
/// scoped by the current shopper's `main_category_type`.
async fn load_product_primary_categories(pool: &Pool) -> Result<Vec<RawProductPrimaryCategory>, SnapshotBuildError> {
	let mut conn = pool.conn().await?;
	let sql = r#"
		SELECT
			fk_product      AS product_uuid,
			fk_category     AS category_uuid,
			fk_categoryType AS category_type_uuid
		FROM eshop_productprimarycategory
		WHERE fk_product IS NOT NULL AND fk_category IS NOT NULL AND fk_categoryType IS NOT NULL
	"#;
	let stream = conn.query_stream::<Row, _>(sql).await?;
	stream
		.map_err(SnapshotBuildError::from)
		.and_then(|mut row| async move {
			Ok(RawProductPrimaryCategory {
				product_uuid: row.take("product_uuid").ok_or(SnapshotBuildError::Decode {
					field: "product_primary_category.product_uuid",
					reason: "missing".into(),
				})?,
				category_uuid: row.take("category_uuid").ok_or(SnapshotBuildError::Decode {
					field: "product_primary_category.category_uuid",
					reason: "missing".into(),
				})?,
				category_type_uuid: row.take("category_type_uuid").ok_or(SnapshotBuildError::Decode {
					field: "product_primary_category.category_type_uuid",
					reason: "missing".into(),
				})?,
			})
		})
		.try_collect::<Vec<_>>()
		.await
}

/// TODO(#M1-loaders): finalize the SELECT list after diffing `ProductRepository::getProducts`.
/// The PHP repo adds a JOIN with `displayAmount` and reads `denormalizedAttributeValues` (CSV).
async fn load_products(pool: &Pool) -> Result<Vec<RawProduct>, SnapshotBuildError> {
	let mut conn = pool.conn().await?;

	// SQL mirrors ProductRepository::getProducts (read-only denormalized columns).
	// Adjust as PHP schema evolves.
	let sql = r#"
		SELECT
			this.uuid                              AS uuid,
			this.id                                AS id,
			this.name_cs                           AS name,
			this.fk_producer                       AS producer_uuid,
			this.fk_displayAmount                  AS display_amount,
			this.fk_displayDelivery                AS display_delivery,
			COALESCE(this.discountLevelPct, 0)     AS discount_level_pct,
			COALESCE(this.isProjectProduct, 0)     AS is_project,
			this.projectIc                         AS project_ic,
			this.fk_masterProduct                  AS master_product_uuid,
			COALESCE(this.denormalizedAttributeValues, '')  AS attribute_values_csv,
			COALESCE(this.denormalizedCategories, '')       AS categories_csv,
			COALESCE(this.denormalizedRibbons, '')          AS ribbons_csv,
			COALESCE(this.denormalizedInternalRibbons, '')  AS internal_ribbons_csv
		FROM eshop_product AS this
		WHERE this.deletedTs IS NULL
	"#;

	let stream = conn.query_stream::<Row, _>(sql).await?;
	stream
		.map_err(SnapshotBuildError::from)
		.and_then(|mut row| async move {
			let uuid: String = row.take("uuid").ok_or(SnapshotBuildError::Decode {
				field: "product.uuid",
				reason: "missing".into(),
			})?;
			// `eshop_product.id` is `INT(10) UNSIGNED AUTO_INCREMENT UNIQUE` — the column PHP
			// sees in `ProductList.php:255` (`$source->where('this.id', $pagedProducts)`). If
			// the column is missing or 0 we still include the product (won't be worse than UUID
			// fallback) but emit a warning — in healthy DB the value is always non-zero.
			let id: u64 = row.take("id").unwrap_or(0);
			if id == 0 {
				tracing::warn!(uuid = %uuid, "eshop_product.id is 0 — response product_pks will include '0'");
			}
			// Nullable columns go through `Option<Option<String>>` so mysql_async's `FromValue`
			// impl for `String` doesn't panic on NULL — `.flatten()` collapses both "absent" and
			// NULL to `None`.
			let name: Option<String> = row.take::<Option<String>, _>("name").flatten();
			let producer_uuid: Option<String> = row.take::<Option<String>, _>("producer_uuid").flatten();
			let display_amount: Option<String> = row.take::<Option<String>, _>("display_amount").flatten();
			let display_delivery: Option<String> = row.take::<Option<String>, _>("display_delivery").flatten();
			let project_ic: Option<String> = row.take::<Option<String>, _>("project_ic").flatten();
			let master_product_uuid: Option<String> = row.take::<Option<String>, _>("master_product_uuid").flatten();
			let discount_level_pct: u8 = row.take("discount_level_pct").unwrap_or(0);
			let is_project: u8 = row.take("is_project").unwrap_or(0);
			let attribute_values_csv: String = row.take("attribute_values_csv").unwrap_or_default();
			let categories_csv: String = row.take("categories_csv").unwrap_or_default();
			let ribbons_csv: String = row.take("ribbons_csv").unwrap_or_default();
			let internal_ribbons_csv: String = row.take("internal_ribbons_csv").unwrap_or_default();
			Ok(RawProduct {
				uuid,
				id,
				name,
				producer_uuid,
				display_amount,
				display_delivery,
				discount_level_pct,
				is_project: is_project != 0,
				project_ic,
				master_product_uuid,
				attribute_value_uuids: split_csv(&attribute_values_csv),
				category_uuids: split_csv(&categories_csv),
				ribbon_uuids: split_csv(&ribbons_csv),
				internal_ribbon_uuids: split_csv(&internal_ribbons_csv),
			})
		})
		.try_collect::<Vec<_>>()
		.await
}

async fn load_attribute_values(pool: &Pool) -> Result<Vec<RawAttributeValue>, SnapshotBuildError> {
	let mut conn = pool.conn().await?;
	let sql = r#"
		SELECT
			uuid,
			fk_attribute AS attribute_uuid
		FROM eshop_attributevalue
		WHERE hidden = 0
	"#;
	let stream = conn.query_stream::<Row, _>(sql).await?;
	stream
		.map_err(SnapshotBuildError::from)
		.and_then(|mut row| async move {
			Ok(RawAttributeValue {
				uuid: row.take("uuid").ok_or(SnapshotBuildError::Decode {
					field: "attribute_value.uuid",
					reason: "missing".into(),
				})?,
				attribute_uuid: row.take("attribute_uuid").ok_or(SnapshotBuildError::Decode {
					field: "attribute_value.fk_attribute",
					reason: "missing".into(),
				})?,
			})
		})
		.try_collect::<Vec<_>>()
		.await
}

/// Enumerate UUIDs from a `displayDelivery` table so the response side can reverse the hash
/// used in `build::display_amount_idx` back to the PHP UUID key.
///
/// `displayAmount` has its own richer loader (`load_display_amounts`) because it carries the
/// `isSold` flag required for availability ordering / filters.
async fn load_display_lookup(pool: &Pool, table: &'static str) -> Result<Vec<String>, SnapshotBuildError> {
	let mut conn = pool.conn().await?;
	// `table` is a compile-time string literal, not user input — safe to interpolate.
	let sql = format!("SELECT uuid FROM {table}");
	let stream = conn.query_stream::<Row, _>(sql.as_str()).await?;
	stream
		.map_err(SnapshotBuildError::from)
		.and_then(|mut row| async move {
			row.take::<String, _>("uuid").ok_or(SnapshotBuildError::Decode {
				field: "display_lookup.uuid",
				reason: "missing".into(),
			})
		})
		.try_collect::<Vec<_>>()
		.await
}

/// Enumerate `eshop_displayamount` rows (uuid + `isSold`). `COALESCE(isSold, 2)` mirrors PHP's
/// `displayAmount_isSold ?? 2` default — unknown availability sorts between in-stock (0) and
/// sold-out (1 → weight 2) in `priorityAvailabilityPrice`.
async fn load_display_amounts(pool: &Pool) -> Result<Vec<RawDisplayAmount>, SnapshotBuildError> {
	let mut conn = pool.conn().await?;
	let sql = r#"
		SELECT
			uuid,
			COALESCE(isSold, 2) AS is_sold
		FROM eshop_displayamount
	"#;
	let stream = conn.query_stream::<Row, _>(sql).await?;
	stream
		.map_err(SnapshotBuildError::from)
		.and_then(|mut row| async move {
			let uuid: String = row.take("uuid").ok_or(SnapshotBuildError::Decode {
				field: "display_amount.uuid",
				reason: "missing".into(),
			})?;
			let is_sold: u8 = row.take("is_sold").unwrap_or(2);
			Ok(RawDisplayAmount { uuid, is_sold })
		})
		.try_collect::<Vec<_>>()
		.await
}

/// Enumerate ribbon UUIDs from `eshop_ribbon`. Products reference ribbons via
/// `denormalizedRibbons` CSV so this seed list is primarily for stable pool ordering and the
/// `ribbon_pool` enumeration exposed to tests / future admin tooling.
async fn load_ribbons(pool: &Pool) -> Result<Vec<String>, SnapshotBuildError> {
	load_ribbon_uuids(pool, "eshop_ribbon", "ribbon.uuid").await
}

/// Same as [`load_ribbons`], but for `eshop_internalribbon` (contract / notPublic ribbons).
async fn load_internal_ribbons(pool: &Pool) -> Result<Vec<String>, SnapshotBuildError> {
	load_ribbon_uuids(pool, "eshop_internalribbon", "internal_ribbon.uuid").await
}

async fn load_ribbon_uuids(
	pool: &Pool,
	table: &'static str,
	field_label: &'static str,
) -> Result<Vec<String>, SnapshotBuildError> {
	let mut conn = pool.conn().await?;
	// `table` is a compile-time string literal.
	let sql = format!("SELECT uuid FROM {table}");
	let stream = conn.query_stream::<Row, _>(sql.as_str()).await?;
	stream
		.map_err(SnapshotBuildError::from)
		.and_then(move |mut row| async move {
			row.take::<String, _>("uuid").ok_or(SnapshotBuildError::Decode {
				field: field_label,
				reason: "missing".into(),
			})
		})
		.try_collect::<Vec<_>>()
		.await
}

async fn load_prices(pool: &Pool) -> Result<Vec<RawPrice>, SnapshotBuildError> {
	let mut conn = pool.conn().await?;
	let sql = r#"
		SELECT
			fk_product                AS product_uuid,
			fk_pricelist              AS pricelist_uuid,
			COALESCE(price, 0)        AS price,
			-- priceVat may be NULL; PHP `LiveProductsProvider::computeEffectivePrices` collapses
			-- `priceVat === null` to `price` (tax-inclusive defaults to tax-exclusive when unset).
			COALESCE(priceVat, price) AS price_vat,
			COALESCE(priceBefore, 0)  AS price_before,
			COALESCE(priceVatBefore, 0) AS price_vat_before,
			COALESCE(hidden, 0)       AS hidden
		FROM eshop_price
	"#;
	let stream = conn.query_stream::<Row, _>(sql).await?;
	stream
		.map_err(SnapshotBuildError::from)
		.and_then(|mut row| async move {
			let hidden: u8 = row.take("hidden").unwrap_or(0);
			Ok(RawPrice {
				product_uuid: row.take("product_uuid").ok_or(SnapshotBuildError::Decode {
					field: "price.product_uuid",
					reason: "missing".into(),
				})?,
				pricelist_uuid: row.take("pricelist_uuid").ok_or(SnapshotBuildError::Decode {
					field: "price.pricelist_uuid",
					reason: "missing".into(),
				})?,
				price: row.take::<f64, _>("price").unwrap_or(0.0),
				price_vat: row.take::<f64, _>("price_vat").unwrap_or(0.0),
				price_before: row.take::<f64, _>("price_before").unwrap_or(0.0),
				price_vat_before: row.take::<f64, _>("price_vat_before").unwrap_or(0.0),
				hidden: hidden != 0,
			})
		})
		.try_collect::<Vec<_>>()
		.await
}

async fn load_pricelists(pool: &Pool) -> Result<Vec<RawPricelist>, SnapshotBuildError> {
	let mut conn = pool.conn().await?;
	// `eshop_pricelist` has no `rate` column — the convert ratio lives on `eshop_currency`
	// (see `PriceModifiers::currency_rate` populated by PHP proxy from ShopperUser). We keep
	// `PricelistMeta::rate` at 1.0 as a placeholder in case future schema gains a pricelist-level
	// override; for now it is unused in the hot path.
	let sql = r#"
		SELECT
			uuid,
			COALESCE(priority, 0)                 AS priority,
			COALESCE(allowDiscountLevel, 0)       AS allow_discount_level,
			COALESCE(allowSurchargeLevel, 0)      AS allow_surcharge_level,
			COALESCE(isActive, 1)                 AS is_active
		FROM eshop_pricelist
	"#;
	let stream = conn.query_stream::<Row, _>(sql).await?;
	stream
		.map_err(SnapshotBuildError::from)
		.and_then(|mut row| async move {
			let allow_discount_level: u8 = row.take("allow_discount_level").unwrap_or(0);
			let allow_surcharge: u8 = row.take("allow_surcharge_level").unwrap_or(0);
			let is_active: u8 = row.take("is_active").unwrap_or(1);
			Ok(RawPricelist {
				uuid: row.take("uuid").ok_or(SnapshotBuildError::Decode {
					field: "pricelist.uuid",
					reason: "missing".into(),
				})?,
				priority: row.take("priority").unwrap_or(0),
				rate: 1.0,
				allow_discount_level: allow_discount_level != 0,
				allow_surcharge: allow_surcharge != 0,
				is_active: is_active != 0,
			})
		})
		.try_collect::<Vec<_>>()
		.await
}

async fn load_visibility(pool: &Pool) -> Result<Vec<RawVisibilityItem>, SnapshotBuildError> {
	let mut conn = pool.conn().await?;
	let sql = r#"
		SELECT
			fk_product          AS product_uuid,
			fk_visibilityList   AS visibility_list_uuid,
			COALESCE(hidden, 0) AS hidden,
			COALESCE(hiddenInMenu, 0) AS hidden_in_menu,
			COALESCE(recommended, 0)  AS recommended,
			COALESCE(unavailable, 0)  AS unavailable,
			COALESCE(priority, 0) AS priority
		FROM eshop_visibilitylistitem
	"#;
	let stream = conn.query_stream::<Row, _>(sql).await?;
	stream
		.map_err(SnapshotBuildError::from)
		.and_then(|mut row| async move {
			let hidden: u8 = row.take("hidden").unwrap_or(0);
			let hidden_in_menu: u8 = row.take("hidden_in_menu").unwrap_or(0);
			let recommended: u8 = row.take("recommended").unwrap_or(0);
			let unavailable: u8 = row.take("unavailable").unwrap_or(0);
			Ok(RawVisibilityItem {
				product_uuid: row.take("product_uuid").ok_or(SnapshotBuildError::Decode {
					field: "visibility.product_uuid",
					reason: "missing".into(),
				})?,
				visibility_list_uuid: row.take("visibility_list_uuid").ok_or(SnapshotBuildError::Decode {
					field: "visibility.visibility_list_uuid",
					reason: "missing".into(),
				})?,
				hidden: hidden != 0,
				hidden_in_menu: hidden_in_menu != 0,
				recommended: recommended != 0,
				unavailable: unavailable != 0,
				priority: row.take("priority").unwrap_or(0),
			})
		})
		.try_collect::<Vec<_>>()
		.await
}

async fn load_categories(pool: &Pool) -> Result<Vec<RawCategory>, SnapshotBuildError> {
	let mut conn = pool.conn().await?;
	let sql = r#"
		SELECT
			uuid,
			fk_ancestor AS parent_uuid,
			path
		FROM eshop_category
	"#;
	let stream = conn.query_stream::<Row, _>(sql).await?;
	stream
		.map_err(SnapshotBuildError::from)
		.and_then(|mut row| async move {
			Ok(RawCategory {
				uuid: row.take("uuid").ok_or(SnapshotBuildError::Decode {
					field: "category.uuid",
					reason: "missing".into(),
				})?,
				parent_uuid: row.take::<Option<String>, _>("parent_uuid").flatten(),
				path: row.take("path").unwrap_or_default(),
			})
		})
		.try_collect::<Vec<_>>()
		.await
}

pub async fn load_drift(pool: &Pool) -> Result<DriftSignals, SnapshotBuildError> {
	let mut conn = pool.conn().await?;

	// Multi-query via a single round trip where possible. mysql_async doesn't batch out of the
	// box, so we issue them sequentially; each is O(index scan) and returns in milliseconds.
	let price_count: u64 = conn
		.query_first::<u64, _>("SELECT COUNT(*) FROM eshop_price")
		.await?
		.unwrap_or(0);
	let price_max_created: u64 = conn
		.query_first::<u64, _>("SELECT COALESCE(MAX(UNIX_TIMESTAMP(createdTs)), 0) FROM eshop_price")
		.await?
		.unwrap_or(0);
	let product_count: u64 = conn
		.query_first::<u64, _>("SELECT COUNT(*) FROM eshop_product WHERE deletedTs IS NULL")
		.await?
		.unwrap_or(0);
	let product_max_touched: u64 = conn
		.query_first::<u64, _>(
			"SELECT COALESCE(MAX(UNIX_TIMESTAMP(lastInStockTs)), 0) FROM eshop_product WHERE deletedTs IS NULL",
		)
		.await?
		.unwrap_or(0);
	let visibility_item_count: u64 = conn
		.query_first::<u64, _>("SELECT COUNT(*) FROM eshop_visibilitylistitem")
		.await?
		.unwrap_or(0);
	let customer_pricelist_count: u64 = conn
		.query_first::<u64, _>("SELECT COUNT(*) FROM eshop_customer_nxn_eshop_pricelist")
		.await?
		.unwrap_or(0);
	let customer_favourite_pricelist_count: u64 = conn
		.query_first::<u64, _>("SELECT COUNT(*) FROM eshop_customer_nxn_eshop_pricelist_favourite")
		.await?
		.unwrap_or(0);
	let pricelist_max_id: u64 = conn
		.query_first::<u64, _>("SELECT COALESCE(MAX(id), 0) FROM eshop_pricelist")
		.await?
		.unwrap_or(0);
	let denorm_hash_hex: String = conn
		.query_first::<String, _>(
			r#"
			SELECT MD5(CONCAT(
				COUNT(*), '|',
				COALESCE(SUM(CHAR_LENGTH(denormalizedAttributeValues)), 0), '|',
				COALESCE(SUM(CHAR_LENGTH(denormalizedCategories)), 0)
			))
			FROM eshop_product WHERE deletedTs IS NULL
			"#,
		)
		.await?
		.unwrap_or_else(|| "0".to_string());

	Ok(DriftSignals {
		price_count,
		price_max_created,
		product_count,
		product_max_touched,
		visibility_item_count,
		customer_pricelist_count,
		customer_favourite_pricelist_count,
		pricelist_max_id,
		denorm_hash_hex,
	})
}

fn split_csv(s: &str) -> Vec<String> {
	if s.is_empty() {
		return Vec::new();
	}
	s.split(',').filter(|t| !t.is_empty()).map(ToOwned::to_owned).collect()
}
