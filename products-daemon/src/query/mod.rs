//! Query engine — the hot path invoked per request.
//!
//! Pipeline (mirrors `LiveProductsProvider::getProductsFromCacheTable`):
//!
//! 1. **Build base mask** — start from `CatalogSnapshot::all_products_mask`,
//!    intersect with visibility-list membership.
//! 2. **Bitmap filters** — customer-independent: category, attributes, producer, displayAmount.
//! 3. **Pricelist membership mask** — product must have a price in at least one of the
//!    customer's pricelists.
//! 4. **Per-candidate priority-first best price** with discount / surcharge / currency modifiers.
//! 5. **Price-band filter** (priceFrom/priceTo) — applied after effective price is known.
//! 6. **Facets** — leave-one-out bitmap counts + price min/max.
//! 7. **Ordering** — priority / name / price.
//!
//! The whole pipeline works on indexes; strings are only materialized on the response envelope.

pub mod facets;
pub mod filter;
pub mod ordering;
pub mod pricing;

use std::sync::Arc;

use roaring::RoaringBitmap;

use std::collections::HashMap;

use crate::{
	error::{RequestError, Result},
	protocol::{GetAllCategoryCountsRequest, GetCategoryCountRequest, GetProductsRequest, GetProductsResponse},
	snapshot::{CatalogSnapshot, CategoryIdx, PricelistIdx, ProductIdx},
};

/// Entry point used by the server handler. Orchestrates the whole pipeline.
///
/// ```text
/// request → base_mask → apply_filters → pricing::best_prices
///                                     → price_band_filter
///                                     → facets::compute
///                                     → ordering::order_and_serialize
///                                     → GetProductsResponse
/// ```
pub fn run(snap: &Arc<CatalogSnapshot>, req: &GetProductsRequest) -> Result<GetProductsResponse> {
	// Fast bail-out: features the daemon doesn't own yet.
	req.ensure_supported()?;

	// 1. Base mask (visibility + deletedTs IS NULL via `all_products_mask`).
	let base_mask = filter::base_mask(snap, &req.visibility_list_pks)?;

	// 2. Customer-independent bitmap filters.
	let mut mask = filter::apply_bitmap_filters(snap, base_mask, req)?;

	// 3. Translate customer pricelist PKs → PricelistIdx set, then mask by "has-price-in-set".
	let pricelist_idxs: Vec<PricelistIdx> = resolve_pricelists(snap, &req.pricelist_pks)?;
	let has_price_mask = filter::has_any_price_mask(snap, &pricelist_idxs, req.price_visibility);
	mask &= has_price_mask;

	// 4. Priority-first best price per candidate.
	let priced = pricing::compute_effective_prices(snap, &mask, &pricelist_idxs, &req.price_modifiers)?;

	// 5a. Restrictive dynamic filters (contract, notPublic, project) — applied after pricing
	//     because contract/notPublic check the *selected* pricelist against customer favourites.
	let priced = filter::apply_restrictive_filters(snap, priced, req);

	// 5b. Price-band filter (priceFrom/priceTo/priceGt) after effective price is computed.
	//     `show_vat` from PHP `ShopperUser::getMainPriceType() === 'withVat'` picks the field.
	let priced = pricing::apply_price_band(priced, &req.filters, req.price_visibility.show_vat);
	let surviving_mask: RoaringBitmap = priced.iter().map(|p| p.product).collect();

	// 6. Facets.
	let facets = facets::compute(snap, req, &surviving_mask, &priced, &pricelist_idxs);

	// 7. Ordering + serialize PKs to wire form.
	let ordered_pks = ordering::order_and_serialize(
		snap,
		priced,
		req.order_by.as_deref(),
		req.order_direction,
		req.order_uuids.as_deref(),
	);

	Ok(GetProductsResponse {
		product_pks: ordered_pks,
		attribute_values_counts: facets.attr_values,
		display_amounts_counts: facets.display_amounts,
		display_deliveries_counts: facets.display_deliveries,
		producers_counts: facets.producers,
		categories_counts: facets.categories,
		price_min: facets.price_min,
		price_max: facets.price_max,
		price_vat_min: facets.price_vat_min,
		price_vat_max: facets.price_vat_max,
		fallback_required: false,
	})
}

/// Counts products surviving base visibility, bitmap filters and pricelist membership —
/// sémanticky mirror `LiveProductsProvider::fetchAllCategoryCountsDirect` (jen pro jednu
/// kategorii, výsledek per-kategorie bucket je v PHP proxy memo).
///
/// **Záměrně se vynechává:**
/// - pricing pipeline (best-price / modifiers) — PHP count SQL rovněž neprovádí výběr
///   per-pricelist ceny, jen existence řádku v `eshop_price`.
/// - `apply_restrictive_filters` (contract/notPublic/project) — PHP SQL tyto filtry ignoruje
///   (docstring `fetchAllCategoryCountsDirect`: "pro menu stromek je malá nepřesnost přijatelná").
/// - price-band + facets + ordering — nejsou v count use-case potřeba.
pub fn count(snap: &Arc<CatalogSnapshot>, req: &GetCategoryCountRequest) -> Result<u64> {
	let base_mask = filter::base_mask(snap, &req.visibility_list_pks)?;

	// apply_bitmap_filters čte jen `filters` a `dynamic_filter_attributes` z requestu — stavíme
	// ad-hoc GetProductsRequest s defaulty pro zbytek polí, abychom nedupliovali filter logiku.
	let pseudo = GetProductsRequest {
		filters: req.filters.clone(),
		dynamic_filter_attributes: req.dynamic_filter_attributes.clone(),
		..GetProductsRequest::default()
	};
	let mut mask = filter::apply_bitmap_filters(snap, base_mask, &pseudo)?;

	let pricelist_idxs = resolve_pricelists(snap, &req.pricelist_pks)?;
	let has_price_mask = filter::has_any_price_mask(snap, &pricelist_idxs, req.price_visibility);
	mask &= has_price_mask;

	Ok(mask.len())
}

/// Batched category counts — jeden průchod vrátí mapu `categoryUuid → count` pro celý snapshot.
///
/// Mirroruje `ProductsCacheGetterService.php:734`: iteruje `direct_category_bitmaps` (přímé
/// členství produkt↔kategorie) a pro každou direct kategorii přičte `|surviving ∩ direct_bitmap|`
/// do všech targetů v precomputed `category_bump_sets[direct_idx]` — self, flagged descendants
/// i flagged ancestors. Žádné ordering, žádné pricing pipeline, žádné restrictive filters;
/// stejná sémantika jako per-request memoizace v cache getteru.
///
/// Caller typicky vynechá `filters.category_uuids` (pak vrací counts pro celý katalog). Pokud
/// ho pošle, `apply_bitmap_filters` ořízne mask na subtree té kategorie.
pub fn all_category_counts(
	snap: &Arc<CatalogSnapshot>,
	req: &GetAllCategoryCountsRequest,
) -> Result<HashMap<String, u64>> {
	let base_mask = filter::base_mask(snap, &req.visibility_list_pks)?;
	let pseudo = GetProductsRequest {
		filters: req.filters.clone(),
		dynamic_filter_attributes: req.dynamic_filter_attributes.clone(),
		..GetProductsRequest::default()
	};
	let mut mask = filter::apply_bitmap_filters(snap, base_mask, &pseudo)?;
	let pricelist_idxs = resolve_pricelists(snap, &req.pricelist_pks)?;
	let has_price_mask = filter::has_any_price_mask(snap, &pricelist_idxs, req.price_visibility);
	mask &= has_price_mask;

	// Agregace per CategoryIdx (u64, ať se vejdou součty nad celým snapshotem); UUID string
	// materializujeme až na výstupu, abychom v hot smyčce nealokovali do HashMapu stringové klíče.
	let mut counts_by_idx: ahash::AHashMap<CategoryIdx, u64> =
		ahash::AHashMap::with_capacity(snap.categories.len());
	for (direct_idx, direct_bitmap) in snap.direct_category_bitmaps.iter() {
		let n = mask.intersection_len(direct_bitmap);
		if n == 0 {
			continue;
		}
		let Some(bumps) = snap.category_bump_sets.get(&direct_idx) else {
			// Drift pojistka: snapshot build garantuje bump set pro každý direct_idx, ale pokud
			// by refresher swapnul inconsistent snapshot (race), necháme aspoň self-count přežít.
			*counts_by_idx.entry(direct_idx).or_insert(0) += n;
			continue;
		};
		for &bump_idx in bumps {
			*counts_by_idx.entry(bump_idx).or_insert(0) += n;
		}
	}

	let out = counts_by_idx
		.into_iter()
		.filter_map(|(idx, count)| snap.category_pool.get(idx).map(|uuid| (uuid.to_owned(), count)))
		.collect();
	Ok(out)
}

/// Vrátí UUID produktů, které jsou prodejné — tj. zároveň:
/// - mají nenulovou cenu (`price > 0 OR price_vat > 0`) v aspoň jednom aktivním ceníku, který má
///   vazbu na customer group / customer / favourite / merchant (`has_customer_binding`), a
/// - jsou přiřazené k aspoň jednomu visibility listu přes `eshop_visibilitylistitem` s
///   `hidden = 0`, kde visibility list je aktivní (`hidden = 0` na `eshop_visibilitylist`) a
///   zároveň má customer vazbu.
///
/// In-memory O(P) scan — žádný DB dotaz, daemon má celý snapshot v RAM.
///
/// Sémanticky cílí na paritu s DISTINCT product ze všech `prices_*` / `cache_prices_*` tabulek,
/// které generuje `ProductsCacheDiffUpdateService`. Cache plní pouze kombinace (customer group ×
/// customer × merchant) → subset aktivních ceníků + visibility listů s vazbou. Orphan ceníky
/// (admin-only, nepoužívané) do cache nepadají, takže je vylučuje i daemon.
///
/// V produkčním snapshot (DDEV kopie): 7558 aktivních ceníků, ~60k prodejných produktů.
/// Bez filtrů: ~160k (price > 0 jakýkoliv ceník), jen is_active: ~124k.
pub fn sellable_product_pks(snap: &CatalogSnapshot) -> Vec<String> {
	// 1. Produkty s nenulovou cenou v aktivním customer-bound ceníku.
	let mut has_active_price = RoaringBitmap::new();
	for price in &snap.prices {
		let pl_meta = &snap.pricelists[usize::from(price.pricelist)];
		if !pl_meta.is_active || !pl_meta.has_customer_binding {
			continue;
		}
		if price.price <= 0.0 && price.price_vat <= 0.0 {
			continue;
		}
		has_active_price.insert(price.product);
	}

	// 2. Produkty s aspoň jedním non-hidden visibility item na aktivním customer-bound VL.
	let mut has_visible_list = RoaringBitmap::new();
	for item in &snap.visibility_items {
		if item.hidden {
			continue;
		}
		// `visibility_lists[idx]` je plněno v SnapshotBuilderu před items, takže idx vždy sedí.
		// `get()` jako pojistka proti driftu — raději vynechat item než panikařit v hot path.
		let Some(vl_meta) = snap.visibility_lists.get(usize::from(item.visibility_list)) else {
			continue;
		};
		if !vl_meta.is_active || !vl_meta.has_customer_binding {
			continue;
		}
		has_visible_list.insert(item.product);
	}

	// 3. Intersect: prodejný = má customer-bound cenu AND je na customer-bound visibility listu.
	let sellable = has_active_price & has_visible_list;

	sellable
		.iter()
		.filter_map(|idx| snap.product_pool.get(idx).map(str::to_owned))
		.collect()
}

fn resolve_pricelists(snap: &CatalogSnapshot, pks: &[String]) -> Result<Vec<PricelistIdx>> {
	let mut out = Vec::with_capacity(pks.len());
	for pk in pks {
		let idx = *snap
			.pricelist_pk_to_idx
			.get(pk.as_str())
			.ok_or_else(|| RequestError::UnknownPricelist(pk.clone()))?;
		out.push(idx);
	}
	Ok(out)
}

/// A product that survived filtering, paired with its effective (final) prices.
/// `Copy` so facets / ordering can shuffle it without clones.
///
/// Prices are `f64` (mirrors PHP `float`, which is double precision). `PriceFact` storage
/// stays `f32` for the 24 B Copy budget; `pricing::apply_modifiers` upcasts to f64 for the
/// arithmetic to match `LiveProductsProvider::computeEffectivePrices` bit-for-bit after
/// `round_to_prec`.
#[derive(Debug, Copy, Clone, PartialEq)]
pub struct PricedProduct {
	pub product: ProductIdx,
	pub price: f64,
	pub price_vat: f64,
	pub price_before: f64,
	pub price_vat_before: f64,
	pub pricelist: PricelistIdx,
}
