//! Facet computation — leave-one-out bitmap counts per filter dimension.
//!
//! ## PHP parity reference
//! - `LiveProductsProvider::buildOutput` — assembles `attributeValuesCounts`, `producersCounts`, etc.
//! - `LiveProductsProvider::filterAndCount` line 1520 — the hot loop this replaces.
//!
//! ## Algorithm
//! For each facet dimension we need counts **as if** that dimension were not filtered, so the
//! UI can show "red (12) blue (7)" even while "red" is the current selection. Concretely:
//!
//! 1. Build `pre_dim_mask` = apply all filters *except* the one for this dimension, then
//!    intersect with `has_price_mask` (mirrors the production pipeline — products without a
//!    price in the customer's set never show up in the counts).
//! 2. For every value bucket `V` in the dimension: count `|pre_dim_mask ∩ V.bitmap|`.
//!
//! Attribute dimensions are leave-one-out **per attribute key** (not across the whole
//! `dynamic_filter_attributes` map). Requires `snap.attribute_of_value` built from
//! `eshop_attributevalue` so we know which values belong to which attribute.
//!
//! Roaring bitmap intersection is O(|smaller|) — cheap even with thousands of attribute values.

use std::collections::HashMap;

use rayon::prelude::*;
use roaring::RoaringBitmap;

use crate::query::{filter, pricing, PricedProduct, RequestContext};

#[derive(Debug, Default)]
pub struct FacetCounts {
	pub attr_values: HashMap<String, u64>,
	pub display_amounts: HashMap<String, u64>,
	pub display_deliveries: HashMap<String, u64>,
	pub producers: HashMap<String, u64>,
	pub categories: Option<HashMap<String, u64>>,
	pub price_min: f64,
	pub price_max: f64,
	pub price_vat_min: f64,
	pub price_vat_max: f64,
}

/// Compute all facet aggregates in one pass.
///
/// `surviving_mask` = výsledek **plné** pipeline z hlavního `run`/`count`. Pro nezfiltrované
/// dimenze se použije přímo (leave-one-out odstranění filtru, který není aktivní, je no-op).
/// Pro filtrované dimenze leave_dim_out / leave_attribute_out spustí plnou pipeline znovu se
/// stripped filters (žádný shortcut — počet ve facetu musí přesně odpovídat tomu, co by
/// uživatel viděl po odstranění toho jednoho filtru).
pub fn compute(ctx: &RequestContext<'_>, surviving_mask: &RoaringBitmap, priced: &[PricedProduct]) -> FacetCounts {
	let mut out = FacetCounts::default();
	let snap = ctx.snap;
	let req = ctx.req;

	// --- attribute value counts — per-attribute leave-one-out --------------------------
	// Strategy:
	// - For each attribute_pk referenced in `dynamic_filter_attributes`, rebuild the pre-mask
	//   with that attribute's filter removed; count each of ITS value buckets against that mask.
	// - For all other attributes (not in the request filter), the surviving_mask already
	//   represents the right pre-mask (removing a filter that isn't applied is a no-op).
	compute_attribute_counts(ctx, surviving_mask, &mut out.attr_values);

	// --- producer counts (leave-one-out: ignore producer filter) -----------------------
	let producer_pre = leave_dim_out(ctx, filter::FilterDim::Producer, surviving_mask);
	for (idx, bitmap) in snap.producer_bitmaps.iter() {
		let count = intersection_len(&producer_pre, bitmap);
		if count == 0 {
			continue;
		}
		if let Some(uuid) = snap.producer_pool.get(idx) {
			out.producers.insert(uuid.to_owned(), count);
		}
	}

	// --- displayAmount counts ----------------------------------------------------------
	let da_pre = leave_dim_out(ctx, filter::FilterDim::DisplayAmount, surviving_mask);
	for (idx, bitmap) in snap.display_amount_bitmaps.iter() {
		let count = intersection_len(&da_pre, bitmap);
		if count == 0 {
			continue;
		}
		let key = snap
			.display_amount_uuid_by_idx
			.get(&idx)
			.map(|u| u.as_str().to_owned())
			.unwrap_or_else(|| idx.to_string());
		out.display_amounts.insert(key, count);
	}

	// --- displayDelivery counts --------------------------------------------------------
	let dd_pre = leave_dim_out(ctx, filter::FilterDim::DisplayDelivery, surviving_mask);
	for (idx, bitmap) in snap.display_delivery_bitmaps.iter() {
		let count = intersection_len(&dd_pre, bitmap);
		if count == 0 {
			continue;
		}
		let key = snap
			.display_delivery_uuid_by_idx
			.get(&idx)
			.map(|u| u.as_str().to_owned())
			.unwrap_or_else(|| idx.to_string());
		out.display_deliveries.insert(key, count);
	}

	// --- category counts (optional: only if countCategories=true) ----------------------
	if req.count_categories {
		let cat_pre = leave_dim_out(ctx, filter::FilterDim::Category, surviving_mask);
		let mut cat_counts = HashMap::new();
		for (idx, bitmap) in snap.category_bitmaps.iter() {
			let count = intersection_len(&cat_pre, bitmap);
			if count == 0 {
				continue;
			}
			if let Some(uuid) = snap.category_pool.get(idx) {
				cat_counts.insert(uuid.to_owned(), count);
			}
		}
		out.categories = Some(cat_counts);
	}

	// --- price min/max from the already-priced set -------------------------------------
	if let (Some(mn), Some(mx)) = (
		priced.iter().map(|p| p.price).reduce(f64::min),
		priced.iter().map(|p| p.price).reduce(f64::max),
	) {
		out.price_min = mn;
		out.price_max = mx;
	}
	if let (Some(mn), Some(mx)) = (
		priced.iter().map(|p| p.price_vat).reduce(f64::min),
		priced.iter().map(|p| p.price_vat).reduce(f64::max),
	) {
		out.price_vat_min = mn;
		out.price_vat_max = mx;
	}

	out
}

/// Per-attribute leave-one-out. Breakdown:
/// - Values under an attribute the user IS filtering → count against pre-mask without that attr's constraint.
/// - Values under any other attribute → count against the `surviving_mask` (same as not filtering it).
fn compute_attribute_counts(
	ctx: &RequestContext<'_>,
	surviving_mask: &RoaringBitmap,
	out: &mut HashMap<String, u64>,
) {
	let snap = ctx.snap;
	let req = ctx.req;

	// Resolve each requested attribute_pk to its AttributeIdx (skipping unknown ones —
	// those never produced any bitmap hits anyway).
	let filtered_attr_idxs: ahash::AHashMap<u32, RoaringBitmap> = req
		.dynamic_filter_attributes
		.as_ref()
		.map(|m| {
			m.keys()
				.filter_map(|pk| {
					let attr_idx = snap.attribute_pool.lookup(pk)?;
					Some((attr_idx, leave_attribute_out(ctx, pk)))
				})
				.collect()
		})
		.unwrap_or_default();

	// `attr_value_bitmaps` má v produkčním snapshotu tisíce buckets — každý
	// `intersection_len` je sub-µs, ale celkem to je největší jednotlivý cost ve facets
	// (~3-5 ms / call). Rayon paralelizuje fan-out přes worker pool inicializovaný jednou
	// per process. Roaring bitmapy + AHashMap jsou `Sync` (immutable read), tak je sdílíme
	// bez kopie. `fold` shromažďuje per-thread Vec<(idx, count)>, `reduce` slije do jednoho —
	// odstraňuje contention na výsledné HashMapě.
	let entries: Vec<(u32, &RoaringBitmap)> = snap.attr_value_bitmaps.iter().collect();
	let folded: Vec<(u32, u64)> = entries
		.par_iter()
		.fold(Vec::new, |mut acc, &(value_idx, bitmap)| {
			let mask = snap
				.attribute_of_value
				.get(&value_idx)
				.and_then(|attr_idx| filtered_attr_idxs.get(attr_idx))
				.unwrap_or(surviving_mask);
			let count = intersection_len(mask, bitmap);
			if count != 0 {
				acc.push((value_idx, count));
			}
			acc
		})
		.reduce(Vec::new, |mut a, mut b| {
			if a.len() < b.len() {
				std::mem::swap(&mut a, &mut b);
			}
			a.extend(b);
			a
		});

	out.reserve(folded.len());
	for (value_idx, count) in folded {
		if let Some(uuid) = snap.attribute_value_pool.get(value_idx) {
			out.insert(uuid.to_owned(), count);
		}
	}
}

#[inline]
fn intersection_len(a: &RoaringBitmap, b: &RoaringBitmap) -> u64 {
	a.intersection_len(b)
}

/// Rebuild surviving set as if the named dimension were not filtered. **Plná pipeline** —
/// bitmap filters (stripped) → has_price → compute_effective_prices → apply_restrictive_filters
/// → apply_price_band. Žádný shortcut: facet count musí přesně odpovídat tomu, co by se
/// listingem vrátilo po odstranění toho filtru.
///
/// **Fast path** pro nezfiltrovanou dimenzi (`req.filters.<dim>_uuids.is_none()`): stripping
/// je no-op, surviving == surviving_mask z hlavní pipeline → vrátíme rovnou. Šetří kompletní
/// pricing+restrictive recompute pro 0-3 dimenze, které uživatel zrovna nefiltruje.
fn leave_dim_out(
	ctx: &RequestContext<'_>,
	dim: filter::FilterDim,
	surviving_mask: &RoaringBitmap,
) -> RoaringBitmap {
	let req = ctx.req;
	let dim_active = match dim {
		filter::FilterDim::Category => req.filters.category_uuids.is_some(),
		filter::FilterDim::Producer => req.filters.producer_uuids.is_some(),
		filter::FilterDim::DisplayAmount => req.filters.display_amount_uuids.is_some(),
		filter::FilterDim::DisplayDelivery => req.filters.display_delivery_uuids.is_some(),
	};
	if !dim_active {
		return surviving_mask.clone();
	}

	let stripped_filters = req.filters.dims_besides(dim);
	pipeline_with_overrides(ctx, &stripped_filters, req.dynamic_filter_attributes.as_ref(), None)
}

/// Rebuild surviving set as if one specific attribute key were not filtered. **Plná
/// pipeline** — stejně jako `leave_dim_out`, žádný shortcut.
fn leave_attribute_out(ctx: &RequestContext<'_>, attr_pk_to_omit: &str) -> RoaringBitmap {
	let req = ctx.req;
	pipeline_with_overrides(
		ctx,
		&req.filters,
		req.dynamic_filter_attributes.as_ref(),
		Some(attr_pk_to_omit),
	)
}

/// Společná pipeline pro facet leave-one-out — stripped bitmap filters → has_price →
/// compute_effective_prices → apply_restrictive_filters → apply_price_band → bitmap.
///
/// Vstupní `filters` jsou už zbavené dim, kterou facet vynechává (pro attribute leave-out
/// se stripping děje uvnitř `apply_bitmap_filters_inner` přes `omit_attr_pk`). Restrictive
/// parametry (favourites/contract/notPublic/project) i price modifiers se berou ze
/// `ctx.req` — ty se neodstraňují, jsou per-customer fixní pro celý request.
///
/// Při interním errorech (neznámé UUID v stripped filtrech) vrátí prázdný bitmap. Hlavní
/// pipeline ten samý request už validovala — dorazit sem error znamená drift snapshotu
/// během requestu, lepší je vrátit "0 v této dim" než celý request shodit.
fn pipeline_with_overrides(
	ctx: &RequestContext<'_>,
	filters: &crate::protocol::FilterPayload,
	dynamic_filter_attributes: Option<&HashMap<String, Vec<String>>>,
	omit_attr_pk: Option<&str>,
) -> RoaringBitmap {
	let req = ctx.req;
	let snap = ctx.snap;

	let mut mask = filter::apply_bitmap_filters_inner(
		snap,
		ctx.base_mask.clone(),
		filters,
		dynamic_filter_attributes,
		&req.visibility_list_pks,
		omit_attr_pk,
	)
	.unwrap_or_else(|_| RoaringBitmap::new());
	mask &= &ctx.has_price_mask;

	let priced = pricing::compute_effective_prices(snap, &mask, &ctx.pricelist_idxs, &req.price_modifiers)
		.unwrap_or_default();
	// `apply_restrictive_filters` čte contract/notPublic/project + favourites z `req` —
	// ty zůstávají identické s hlavní pipeline (jsou per-customer fixní, neodstraňují se).
	let priced = filter::apply_restrictive_filters(snap, priced, req);
	// `apply_price_band` čte priceFrom/To/Gt přímo ze `filters` — pro stripping kategorií atd.
	// jsou hodnoty stejné jako v `req.filters`. `dims_besides` nevolá ani priceFrom; pro
	// attribute leave-out používáme `&req.filters` přímo.
	let priced = pricing::apply_price_band(priced, filters, req.price_visibility.show_vat);

	priced.iter().map(|p| p.product).collect()
}
