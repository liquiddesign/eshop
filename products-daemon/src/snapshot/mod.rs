//! Catalog snapshot — the in-memory source of truth consumed by the query engine.
//!
//! Produced by `build::SnapshotBuilder` from MariaDB rows, then held in
//! `Arc<ArcSwap<CatalogSnapshot>>` for lock-free reads (see ch. 9).

pub mod bitmaps;
pub mod build;
pub mod intern;

use std::{num::NonZeroUsize, ops::Range, sync::Arc, time::Instant};

use ahash::AHashMap;
use lru::LruCache;
use parking_lot::Mutex;
use roaring::RoaringBitmap;
use smallvec::SmallVec;

use crate::{db::Pool, error::SnapshotBuildError, snapshot::bitmaps::BitmapIndex, snapshot::intern::InternPool};

/// Dense index into `CatalogSnapshot::product_pool` (up to ~186k in prod, ~4.3B theoretical).
pub type ProductIdx = u32;

/// Dense index into `CatalogSnapshot::pricelist_pool` — 7541 in prod, `u16` fits comfortably.
pub type PricelistIdx = u16;

/// Dense index into `CatalogSnapshot::attribute_value_pool`.
pub type AttrValIdx = u32;

/// Dense index into `CatalogSnapshot::category_pool`.
pub type CategoryIdx = u32;

/// Dense index into `CatalogSnapshot::producer_pool`.
pub type ProducerIdx = u32;

/// Dense index into `CatalogSnapshot::visibility_list_pool`.
pub type VisibilityListIdx = u16;

/// Dense index into `CatalogSnapshot::ribbon_pool` (eshop_ribbon).
pub type RibbonIdx = u32;

/// Dense index into `CatalogSnapshot::internal_ribbon_pool` (eshop_internalribbon).
pub type InternalRibbonIdx = u32;

/// A single row of `eshop_price`, flattened to `Copy` for tight scans.
///
/// Layout sized to 40 B (8 B header + 4× f64 price fields). f64 (not f32) so parity tests
/// against the PHP reference hold at all values — `217.8_f32` upcast to f64 produces
/// 217.8000030517578, which breaks byte-equal compares. The cost is +16 B per row — ~25 MB
/// extra for a 1.6 M-row snapshot, well within the memory budget (plan targets 150 MB total).
#[repr(C)]
#[derive(Debug, Copy, Clone, PartialEq)]
pub struct PriceFact {
	pub product: ProductIdx,     // 4
	pub pricelist: PricelistIdx, // 2
	pub flags: PriceFactFlags,   // 1
	_pad: u8,                    // 1 — reserved for alignment; do not use
	pub price: f64,              // 8
	pub price_vat: f64,          // 8
	pub price_before: f64,       // 8
	pub price_vat_before: f64,   // 8
} // = 40 B

#[repr(transparent)]
#[derive(Debug, Copy, Clone, PartialEq, Eq)]
pub struct PriceFactFlags(pub u8);

impl PriceFactFlags {
	pub const HIDDEN: u8 = 1 << 0;

	#[must_use]
	pub const fn empty() -> Self {
		Self(0)
	}

	#[must_use]
	pub const fn is_hidden(self) -> bool {
		self.0 & Self::HIDDEN != 0
	}

	#[must_use]
	pub const fn with_hidden(mut self, hidden: bool) -> Self {
		if hidden {
			self.0 |= Self::HIDDEN;
		} else {
			self.0 &= !Self::HIDDEN;
		}
		self
	}
}

impl PriceFact {
	#[must_use]
	pub const fn new(
		product: ProductIdx,
		pricelist: PricelistIdx,
		flags: PriceFactFlags,
		price: f64,
		price_vat: f64,
		price_before: f64,
		price_vat_before: f64,
	) -> Self {
		Self {
			product,
			pricelist,
			flags,
			_pad: 0,
			price,
			price_vat,
			price_before,
			price_vat_before,
		}
	}
}

const _: () = assert!(
	core::mem::size_of::<PriceFact>() == 40,
	"PriceFact layout drifted — update the memory budget comment"
);

/// A single product row with denormalized attribute/ribbon references.
#[derive(Debug, Clone)]
pub struct ProductRow {
	pub uuid_idx: ProductIdx,
	/// Localized name used for the `name` order direction. `None` means the DB column was NULL
	/// (rare — products without a name — but possible during admin draft states).
	pub name: Option<smol_str::SmolStr>,
	pub producer: Option<ProducerIdx>,
	pub display_amount: Option<u32>,
	pub display_delivery: Option<u32>,
	pub discount_level_pct: u8,
	pub is_project: bool,
	/// `true` = master produkt (DB `fk_masterProduct IS NULL`), `false` = slave produkt.
	/// Drží denormalizovanou hodnotu pro filter `masterProduct`; bitmap `master_mask` na snapshotu
	/// slouží jako rychlá cesta pro bulk filter.
	pub is_master: bool,
	/// CSV IČ z `eshop_product.projectIc`. Používá filter `project` pro merchant/customer
	/// authorizaci project produktů. Stored already-split, žádné heap alloc pro prázdný.
	pub project_ics: SmallVec<[smol_str::SmolStr; 4]>,
	/// Attribute values the product has. Stored as a bitmap for fast intersection with
	/// attribute-value bitmaps; `SmallVec` avoids heap alloc for typical products (≤ 16).
	pub attr_values: SmallVec<[AttrValIdx; 16]>,
	/// Standard ribbons (eshop_ribbon). Index do `ribbon_pool`.
	pub ribbons: SmallVec<[RibbonIdx; 4]>,
	/// Internal ribbons (eshop_internalribbon). Pro `contract`/`notPublic` filtry.
	pub internal_ribbons: SmallVec<[InternalRibbonIdx; 4]>,
}

/// Static metadata per pricelist — replicated into the snapshot so the hot path doesn't JOIN.
#[derive(Debug, Copy, Clone)]
pub struct PricelistMeta {
	pub idx: PricelistIdx,
	pub priority: i32,
	pub rate: f32,
	pub allow_discount_level: bool,
	pub allow_surcharge: bool,
	pub is_active: bool,
	/// `true` když má ceník aspoň jednu vazbu v junction tabulkách (customer group / customer /
	/// customer favourite / merchant). `ProductsCacheDiffUpdateService` generuje cache jen pro
	/// ceníky z téhle množiny — orphan aktivní ceníky (admin-only, osiřelé) do cache nepadají.
	/// `query::sellable_product_pks` totéž respektuje, aby výstup odpovídal cache sémantice.
	pub has_customer_binding: bool,
}

impl PricelistMeta {
	/// Neutral fallback used when a price fact references a pricelist that isn't in the
	/// snapshot (should not happen — `SnapshotBuilder` rejects those — but keeps the hot
	/// path total without requiring callers to branch on `Option`).
	#[must_use]
	pub const fn neutral() -> Self {
		Self {
			idx: 0,
			priority: 0,
			rate: 1.0,
			allow_discount_level: false,
			allow_surcharge: false,
			is_active: false,
			has_customer_binding: false,
		}
	}
}

/// Static metadata per visibility list — replicated into the snapshot so the hot path doesn't JOIN.
/// Mirror `PricelistMeta`: `visibility_lists[i].idx == i`, stable by construction in `SnapshotBuilder`.
#[derive(Debug, Copy, Clone)]
pub struct VisibilityListMeta {
	pub idx: VisibilityListIdx,
	pub is_active: bool,
	/// Same sémantika jako `PricelistMeta::has_customer_binding`, jen pro visibility listy
	/// — vazba přes `eshop_customergroup_nxn_eshop_visibilitylist`,
	/// `eshop_customer_nxn_eshop_visibilitylist` nebo `eshop_merchant_nxn_eshop_visibilitylist`.
	pub has_customer_binding: bool,
}

impl VisibilityListMeta {
	/// Neutral fallback for a visibility list idx that never made it into the snapshot —
	/// treated as inactive so `sellable_product_pks` won't leak products attached to unknown lists.
	#[must_use]
	pub const fn neutral() -> Self {
		Self {
			idx: 0,
			is_active: false,
			has_customer_binding: false,
		}
	}
}

/// Visibility-list item bundle — per (product, visibility_list) row.
#[derive(Debug, Copy, Clone)]
pub struct VisibilityItem {
	pub product: ProductIdx,
	pub visibility_list: VisibilityListIdx,
	pub hidden: bool,
	pub hidden_in_menu: bool,
	pub recommended: bool,
	pub unavailable: bool,
	pub priority: i32,
}

/// Category tree node — parent/child for category expansion (`/elektro` → all descendants).
#[derive(Debug, Clone)]
pub struct CategoryNode {
	pub idx: CategoryIdx,
	pub parent: Option<CategoryIdx>,
	pub path: smol_str::SmolStr,
	/// Precomputed descendant set (inclusive) for fast category-filter expansion.
	pub descendants: RoaringBitmap,
	/// `eshop_category.showDescendantProducts` — ovládá ancestor-bump v
	/// `all_category_counts`: přímý člen descendant-kategorie se počítá i sem, pokud je flag true.
	pub show_descendant_products: bool,
	/// `eshop_category.showProductsInAncestors` — ovládá descendant-bump v
	/// `all_category_counts`: přímý člen této kategorie se počítá i do descendant-uzlu, pokud
	/// ten má flag true.
	pub show_products_in_ancestors: bool,
}

/// The full in-memory catalog, produced by `SnapshotBuilder::build()` and swapped in via `ArcSwap`.
#[derive(Debug)]
pub struct CatalogSnapshot {
	// --- intern pools (idx -> display string) ---
	pub product_pool: InternPool,
	pub pricelist_pool: InternPool,
	pub attribute_value_pool: InternPool,
	pub category_pool: InternPool,
	pub producer_pool: InternPool,
	pub visibility_list_pool: InternPool,

	// --- facts ---
	pub products: Vec<ProductRow>,
	pub prices: Vec<PriceFact>,
	/// For each `ProductIdx`, the range into `prices[]` containing its price facts.
	/// Prices within a range are sorted by `pricelist_priority` descending — priority-first scan.
	pub prices_by_product: Vec<Range<u32>>,

	/// Inverted indexes for bitmap AND filtering.
	pub attr_value_bitmaps: BitmapIndex,
	pub category_bitmaps: BitmapIndex,
	/// Přímá junction `eshop_product_nxn_eshop_category` — bitmapa produktů, které patří přímo
	/// do dané kategorie (bez ancestor denormalizace). Používá `all_category_counts` — mirror
	/// `ProductsCacheGetterService.php:734`, kde se iterace dělá nad GROUP_CONCATem přímých
	/// kategorií (ne nad denormalizedCategories).
	pub direct_category_bitmaps: BitmapIndex,
	pub producer_bitmaps: BitmapIndex,
	pub display_amount_bitmaps: BitmapIndex,
	pub display_delivery_bitmaps: BitmapIndex,
	/// Inverted index: `RibbonIdx` → set of `ProductIdx`. Used by `ribbon` / `notRibbon` dynamic
	/// filters. AND within dimension (intersect for each required ribbon).
	pub ribbon_bitmaps: BitmapIndex,
	/// Same as [`Self::ribbon_bitmaps`] but for `eshop_internalribbon`.
	pub internal_ribbon_bitmaps: BitmapIndex,

	/// Full set of active (non-deleted) products, as a bitmap — base mask before filters.
	pub all_products_mask: RoaringBitmap,
	/// Subset of `all_products_mask` — pouze master produkty (`fk_masterProduct IS NULL`).
	/// Slouží k O(log n) aplikaci filtru `masterProduct`: `mask &= master_mask` (want=true)
	/// nebo `mask -= &master_mask` (want=false). Sestavené v `SnapshotBuilder` z per-produktového
	/// `is_master` flagu.
	pub master_mask: RoaringBitmap,

	// --- metadata ---
	pub pricelists: Vec<PricelistMeta>,
	/// `pricelist_pk_to_idx[uuid] -> PricelistIdx`; used by handlers that receive PHP-style UUIDs.
	pub pricelist_pk_to_idx: AHashMap<smol_str::SmolStr, PricelistIdx>,
	pub visibility_items: Vec<VisibilityItem>,
	pub visibility_by_product: AHashMap<ProductIdx, SmallVec<[u32; 4]>>,
	/// Per-VL precomputed bitmap pro single-VL `base_mask` fast path. Klíč: `VisibilityListIdx`.
	/// Hodnota: produkty, jejichž "winner v rámci té VL" (= first item v
	/// `visibility_by_product[p]` v dané VL) má `hidden = 0`. Multi-VL request tuhle mapu
	/// neumí použít (priority-first napříč VL setem nezachová parity), proto zůstává
	/// fallback na lineární scan v `filter::base_mask`. Build-time O(visibility_items.len()).
	pub visibility_winner_bitmaps: AHashMap<VisibilityListIdx, RoaringBitmap>,
	/// Per-`VisibilityListIdx` metadata. Index-matched with `visibility_list_pool` — entry `i`
	/// describes the list whose UUID lives at `visibility_list_pool.get(i)`. Populated in
	/// `SnapshotBuilder::build` before visibility items so `VisibilityItem::visibility_list`
	/// idx always resolves to a real meta entry.
	pub visibility_lists: Vec<VisibilityListMeta>,
	pub categories: Vec<CategoryNode>,

	/// Per-direct-category precomputed fanout: `direct_idx → [direct_idx, flagged_descendants,
	/// flagged_ancestors]`. Hot path `all_category_counts` iteruje `direct_category_bitmaps`
	/// a pro každý direct_idx přičte `n` do všech cílů v bump setu — mirror per-product walk
	/// v `ProductsCacheGetterService.php:734` (descendants s `show_products_in_ancestors=true`,
	/// ancestors s `show_descendant_products=true`). Precomputed at snapshot build — O(C²) v
	/// nejhorším, typicky zanedbatelné při 2-3 k kategoriích.
	pub category_bump_sets: AHashMap<CategoryIdx, SmallVec<[CategoryIdx; 8]>>,

	/// Attribute intern pool — every `AttrValIdx` has a parent `AttributeIdx` via
	/// `attribute_of_value`. Required for per-attribute leave-one-out facet counting.
	pub attribute_pool: InternPool,
	/// `attribute_of_value[AttrValIdx] -> AttributeIdx` (stored as `u32` from the attribute pool).
	/// Built once by `SnapshotBuilder` from `eshop_attributevalue`. Values without a known parent
	/// (e.g. legacy orphans) are absent from the map.
	pub attribute_of_value: AHashMap<AttrValIdx, u32>,
	/// Intern pool pro `eshop_ribbon.uuid` — odkazy z `ProductRow.ribbons`.
	pub ribbon_pool: InternPool,
	/// Intern pool pro `eshop_internalribbon.uuid` — odkazy z `ProductRow.internal_ribbons` a z
	/// `GetProductsRequest::contract_ribbon_pk` / `not_public_ribbon_pk`.
	pub internal_ribbon_pool: InternPool,
	/// Reverse lookup for the `displayAmount` / `displayDelivery` dimensions so facet counts
	/// can serialize the original UUID instead of the hashed index. Populated from
	/// `load_display_amounts` / `load_display_lookup` at snapshot build.
	pub display_amount_uuid_by_idx: AHashMap<u32, smol_str::SmolStr>,
	pub display_delivery_uuid_by_idx: AHashMap<u32, smol_str::SmolStr>,
	/// `display_amount_idx(uuid)` → `isSold` (0 = in stock, 1 = sold out, 2 = unknown). Drives
	/// the `isSold` filter and `priorityAvailabilityPrice` / `availabilityAndPrice` ordering
	/// expressions. Missing entries default to `2` (unknown).
	pub display_amount_is_sold: AHashMap<u32, u8>,
	/// Denormalized `isSold` per product, keyed by `ProductIdx`. Built from
	/// `display_amount_is_sold` lookup on each product's `display_amount` hash. Products without
	/// a `display_amount` default to `2` (unknown) — matches PHP `$product->displayAmount_isSold ?? 2`.
	pub is_sold_by_product: Vec<u8>,
	/// Auto-increment integer PKs (`eshop_product.id`) keyed by `ProductIdx`. Same index space
	/// as `products`, same push order as `product_pool` interning — `product_ids[i]` is the
	/// `id` of the product whose UUID lives at `product_pool.get(i)`. Serialized into the
	/// response `product_pks` so PHP `ProductList.php:255` (`$source->where('this.id', ...)`)
	/// sees integer IDs, matching LiveProductsProvider fallback. See `product_pool` for the
	/// UUID mapping still needed by request-side lookups (`filters.uuids`, `sort_by_uuid_field`).
	pub product_ids: Vec<u64>,

	/// `eshop_productprimarycategory` lookup: `(category_type_pk, category_uuid)` → bitmap produktů,
	/// které mají tuhle kategorii jako primární pod daným category type. Drží SmolStr pool aby
	/// composite klíč neduplikoval stringy přes AHashMap.
	///
	/// Používá filter `related` — PHP `filterRelated` joinne `productPrimaryCategory` na aktuální
	/// `shopperUser->getMainCategoryType()` a matchuje `fk_category`. Rust odvodí totéž přes
	/// `primary_category_by_type_cat[(type_pk, cat_uuid)]`.
	pub primary_category_by_type_cat: AHashMap<(smol_str::SmolStr, smol_str::SmolStr), RoaringBitmap>,

	/// `eshop_related` lookup: `(type_uuid, master_uuid)` → bitmap slave produktů. Používá
	/// filter `relatedSlave` (`filterRelatedSlave`). Uložené v intern-rámci daemon snapshot.
	pub related_slaves_by_type_master: AHashMap<(smol_str::SmolStr, smol_str::SmolStr), RoaringBitmap>,

	/// Inverted index `4-char suffix` → list `CategoryIdx` jejichž `eshop_category.path` končí
	/// tímto suffixem. Používá `crossSellFilter`, který rozdělí vstupní path na 4-char chunky
	/// a unionuje jejich category bitmapy.
	pub categories_by_path_suffix: AHashMap<smol_str::SmolStr, SmallVec<[CategoryIdx; 4]>>,

	/// Version tuple hashed into a `u64` — refresher compares against this to detect DB drift.
	pub schema_version: u64,
	pub built_at: Instant,

	/// Per-snapshot LRU cache nad serialized response bytes (Phase 4 — největší cache hit win).
	/// Klíč: `[u8; 32]` blake3 hash request frame bytes. Hodnota: serialized response frame
	/// (bez délkového headeru — ten se připojí v `write_frame`). Cache umírá s drop-em snapshotu
	/// (ArcSwap drop semantics) — žádné stale entries po rebuild.
	///
	/// Bound: 256 entries × průměrných 100 KB = ~25 MB ceiling. Pro daemon s 187k produkty
	/// a stable frontend cestami je top-50 klíčů (kategorie × isB2B × VAT toggle) typicky pokrývá
	/// 70-90 % traffic — cache hit ratio na produkčním e-shopu.
	///
	/// `Mutex` (ne `RwLock`) protože LruCache `get()` mutuje recency tracker. `parking_lot::Mutex`
	/// je ~10× rychlejší než `std::sync::Mutex` pro krátké hold-time (lookup je sub-µs).
	pub response_cache: Mutex<LruCache<[u8; 32], Arc<Vec<u8>>>>,
}

impl CatalogSnapshot {
	/// Load a fresh snapshot from a MariaDB pool. Full rebuild, not incremental.
	/// Called once at startup and from `Refresher` when drift is detected.
	pub async fn load_from_pool(pool: &Pool) -> Result<Self, SnapshotBuildError> {
		build::SnapshotBuilder::new(pool).build().await
	}

	/// Construct a minimal snapshot used by `cargo bench` when `--benchmark-mode` is passed.
	/// Shapes are representative; data values are synthetic.
	#[must_use]
	pub fn fixtures_for_benchmarks() -> Self {
		build::fixture_snapshot(/* products */ 50_000, /* pricelists */ 256)
	}

	#[must_use]
	pub fn product_count(&self) -> usize {
		self.products.len()
	}

	#[must_use]
	pub fn price_count(&self) -> usize {
		self.prices.len()
	}

	/// Rough RSS estimate based on container sizes — used for startup logging, not SLOs.
	#[must_use]
	pub fn memory_estimate_mb(&self) -> u64 {
		let prices = self.prices.len() * std::mem::size_of::<PriceFact>();
		let rows = self.products.len() * std::mem::size_of::<ProductRow>();
		let vis = self.visibility_items.len() * std::mem::size_of::<VisibilityItem>();
		((prices + rows + vis) / (1024 * 1024)) as u64
	}

	/// Empty default — used before the initial snapshot is built.
	#[must_use]
	pub fn empty() -> Arc<Self> {
		Arc::new(Self {
			product_pool: InternPool::new("product", 0),
			pricelist_pool: InternPool::new("pricelist", 0),
			attribute_value_pool: InternPool::new("attribute_value", 0),
			category_pool: InternPool::new("category", 0),
			producer_pool: InternPool::new("producer", 0),
			visibility_list_pool: InternPool::new("visibility_list", 0),
			products: Vec::new(),
			prices: Vec::new(),
			prices_by_product: Vec::new(),
			attr_value_bitmaps: BitmapIndex::new(),
			category_bitmaps: BitmapIndex::new(),
			direct_category_bitmaps: BitmapIndex::new(),
			producer_bitmaps: BitmapIndex::new(),
			display_amount_bitmaps: BitmapIndex::new(),
			display_delivery_bitmaps: BitmapIndex::new(),
			ribbon_bitmaps: BitmapIndex::new(),
			internal_ribbon_bitmaps: BitmapIndex::new(),
			all_products_mask: RoaringBitmap::new(),
			master_mask: RoaringBitmap::new(),
			pricelists: Vec::new(),
			pricelist_pk_to_idx: AHashMap::new(),
			visibility_items: Vec::new(),
			visibility_by_product: AHashMap::new(),
			visibility_winner_bitmaps: AHashMap::new(),
			visibility_lists: Vec::new(),
			categories: Vec::new(),
			category_bump_sets: AHashMap::new(),
			attribute_pool: InternPool::new("attribute", 0),
			attribute_of_value: AHashMap::new(),
			ribbon_pool: InternPool::new("ribbon", 0),
			internal_ribbon_pool: InternPool::new("internal_ribbon", 0),
			display_amount_uuid_by_idx: AHashMap::new(),
			display_delivery_uuid_by_idx: AHashMap::new(),
			display_amount_is_sold: AHashMap::new(),
			is_sold_by_product: Vec::new(),
			product_ids: Vec::new(),
			primary_category_by_type_cat: AHashMap::new(),
			related_slaves_by_type_master: AHashMap::new(),
			categories_by_path_suffix: AHashMap::new(),
			schema_version: 0,
			built_at: Instant::now(),
			response_cache: Mutex::new(LruCache::new(RESPONSE_CACHE_CAPACITY)),
		})
	}
}

/// Capacity per snapshot response cache.
///
/// Plánovaných 256 × 100 KB = 25 MB ceiling, ale produkční data ukázala že velké kategorie
/// (22k produktů) vrací ~1 MB JSON response (full UUID list bez pagination — pagination
/// se dělá až v PHP `ProductList`). 128 entries × průměr ~200 KB ≈ 25 MB ceiling i při
/// mixu malých a velkých odpovědí.
pub const RESPONSE_CACHE_CAPACITY: NonZeroUsize = match NonZeroUsize::new(128) {
	Some(n) => n,
	None => panic!("RESPONSE_CACHE_CAPACITY must be > 0"),
};
