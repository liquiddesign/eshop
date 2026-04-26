//! `SnapshotBuilder` — orchestrates the parallel DB loads and assembles a `CatalogSnapshot`.
//!
//! Pipeline (all fetches run concurrently via `tokio::join!`):
//! ```text
//! ┌─ load_products ────┐
//! ┌─ load_prices ──────┤
//! ┌─ load_visibility ──┼─► assemble → intern pools + bitmaps → CatalogSnapshot
//! ┌─ load_pricelists ──┤
//! ┌─ load_categories ──┤
//! ┌─ load_attr_vals ───┘
//! ```
//!
//! Target timing: `< 15 s` for a full rebuild (the plan's M1 gate). Incremental updates
//! are explicitly out of scope for v1 (see README § Non-goals).

use std::{
	hash::{Hash, Hasher},
	time::Instant,
};

use ahash::{AHashMap, AHasher};
use roaring::RoaringBitmap;
use smallvec::SmallVec;
use smol_str::SmolStr;
use tracing::{info, instrument};

use crate::{
	db::{loaders::DriftSignals, Pool},
	error::SnapshotBuildError,
	snapshot::{
		bitmaps::BitmapIndex, intern::InternPool, AttrValIdx, CatalogSnapshot, CategoryIdx, CategoryNode,
		InternalRibbonIdx, PriceFact, PriceFactFlags, PricelistIdx, PricelistMeta, ProductIdx, ProductRow, RibbonIdx,
		VisibilityItem, VisibilityListIdx, VisibilityListMeta,
	},
};

pub struct SnapshotBuilder<'a> {
	pool: &'a Pool,
}

impl<'a> SnapshotBuilder<'a> {
	#[must_use]
	pub const fn new(pool: &'a Pool) -> Self {
		Self { pool }
	}

	#[instrument(skip(self), level = "info")]
	pub async fn build(self) -> Result<CatalogSnapshot, SnapshotBuildError> {
		let started = Instant::now();

		// NOTE: all of these are stubs in M1 — real SQL lands in the `db::loaders` module and
		// `tokio::try_join!` wires them together here. Shapes below are authoritative.
		let raw = self.pool.load_catalog_raw().await?;

		let mut product_pool = InternPool::new("product", raw.products.len());
		let mut pricelist_pool = InternPool::new("pricelist", raw.pricelists.len()).with_limit(u32::from(u16::MAX));
		let mut attribute_value_pool = InternPool::new("attribute_value", 2048);
		let mut attribute_pool = InternPool::new("attribute", 256);
		let mut category_pool = InternPool::new("category", 4096);
		let mut producer_pool = InternPool::new("producer", 1024);
		let mut visibility_list_pool = InternPool::new("visibility_list", 32).with_limit(u32::from(u16::MAX));
		let mut ribbon_pool = InternPool::new("ribbon", 64);
		let mut internal_ribbon_pool = InternPool::new("internal_ribbon", 64);

		// --- intern pricelists first so price rows can reference u16 indexes ---
		// Invariant: `pricelists[i].idx == i as PricelistIdx`. `query::pricing::apply_modifiers`
		// depends on it for O(1) pricelist-meta lookup (hot path). debug_assert catches any
		// regression in the push order without paying the check in release builds.
		let customer_bound_pricelist_set: ahash::AHashSet<&str> = raw
			.customer_bound_pricelists
			.iter()
			.map(String::as_str)
			.collect();
		let mut pricelists = Vec::with_capacity(raw.pricelists.len());
		for raw_pl in &raw.pricelists {
			let idx: PricelistIdx =
				pricelist_pool
					.intern(&raw_pl.uuid)?
					.try_into()
					.map_err(|_| SnapshotBuildError::InternOverflow {
						pool: "pricelist",
						limit: u16::MAX as usize,
					})?;
			debug_assert_eq!(
				usize::from(idx),
				pricelists.len(),
				"pricelist idx must match its position in pricelists[]"
			);
			pricelists.push(PricelistMeta {
				idx,
				priority: raw_pl.priority,
				rate: raw_pl.rate,
				allow_discount_level: raw_pl.allow_discount_level,
				allow_surcharge: raw_pl.allow_surcharge,
				is_active: raw_pl.is_active,
				has_customer_binding: customer_bound_pricelist_set.contains(raw_pl.uuid.as_str()),
			});
		}
		let pricelist_pk_to_idx: AHashMap<SmolStr, PricelistIdx> = pricelists
			.iter()
			.filter_map(|pl| pricelist_pool.get(u32::from(pl.idx)).map(|s| (SmolStr::new(s), pl.idx)))
			.collect();

		// --- pre-seed ribbon intern pools from authoritative tables so every known ribbon has a
		// stable idx, even if no product currently references it. Products may later add UUIDs
		// that didn't exist here (shouldn't happen — FK constraint — but we handle it defensively).
		for uuid in &raw.ribbons {
			let _ = ribbon_pool.intern(uuid)?;
		}
		for uuid in &raw.internal_ribbons {
			let _ = internal_ribbon_pool.intern(uuid)?;
		}

		// --- visibility lists (intern first, then items can lookup their list idx) ---
		// Mirror pricelist pattern: `visibility_lists[i].idx == i`. Interning the authoritative
		// `eshop_visibilitylist` table before `eshop_visibilitylistitem` guarantees every item's
		// VisibilityListIdx resolves to a real VisibilityListMeta entry.
		let customer_bound_vl_set: ahash::AHashSet<&str> = raw
			.customer_bound_visibility_lists
			.iter()
			.map(String::as_str)
			.collect();
		let mut visibility_lists: Vec<VisibilityListMeta> = Vec::with_capacity(raw.visibility_lists.len());
		for raw_vl in &raw.visibility_lists {
			let idx_u32 = visibility_list_pool.intern(&raw_vl.uuid)?;
			let idx: VisibilityListIdx =
				idx_u32
					.try_into()
					.map_err(|_| SnapshotBuildError::InternOverflow {
						pool: "visibility_list",
						limit: u16::MAX as usize,
					})?;
			debug_assert_eq!(
				usize::from(idx),
				visibility_lists.len(),
				"visibility_list idx must match its position in visibility_lists[]"
			);
			visibility_lists.push(VisibilityListMeta {
				idx,
				is_active: raw_vl.is_active,
				has_customer_binding: customer_bound_vl_set.contains(raw_vl.uuid.as_str()),
			});
		}

		// --- displayAmount isSold lookup — hashed the same way as display_amount_idx below. ---
		let mut display_amount_is_sold: AHashMap<u32, u8> = AHashMap::with_capacity(raw.display_amounts.len());
		for da in &raw.display_amounts {
			display_amount_is_sold.insert(display_amount_idx(&da.uuid), da.is_sold);
		}

		// --- products ---
		let mut products: Vec<ProductRow> = Vec::with_capacity(raw.products.len());
		let mut producer_bitmaps = BitmapIndex::new();
		let mut attr_value_bitmaps = BitmapIndex::new();
		let mut category_bitmaps = BitmapIndex::new();
		let mut display_amount_bitmaps = BitmapIndex::new();
		let mut display_delivery_bitmaps = BitmapIndex::new();
		let mut ribbon_bitmaps = BitmapIndex::new();
		let mut internal_ribbon_bitmaps = BitmapIndex::new();
		let mut all_products_mask = RoaringBitmap::new();
		let mut master_mask = RoaringBitmap::new();
		let mut is_sold_by_product: Vec<u8> = Vec::with_capacity(raw.products.len());
		// `product_ids[uuid_idx] == raw_p.id` — pushed in lockstep with `products.push(...)` and
		// `product_pool.intern(&raw_p.uuid)` so the three arrays share one index space.
		let mut product_ids: Vec<u64> = Vec::with_capacity(raw.products.len());

		for raw_p in raw.products {
			let uuid_idx = product_pool.intern(&raw_p.uuid)?;
			all_products_mask.insert(uuid_idx);
			let is_master = raw_p.master_product_uuid.is_none();
			if is_master {
				master_mask.insert(uuid_idx);
			}

			let producer = raw_p
				.producer_uuid
				.as_deref()
				.map(|u| producer_pool.intern(u))
				.transpose()?;
			if let Some(p) = producer {
				producer_bitmaps.insert(p, uuid_idx);
			}

			let mut attr_values = SmallVec::<[AttrValIdx; 16]>::new();
			for raw_attr in raw_p.attribute_value_uuids {
				let idx = attribute_value_pool.intern(&raw_attr)?;
				attr_values.push(idx);
				attr_value_bitmaps.insert(idx, uuid_idx);
			}

			for raw_cat in raw_p.category_uuids {
				let idx = category_pool.intern(&raw_cat)?;
				category_bitmaps.insert(idx, uuid_idx);
			}

			let display_amount = raw_p.display_amount.map(|u| {
				let idx = display_amount_idx(&u);
				display_amount_bitmaps.insert(idx, uuid_idx);
				idx
			});
			let display_delivery = raw_p.display_delivery.map(|u| {
				let idx = display_amount_idx(&u);
				display_delivery_bitmaps.insert(idx, uuid_idx);
				idx
			});

			// Intern ribbons (both kinds) from CSV strings. Previous impl used `Vec<u32>` which
			// silently dropped all ribbons because `denormalizedRibbons` in DB holds UUID
			// strings, not numeric IDs. `parse::<u32>()` always failed → empty vec.
			let mut ribbons = SmallVec::<[RibbonIdx; 4]>::new();
			for uuid in &raw_p.ribbon_uuids {
				let idx = ribbon_pool.intern(uuid)?;
				ribbons.push(idx);
				ribbon_bitmaps.insert(idx, uuid_idx);
			}
			let mut internal_ribbons = SmallVec::<[InternalRibbonIdx; 4]>::new();
			for uuid in &raw_p.internal_ribbon_uuids {
				let idx = internal_ribbon_pool.intern(uuid)?;
				internal_ribbons.push(idx);
				internal_ribbon_bitmaps.insert(idx, uuid_idx);
			}

			// Denormalized isSold for the `isSold` filter and `priorityAvailabilityPrice`
			// ordering. `displayAmount` missing ⇒ 2 (unknown), matching PHP
			// `$product->displayAmount_isSold ?? 2`.
			let is_sold = display_amount
				.and_then(|hash| display_amount_is_sold.get(&hash).copied())
				.unwrap_or(2);
			is_sold_by_product.push(is_sold);

			// `projectIc` is CSV (e.g. "12345678,87654321") — normalized by trim + filter-empty.
			let mut project_ics = SmallVec::<[SmolStr; 4]>::new();
			if let Some(raw_ic) = raw_p.project_ic.as_deref() {
				for token in raw_ic.split(',') {
					let trimmed = token.trim();
					if !trimmed.is_empty() {
						project_ics.push(SmolStr::new(trimmed));
					}
				}
			}

			products.push(ProductRow {
				uuid_idx,
				name: raw_p.name.as_deref().map(SmolStr::new),
				producer,
				display_amount,
				display_delivery,
				discount_level_pct: raw_p.discount_level_pct,
				is_project: raw_p.is_project,
				is_master,
				project_ics,
				attr_values,
				ribbons,
				internal_ribbons,
			});
			product_ids.push(raw_p.id);
		}

		// --- prices (sorted by product + priority ASC so priority-first scan is linear) ---
		// PHP convention: lower priority number = higher precedence. Matches `LiveProductsProvider::computeEffectivePrices` (PHPDoc at line 1076 of LiveProductsProvider.php).
		let mut prices: Vec<PriceFact> = Vec::with_capacity(raw.prices.len());
		let pricelist_priority: AHashMap<PricelistIdx, i32> =
			pricelists.iter().map(|pl| (pl.idx, pl.priority)).collect();

		for raw_price in raw.prices {
			// Orphan price rows (product/pricelist hard-deleted but price left behind) are
			// tolerated — PHP repo joins on product so these never show up there anyway.
			// Skip silently; a warn-level count would be noisy on large datasets.
			let Some(product) = product_pool.lookup(&raw_price.product_uuid) else {
				continue;
			};
			let Some(pricelist) = pricelist_pool.lookup(&raw_price.pricelist_uuid) else {
				continue;
			};
			let pricelist = PricelistIdx::try_from(pricelist).map_err(|_| SnapshotBuildError::InternOverflow {
				pool: "pricelist",
				limit: u16::MAX as usize,
			})?;
			let flags = PriceFactFlags::empty().with_hidden(raw_price.hidden);
			prices.push(PriceFact::new(
				product,
				pricelist,
				flags,
				raw_price.price,
				raw_price.price_vat,
				raw_price.price_before,
				raw_price.price_vat_before,
			));
		}

		// Sort by (product, priority_asc) for fast priority-first scan.
		// Missing pricelist sinks to end via i32::MAX so only real hits can win.
		prices.sort_unstable_by_key(|f| {
			let prio = pricelist_priority.get(&f.pricelist).copied().unwrap_or(i32::MAX);
			(f.product, prio)
		});

		// Build per-product range index over `prices`.
		let mut prices_by_product: Vec<std::ops::Range<u32>> = Vec::with_capacity(products.len());
		prices_by_product.resize(products.len(), 0..0);
		let mut i: u32 = 0;
		while (i as usize) < prices.len() {
			let product = prices[i as usize].product;
			let start = i;
			while (i as usize) < prices.len() && prices[i as usize].product == product {
				i += 1;
			}
			if (product as usize) < prices_by_product.len() {
				prices_by_product[product as usize] = start..i;
			}
		}

		// --- visibility ---
		let mut visibility_items = Vec::with_capacity(raw.visibility_items.len());
		let mut visibility_by_product: AHashMap<ProductIdx, SmallVec<[u32; 4]>> = AHashMap::new();
		for raw_v in raw.visibility_items {
			let Some(product) = product_pool.lookup(&raw_v.product_uuid) else {
				continue;
			};
			let vl_idx_u32 = visibility_list_pool.intern(&raw_v.visibility_list_uuid)?;
			let visibility_list =
				VisibilityListIdx::try_from(vl_idx_u32).map_err(|_| SnapshotBuildError::InternOverflow {
					pool: "visibility_list",
					limit: u16::MAX as usize,
				})?;
			let row_idx: u32 = visibility_items
				.len()
				.try_into()
				.map_err(|_| SnapshotBuildError::InternOverflow {
					pool: "visibility_items",
					limit: u32::MAX as usize,
				})?;
			visibility_items.push(VisibilityItem {
				product,
				visibility_list,
				hidden: raw_v.hidden,
				hidden_in_menu: raw_v.hidden_in_menu,
				recommended: raw_v.recommended,
				unavailable: raw_v.unavailable,
				priority: raw_v.priority,
			});
			visibility_by_product.entry(product).or_default().push(row_idx);
		}

		// --- per-VL "single-VL winner" bitmaps (Phase 3 fast path) ----------------------
		// Pro single-VL request (`visibility_list_pks.len() == 1`) je `base_mask` jen lookup
		// předbudovaného bitmapu místo lineárního scan-u nad 186k produkty. Bitmap obsahuje
		// produkty, jejichž **winner v rámci dané VL** (= first encountered item v
		// `visibility_by_product[p]` filtrovaný na tu VL) má `hidden = 0` — exact mirror
		// `filter::base_mask` algoritmu pro single-VL případ (priority/uuid tie-break je
		// no-op, když je v sadě jen jedna VL).
		//
		// Multi-VL request fallbackuje na původní scan — priority-first selection napříč
		// VL setem zůstává parity-safe.
		let mut visibility_winner_bitmaps: AHashMap<VisibilityListIdx, RoaringBitmap> = AHashMap::new();
		for (product_idx, row_idxs) in &visibility_by_product {
			let mut seen_vls: ahash::AHashSet<VisibilityListIdx> = ahash::AHashSet::with_capacity(row_idxs.len());
			for &row_idx in row_idxs {
				let Some(item) = visibility_items.get(row_idx as usize) else {
					continue;
				};
				if !seen_vls.insert(item.visibility_list) {
					continue;
				}
				if item.hidden {
					continue;
				}
				visibility_winner_bitmaps
					.entry(item.visibility_list)
					.or_default()
					.insert(*product_idx);
			}
		}

		// --- attribute values → attribute mapping (for per-attribute facet leave-one-out) ---
		let mut attribute_of_value: AHashMap<AttrValIdx, u32> = AHashMap::with_capacity(raw.attribute_values.len());
		for av in &raw.attribute_values {
			// `intern` returns the same idx if the value was already interned from a product's
			// denormalized CSV; otherwise it gets a fresh idx. Either way, the AttributeIdx is
			// the same across products that share an attribute.
			let value_idx = attribute_value_pool.intern(&av.uuid)?;
			let attribute_idx = attribute_pool.intern(&av.attribute_uuid)?;
			attribute_of_value.insert(value_idx, attribute_idx);
		}

		// --- display_* UUID reverse lookup ---
		// Matches the hash in `display_amount_idx` below — same function, so indexes line up
		// with what products were bitmap-inserted under.
		let mut display_amount_uuid_by_idx: AHashMap<u32, SmolStr> = AHashMap::with_capacity(raw.display_amounts.len());
		for da in &raw.display_amounts {
			display_amount_uuid_by_idx.insert(display_amount_idx(&da.uuid), SmolStr::new(&da.uuid));
		}
		let mut display_delivery_uuid_by_idx: AHashMap<u32, SmolStr> =
			AHashMap::with_capacity(raw.display_deliveries.len());
		for uuid in &raw.display_deliveries {
			display_delivery_uuid_by_idx.insert(display_amount_idx(uuid), SmolStr::new(uuid));
		}

		// --- categories + descendant sets ---
		let mut categories: Vec<CategoryNode> = raw
			.categories
			.iter()
			.map(|c| CategoryNode {
				idx: category_pool.lookup(&c.uuid).unwrap_or(u32::MAX),
				parent: c.parent_uuid.as_deref().and_then(|u| category_pool.lookup(u)),
				path: SmolStr::new(&c.path),
				descendants: RoaringBitmap::new(),
				show_descendant_products: c.show_descendant_products,
				show_products_in_ancestors: c.show_products_in_ancestors,
			})
			.collect();
		build_category_descendants(&mut categories, &category_bitmaps);

		// --- direct_category_bitmaps (eshop_product_nxn_eshop_category) ---
		// Nedenormalizovaná vazba produkt → kategorie. Na rozdíl od `category_bitmaps` (plněných
		// z `denormalizedCategories`) tady mapa obsahuje jen přímé členství. `all_category_counts`
		// iteruje tuhle mapu a per-direct-cat rozdělí n do `category_bump_sets[direct_idx]`.
		let mut direct_category_bitmaps = BitmapIndex::new();
		for row in &raw.product_categories {
			let Some(product_idx) = product_pool.lookup(&row.product_uuid) else {
				continue;
			};
			let Some(cat_idx) = category_pool.lookup(&row.category_uuid) else {
				continue;
			};
			direct_category_bitmaps.insert(cat_idx, product_idx);
		}
		direct_category_bitmaps.shrink_to_fit();

		// --- category_bump_sets ---
		// Pro každý direct_idx vrátíme [direct_idx]
		//   ∪ {descendants, kde desc.show_products_in_ancestors = true}
		//   ∪ {ancestors walked, kde anc.show_descendant_products = true}
		// Mirror `ProductsCacheGetterService::…` line 734 (walk per direct cat). Index po CategoryIdx
		// pro O(1) lookup u CategoryNode flagů.
		let categories_by_idx: AHashMap<CategoryIdx, &CategoryNode> = categories
			.iter()
			.filter(|c| c.idx != u32::MAX)
			.map(|c| (c.idx, c))
			.collect();
		let mut category_bump_sets: AHashMap<CategoryIdx, SmallVec<[CategoryIdx; 8]>> =
			AHashMap::with_capacity(categories.len());
		for direct_node in categories.iter().filter(|c| c.idx != u32::MAX) {
			let mut bumps: SmallVec<[CategoryIdx; 8]> = SmallVec::new();
			bumps.push(direct_node.idx);
			// Descendants s `show_products_in_ancestors = true`. `descendants` obsahuje inklusivně
			// sebe — filtrujeme self a neexistující CategoryIdx.
			for desc_idx in &direct_node.descendants {
				if desc_idx == direct_node.idx {
					continue;
				}
				let Some(desc) = categories_by_idx.get(&desc_idx) else { continue };
				if desc.show_products_in_ancestors {
					bumps.push(desc_idx);
				}
			}
			// Walk ancestors přes `parent`. Cache getter (ProductsCacheGetterService.php:754-762)
			// gating NEkontroluje `direct_node.show_products_in_ancestors` — jenom flag ancestra.
			let mut cursor = direct_node.parent;
			while let Some(anc_idx) = cursor {
				let Some(anc) = categories_by_idx.get(&anc_idx) else { break };
				if anc.show_descendant_products {
					bumps.push(anc_idx);
				}
				cursor = anc.parent;
			}
			category_bump_sets.insert(direct_node.idx, bumps);
		}

		// --- primary_category_by_type_cat (for `related` filter) ---
		// Klíč: (category_type_uuid, category_uuid) → bitmap produktů, které ji mají jako primární.
		// PHP `filterRelated` joinne `productPrimaryCategory` na aktuální `main_category_type` a
		// matchuje `fk_category`. Build stačí linearly — produkcí je desítky tisíc řádků.
		let mut primary_category_by_type_cat: AHashMap<(SmolStr, SmolStr), RoaringBitmap> =
			AHashMap::with_capacity(raw.product_primary_categories.len());
		for row in &raw.product_primary_categories {
			let Some(product_idx) = product_pool.lookup(&row.product_uuid) else {
				continue;
			};
			let key = (SmolStr::new(&row.category_type_uuid), SmolStr::new(&row.category_uuid));
			primary_category_by_type_cat.entry(key).or_default().insert(product_idx);
		}

		// --- related_slaves_by_type_master (for `relatedSlave` filter) ---
		// Klíč: (type_uuid, master_uuid) → bitmap slave produktů. PHP `filterRelatedSlave` joinne
		// `eshop_related` a matchuje fk_type + fk_master.
		let mut related_slaves_by_type_master: AHashMap<(SmolStr, SmolStr), RoaringBitmap> =
			AHashMap::with_capacity(raw.related_rows.len());
		for row in &raw.related_rows {
			let Some(slave_idx) = product_pool.lookup(&row.slave_uuid) else {
				continue;
			};
			let key = (SmolStr::new(&row.type_uuid), SmolStr::new(&row.master_uuid));
			related_slaves_by_type_master.entry(key).or_default().insert(slave_idx);
		}

		// --- categories_by_path_suffix (for `crossSellFilter`) ---
		// 4-char suffix → list CategoryIdx. PHP `filterCrossSellFilter` dělí input path na 4-char
		// chunky a ORuje `categories.path LIKE '%chunk'`. Katalog má typicky 2-3k kategorií, takže
		// i single-pass build zvládne v ms.
		let mut categories_by_path_suffix: AHashMap<SmolStr, SmallVec<[CategoryIdx; 4]>> = AHashMap::new();
		for cat in &raw.categories {
			if cat.path.len() < 4 {
				continue;
			}
			let suffix = &cat.path[cat.path.len() - 4..];
			let Some(cat_idx) = category_pool.lookup(&cat.uuid) else {
				continue;
			};
			categories_by_path_suffix
				.entry(SmolStr::new(suffix))
				.or_default()
				.push(cat_idx);
		}

		// --- drift hash ---
		let schema_version = hash_drift(&raw.drift);

		// Compact / optimize memory layout.
		attr_value_bitmaps.shrink_to_fit();
		category_bitmaps.shrink_to_fit();
		producer_bitmaps.shrink_to_fit();
		display_amount_bitmaps.shrink_to_fit();
		display_delivery_bitmaps.shrink_to_fit();
		ribbon_bitmaps.shrink_to_fit();
		internal_ribbon_bitmaps.shrink_to_fit();

		let elapsed = started.elapsed();
		info!(?elapsed, "catalog snapshot built");

		Ok(CatalogSnapshot {
			product_pool,
			pricelist_pool,
			attribute_value_pool,
			category_pool,
			producer_pool,
			visibility_list_pool,
			products,
			prices,
			prices_by_product,
			attr_value_bitmaps,
			category_bitmaps,
			direct_category_bitmaps,
			producer_bitmaps,
			display_amount_bitmaps,
			display_delivery_bitmaps,
			ribbon_bitmaps,
			internal_ribbon_bitmaps,
			all_products_mask,
			master_mask,
			pricelists,
			pricelist_pk_to_idx,
			visibility_items,
			visibility_by_product,
			visibility_winner_bitmaps,
			visibility_lists,
			categories,
			category_bump_sets,
			attribute_pool,
			attribute_of_value,
			ribbon_pool,
			internal_ribbon_pool,
			display_amount_uuid_by_idx,
			display_delivery_uuid_by_idx,
			display_amount_is_sold,
			is_sold_by_product,
			product_ids,
			primary_category_by_type_cat,
			related_slaves_by_type_master,
			categories_by_path_suffix,
			schema_version,
			built_at: Instant::now(),
		})
	}
}

/// Compose a stable hash from drift signals — the refresher stores this on the snapshot
/// and compares against a freshly-queried tuple to decide whether a rebuild is needed.
fn hash_drift(drift: &DriftSignals) -> u64 {
	let mut hasher = AHasher::default();
	drift.hash(&mut hasher);
	hasher.finish()
}

/// Fold child-of edges from `CategoryNode::parent` into inclusive descendant sets. Linear in
/// the tree size (2–3k categories in prod).
fn build_category_descendants(categories: &mut [CategoryNode], category_bitmaps: &BitmapIndex) {
	// Children adjacency first.
	let mut children: AHashMap<CategoryIdx, SmallVec<[CategoryIdx; 8]>> = AHashMap::new();
	for c in categories.iter() {
		if let Some(parent) = c.parent {
			children.entry(parent).or_default().push(c.idx);
		}
	}

	// DFS fill — each node's `descendants` is itself ∪ children.descendants.
	// Memoized so we avoid recomputing shared subtrees for dense category graphs.
	let mut filled: AHashMap<CategoryIdx, RoaringBitmap> = AHashMap::with_capacity(categories.len());
	let indexes_to_fill: Vec<CategoryIdx> = categories.iter().map(|c| c.idx).collect();
	for idx in indexes_to_fill {
		fill_descendants(idx, &children, category_bitmaps, &mut filled);
	}
	for cat in categories {
		if let Some(bm) = filled.remove(&cat.idx) {
			cat.descendants = bm;
		}
	}
}

fn fill_descendants(
	node: CategoryIdx,
	children: &AHashMap<CategoryIdx, SmallVec<[CategoryIdx; 8]>>,
	category_bitmaps: &BitmapIndex,
	memo: &mut AHashMap<CategoryIdx, RoaringBitmap>,
) -> RoaringBitmap {
	if let Some(bm) = memo.get(&node) {
		return bm.clone();
	}
	let mut acc = category_bitmaps.get(node).cloned().unwrap_or_default();
	if let Some(kids) = children.get(&node) {
		for k in kids {
			acc |= fill_descendants(*k, children, category_bitmaps, memo);
		}
	}
	memo.insert(node, acc.clone());
	acc
}

/// Intern a `displayAmount` / `displayDelivery` UUID as a dense `u32`. These dimensions are
/// low-cardinality (< 20 entries each), so a global intern is tiny.
fn display_amount_idx(uuid: &str) -> u32 {
	// Shared intern for display_* — a dedicated cross-snapshot pool would be cleaner, but
	// for v1 we key by `ahash(uuid)` and accept that two builds produce different indexes.
	// The indexes are opaque to the wire protocol (we serialize back to `displayAmountUuid`
	// on the response), so this is safe.
	let mut hasher = AHasher::default();
	uuid.hash(&mut hasher);
	// Truncate to u32 — collisions within a single dimension of < 20 entries are astronomically unlikely.
	(hasher.finish() & 0xFFFF_FFFF) as u32
}

/// Synthetic snapshot for `cargo bench --bench filter_bench` — honest shapes, random data.
/// Returns an empty, well-formed snapshot the benchmarks can seed via a builder.
///
/// TODO(#M1-bench): seed with realistic sparse product/price/attribute distribution so
/// benchmarks match production timings (filter takes ~O(|candidates|) so synthetic
/// uniform-random data under-represents dense hotspots).
#[must_use]
pub fn fixture_snapshot(product_count: usize, pricelist_count: usize) -> CatalogSnapshot {
	let _ = pricelist_count;
	// Synthetic `eshop_product.id` starting at 100_000 so they stay visually distinct from
	// ProductIdx values and from the pricelist index space.
	let product_ids: Vec<u64> = (0..product_count).map(|i| 100_000u64 + i as u64).collect();
	CatalogSnapshot {
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
		all_products_mask: roaring::RoaringBitmap::new(),
		master_mask: roaring::RoaringBitmap::new(),
		pricelists: Vec::new(),
		pricelist_pk_to_idx: ahash::AHashMap::new(),
		visibility_items: Vec::new(),
		visibility_by_product: ahash::AHashMap::new(),
		visibility_winner_bitmaps: ahash::AHashMap::new(),
		visibility_lists: Vec::new(),
		categories: Vec::new(),
		category_bump_sets: ahash::AHashMap::new(),
		attribute_pool: InternPool::new("attribute", 0),
		attribute_of_value: ahash::AHashMap::new(),
		ribbon_pool: InternPool::new("ribbon", 0),
		internal_ribbon_pool: InternPool::new("internal_ribbon", 0),
		display_amount_uuid_by_idx: ahash::AHashMap::new(),
		display_delivery_uuid_by_idx: ahash::AHashMap::new(),
		display_amount_is_sold: ahash::AHashMap::new(),
		is_sold_by_product: Vec::new(),
		product_ids,
		primary_category_by_type_cat: ahash::AHashMap::new(),
		related_slaves_by_type_master: ahash::AHashMap::new(),
		categories_by_path_suffix: ahash::AHashMap::new(),
		schema_version: 0,
		built_at: Instant::now(),
	}
}
