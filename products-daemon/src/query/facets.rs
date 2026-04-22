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

use roaring::RoaringBitmap;

use crate::{
	protocol::GetProductsRequest,
	query::{filter, PricedProduct},
	snapshot::{CatalogSnapshot, PricelistIdx},
};

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
pub fn compute(
	snap: &CatalogSnapshot,
	req: &GetProductsRequest,
	surviving_mask: &RoaringBitmap,
	priced: &[PricedProduct],
	pricelist_idxs: &[PricelistIdx],
) -> FacetCounts {
	let mut out = FacetCounts::default();

	let has_price_mask = filter::has_any_price_mask(snap, pricelist_idxs, req.price_visibility);

	// --- attribute value counts — per-attribute leave-one-out --------------------------
	// Strategy:
	// - For each attribute_pk referenced in `dynamic_filter_attributes`, rebuild the pre-mask
	//   with that attribute's filter removed; count each of ITS value buckets against that mask.
	// - For all other attributes (not in the request filter), the surviving_mask already
	//   represents the right pre-mask (removing a filter that isn't applied is a no-op).
	compute_attribute_counts(snap, req, surviving_mask, &has_price_mask, &mut out.attr_values);

	// --- producer counts (leave-one-out: ignore producer filter) -----------------------
	let producer_pre = leave_dim_out(snap, req, filter::FilterDim::Producer, &has_price_mask);
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
	let da_pre = leave_dim_out(snap, req, filter::FilterDim::DisplayAmount, &has_price_mask);
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
	let dd_pre = leave_dim_out(snap, req, filter::FilterDim::DisplayDelivery, &has_price_mask);
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
		let cat_pre = leave_dim_out(snap, req, filter::FilterDim::Category, &has_price_mask);
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
	snap: &CatalogSnapshot,
	req: &GetProductsRequest,
	surviving_mask: &RoaringBitmap,
	has_price_mask: &RoaringBitmap,
	out: &mut HashMap<String, u64>,
) {
	// Resolve each requested attribute_pk to its AttributeIdx (skipping unknown ones —
	// those never produced any bitmap hits anyway).
	let filtered_attr_idxs: ahash::AHashMap<u32, RoaringBitmap> = req
		.dynamic_filter_attributes
		.as_ref()
		.map(|m| {
			m.keys()
				.filter_map(|pk| {
					let attr_idx = snap.attribute_pool.lookup(pk)?;
					Some((attr_idx, leave_attribute_out(snap, req, pk, has_price_mask)))
				})
				.collect()
		})
		.unwrap_or_default();

	for (value_idx, bitmap) in snap.attr_value_bitmaps.iter() {
		let mask = snap
			.attribute_of_value
			.get(&value_idx)
			.and_then(|attr_idx| filtered_attr_idxs.get(attr_idx))
			.unwrap_or(surviving_mask);
		let count = intersection_len(mask, bitmap);
		if count == 0 {
			continue;
		}
		if let Some(uuid) = snap.attribute_value_pool.get(value_idx) {
			out.insert(uuid.to_owned(), count);
		}
	}
}

#[inline]
fn intersection_len(a: &RoaringBitmap, b: &RoaringBitmap) -> u64 {
	a.intersection_len(b)
}

/// Rebuild a mask with all filters applied except the named dimension, then intersect with
/// `has_price_mask` so facet counts never include products without any matching pricelist.
///
/// Falls back to a clone of the full active mask on error — facet computation is best-effort
/// and shouldn't block the whole response for a mistyped filter key (the main path already
/// validated before calling us).
fn leave_dim_out(
	snap: &CatalogSnapshot,
	req: &GetProductsRequest,
	dim: filter::FilterDim,
	has_price_mask: &RoaringBitmap,
) -> RoaringBitmap {
	let stripped_filters = req.filters.dims_besides(dim);
	let mut stripped = req.clone();
	stripped.filters = stripped_filters;
	let mask = filter::base_mask(snap, &stripped.visibility_list_pks)
		.and_then(|base| filter::apply_bitmap_filters(snap, base, &stripped))
		.unwrap_or_else(|_| snap.all_products_mask.clone());
	mask & has_price_mask
}

/// Rebuild a mask with all filters applied except one specific attribute key in
/// `dynamic_filter_attributes`. Intersects with `has_price_mask`.
fn leave_attribute_out(
	snap: &CatalogSnapshot,
	req: &GetProductsRequest,
	attr_pk_to_omit: &str,
	has_price_mask: &RoaringBitmap,
) -> RoaringBitmap {
	let mut stripped = req.clone();
	if let Some(attrs) = stripped.dynamic_filter_attributes.as_mut() {
		attrs.remove(attr_pk_to_omit);
	}
	let mask = filter::base_mask(snap, &stripped.visibility_list_pks)
		.and_then(|base| filter::apply_bitmap_filters(snap, base, &stripped))
		.unwrap_or_else(|_| snap.all_products_mask.clone());
	mask & has_price_mask
}
