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

use std::{sync::Arc, time::Instant};

use roaring::RoaringBitmap;

use std::collections::HashMap;

use crate::{
	error::Result,
	protocol::{
		GetAllCategoryCountsRequest, GetCategoryCountRequest, GetProductsRequest, GetProductsResponse, TimingsBreakdown,
	},
	snapshot::{CatalogSnapshot, CategoryIdx, PricelistIdx, ProductIdx},
};

/// Akumulátor sub-step latencí v ms. Plněn jen když caller posílá `Some(&mut Timings)`,
/// jinak je celé měření no-op (instrumentace nemá zaplatit nic na produkčním fast pathu).
///
/// Konvertuje se na `TimingsBreakdown` na hranici protokolu (`into_breakdown`).
#[derive(Debug, Default)]
pub struct Timings {
	pub parse_ms: f64,
	pub base_mask_ms: f64,
	pub bitmap_filters_ms: f64,
	pub pricing_ms: f64,
	pub facets_ms: f64,
	pub ordering_ms: f64,
	pub serialize_ms: f64,
}

impl Timings {
	#[inline]
	pub fn into_breakdown(self) -> TimingsBreakdown {
		TimingsBreakdown {
			parse_ms: self.parse_ms,
			base_mask_ms: self.base_mask_ms,
			bitmap_filters_ms: self.bitmap_filters_ms,
			pricing_ms: self.pricing_ms,
			facets_ms: self.facets_ms,
			ordering_ms: self.ordering_ms,
			serialize_ms: self.serialize_ms,
		}
	}
}

/// Pomocník na konverzi elapsed `Instant` → ms (f64). Inline aby release build vůbec
/// nevolal funkci, když `Option<&mut Timings>` je `None` (větvení na vstupu `run`).
#[inline]
fn elapsed_ms(start: Instant) -> f64 {
	let nanos = start.elapsed().as_nanos();
	#[allow(clippy::cast_precision_loss)]
	{
		nanos as f64 / 1_000_000.0
	}
}

/// Per-request memoizovaný kontext sdílený hlavní pipeline a facet leave-one-out smyčkou.
///
/// **Why:** `base_mask` a `has_any_price_mask` jsou v rámci jednoho requestu invariantní
/// (závisí jen na `visibility_list_pks` resp. `pricelist_pks` + `price_visibility`).
/// Bez memoizace každý `leave_dim_out` / `leave_attribute_out` v `facets::compute` recomputuje
/// base_mask znovu (lineární scan ~16 ms při 186k produktech × ≤4 visibility items per produkt).
/// Pro typickou kategorii s 3-5 attribute filtry to bylo 5-7× zbytečně.
///
/// Eager build (ne `OnceCell`) — obě masky jsou potřeba vždy, lazy by jen přidalo runtime
/// větvení. Klonování `RoaringBitmap` při průniku ve facets je cheap (Roaring container je
/// reference-counted internally; zde jen Vec<Container> alokace).
pub struct RequestContext<'a> {
	pub snap: &'a CatalogSnapshot,
	pub req: &'a GetProductsRequest,
	pub pricelist_idxs: Vec<PricelistIdx>,
	/// PKs requestovaných ceníků, které snapshot nezná (typicky čerstvě vytvořený customer
	/// pricelist po `offer approve` / CKP sync — daemon snapshot ještě nezahrnuje). Neznámé
	/// se z `pricelist_idxs` filtrují (ne erroru) a propagují přes response do PHP, které
	/// flipne banner pro přihlášeného merchanta. Best-effort sémantika: ostatní známé ceníky
	/// se použijí normálně, takže katalog/menu zůstávají funkční místo HTTP 500.
	pub unknown_pricelist_pks: Vec<String>,
	pub base_mask: RoaringBitmap,
	pub has_price_mask: RoaringBitmap,
}

impl<'a> RequestContext<'a> {
	pub fn build(snap: &'a CatalogSnapshot, req: &'a GetProductsRequest) -> Result<Self> {
		let (pricelist_idxs, unknown_pricelist_pks) = resolve_pricelists(snap, &req.pricelist_pks);
		let base_mask = filter::base_mask(snap, &req.visibility_list_pks)?;
		let has_price_mask = filter::has_any_price_mask(snap, &pricelist_idxs, req.price_visibility);
		Ok(Self {
			snap,
			req,
			pricelist_idxs,
			unknown_pricelist_pks,
			base_mask,
			has_price_mask,
		})
	}
}

/// Spustí kompletní filter+pricing pipeline na top-level (bez stripped filters) — bitmap
/// filters → has_price → compute_effective_prices → apply_restrictive_filters → apply_price_band.
///
/// Používá `run`, `count` i `all_category_counts`, aby všechny tři cesty produkovaly identický
/// surviving set. **Jakékoliv zjednodušení (vynechání restrictive filters, price band, pricing)
/// je v rozporu s invariantem**: count/menu/facet musí přesně sedět s hlavním listingem.
///
/// Vrací surviving `Vec<PricedProduct>`. `surviving_mask` postavíme z něj v callerovi
/// (potřebujeme `priced` pro pricing facets a ordering, mask jen pro count/category aggregace).
pub(crate) fn run_filter_pipeline(
	snap: &CatalogSnapshot,
	req: &GetProductsRequest,
	base_mask: &RoaringBitmap,
	has_price_mask: &RoaringBitmap,
	pricelist_idxs: &[PricelistIdx],
) -> Result<Vec<PricedProduct>> {
	let mut mask = filter::apply_bitmap_filters(snap, base_mask.clone(), req)?;
	mask &= has_price_mask;
	let priced = pricing::compute_effective_prices(snap, &mask, pricelist_idxs, &req.price_modifiers)?;
	let priced = filter::apply_restrictive_filters(snap, priced, req);
	let priced = pricing::apply_price_band(priced, &req.filters, req.price_visibility.show_vat);
	Ok(priced)
}

/// Entry point used by the server handler. Orchestrates the whole pipeline.
///
/// ```text
/// request → base_mask → apply_filters → pricing::best_prices
///                                     → price_band_filter
///                                     → facets::compute
///                                     → ordering::order_and_serialize
///                                     → GetProductsResponse
/// ```
pub fn run(
	snap: &Arc<CatalogSnapshot>,
	req: &GetProductsRequest,
	timings: Option<&mut Timings>,
) -> Result<(GetProductsResponse, Vec<String>)> {
	// Fast bail-out: features the daemon doesn't own yet.
	req.ensure_supported()?;

	// 1. Base mask (visibility + deletedTs IS NULL via `all_products_mask`).
	let t0 = Instant::now();
	let ctx = RequestContext::build(snap, req)?;
	let unknown_pricelist_pks = ctx.unknown_pricelist_pks.clone();
	let base_mask_ms = elapsed_ms(t0);

	// 2-5. Bitmap filters → has_price → pricing → restrictive → price_band.
	// `bitmap_filters_ms` zde už zahrnuje celou pipeline kromě base_mask; necháváme staré
	// jméno, protože Tracy panel ho čeká, ale `pricing_ms` měří celé `run_filter_pipeline`.
	let t1 = Instant::now();
	let priced = run_filter_pipeline(snap, req, &ctx.base_mask, &ctx.has_price_mask, &ctx.pricelist_idxs)?;
	let surviving_mask: RoaringBitmap = priced.iter().map(|p| p.product).collect();
	let bitmap_filters_ms = 0.0; // pohlcen do pricing_ms aby pipeline měla jeden honest měřič
	let pricing_ms = elapsed_ms(t1);

	// 6. Facets.
	let t3 = Instant::now();
	let facets = facets::compute(&ctx, &surviving_mask, &priced);
	let facets_ms = elapsed_ms(t3);

	// 7. Ordering + serialize PKs to wire form.
	let t4 = Instant::now();
	let ordered_pks = ordering::order_and_serialize(
		snap,
		priced,
		req.order_by.as_deref(),
		req.order_direction,
		req.order_uuids.as_deref(),
	);
	let ordering_ms = elapsed_ms(t4);

	if let Some(t) = timings {
		t.base_mask_ms = base_mask_ms;
		t.bitmap_filters_ms = bitmap_filters_ms;
		t.pricing_ms = pricing_ms;
		t.facets_ms = facets_ms;
		t.ordering_ms = ordering_ms;
	}

	Ok((
		GetProductsResponse {
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
		},
		unknown_pricelist_pks,
	))
}

/// Counts products surviving the **full** filter+pricing pipeline (base_mask → bitmap filters
/// → has_price → compute_effective_prices → apply_restrictive_filters → apply_price_band).
///
/// Žádný shortcut — count musí přesně sedět s hlavním listingem (`run`). Pokud caller
/// nezná restrictive parametry (contract/notPublic/project) nebo `priceModifiers`, dostane
/// **jiný** počet než hlavní listing a UI menu/facet rozejde se s rendrovanou stránkou.
pub fn count(snap: &Arc<CatalogSnapshot>, req: &GetCategoryCountRequest) -> Result<(u64, Vec<String>)> {
	let pseudo = req.to_pseudo_request();
	let ctx = RequestContext::build(snap, &pseudo)?;
	let unknown_pricelist_pks = ctx.unknown_pricelist_pks.clone();
	let priced = run_filter_pipeline(snap, &pseudo, &ctx.base_mask, &ctx.has_price_mask, &ctx.pricelist_idxs)?;
	Ok((priced.len() as u64, unknown_pricelist_pks))
}

/// Batched category counts — jeden průchod vrátí mapu `categoryUuid → count` pro celý snapshot.
///
/// Mirroruje `ProductsCacheGetterService.php:734`: iteruje `direct_category_bitmaps` (přímé
/// členství produkt↔kategorie) a pro každou direct kategorii přičte `|surviving ∩ direct_bitmap|`
/// do všech targetů v precomputed `category_bump_sets[direct_idx]` — self, flagged descendants
/// i flagged ancestors. **Plná pipeline** — surviving = priced & restrictive & price_band,
/// stejně jako hlavní listing.
///
/// Caller typicky vynechá `filters.category_uuids` (pak vrací counts pro celý katalog). Pokud
/// ho pošle, `apply_bitmap_filters` ořízne mask na subtree té kategorie.
pub fn all_category_counts(
	snap: &Arc<CatalogSnapshot>,
	req: &GetAllCategoryCountsRequest,
) -> Result<(HashMap<String, u64>, Vec<String>)> {
	let pseudo = req.to_pseudo_request();
	let ctx = RequestContext::build(snap, &pseudo)?;
	let unknown_pricelist_pks = ctx.unknown_pricelist_pks.clone();
	let priced = run_filter_pipeline(snap, &pseudo, &ctx.base_mask, &ctx.has_price_mask, &ctx.pricelist_idxs)?;
	let mask: RoaringBitmap = priced.iter().map(|p| p.product).collect();

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
	Ok((out, unknown_pricelist_pks))
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

/// Rozdělí požadované ceníky na známé (`PricelistIdx`) a neznámé (`String` PK). Neznámé
/// jsou ceníky, které byly v DB vytvořeny po posledním snapshot rebuildu — typicky čerstvý
/// `offerPriceList` po offer approve nebo customer pricelist po CKP sync. Místo erroru
/// (`unknown_pricelist`, který by celý request shodil do HTTP 500) je z výsledku tiše
/// vyfiltrujeme a předáme caller-ovi seznam neznámých přes `RequestContext`. Caller je
/// pošle v response, aby PHP flipnul merchant banner.
fn resolve_pricelists(snap: &CatalogSnapshot, pks: &[String]) -> (Vec<PricelistIdx>, Vec<String>) {
	let mut known = Vec::with_capacity(pks.len());
	let mut unknown = Vec::new();
	for pk in pks {
		match snap.pricelist_pk_to_idx.get(pk.as_str()) {
			Some(idx) => known.push(*idx),
			None => unknown.push(pk.clone()),
		}
	}
	(known, unknown)
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
