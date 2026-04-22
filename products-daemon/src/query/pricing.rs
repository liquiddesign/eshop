//! Priority-first best-price resolution + discount / surcharge / currency modifiers.
//!
//! ## PHP parity reference
//! Exact port of `LiveProductsProvider::computeEffectivePrices` (lines 1078–1199 of
//! `/home/petr/eshop/src/Services/ProductsCache/LiveProductsProvider.php`). Every rounding,
//! clamp, and short-circuit here mirrors that method. Any divergence must be paired with an
//! updated golden fixture in `tests/fixtures/pricing/`.
//!
//! ## Algorithm
//! For each candidate product, walk its `prices_by_product[p]` slice (sorted by pricelist
//! priority **ASC** at snapshot-build time — lower number = higher precedence). First
//! non-hidden entry whose pricelist is in the customer's set wins; then:
//!
//! 1. Apply `currency_rate` to all four prices (price, priceVat, priceBefore, priceVatBefore)
//!    via `round(x * rate, prec)` when rate is `Some`.
//! 2. Compute `effective_discount = max(min(product_pct, max_product_discount_level), discount_level_pct)`.
//! 3. If `pricelist.allow_surcharge && surcharge_level_pct > 0`:
//!    `price /= (1 - pct/100)`, round to `prec`; same for `price_vat`.
//! 4. If `pricelist.allow_discount_level && effective_discount > 0`:
//!    `price *= (100 - eff) / 100`, round to `prec`. If `price_before == 0`, reverse-engineer
//!    `price_before = price / discount_factor` (PHP semantic: "show the pre-discount price").
//!
//! ## Float precision
//! f64 throughout — storage, wire, and arithmetic. PHP `round($x, $prec)` is
//! `PHP_ROUND_HALF_UP` by default (round half away from zero). Rust f64 `.round()` has the
//! same semantics on non-negative numbers, which is the only domain we deal with (prices ≥ 0).

use roaring::RoaringBitmap;

use crate::{
	error::Result,
	protocol::{FilterPayload, PriceModifiers},
	query::PricedProduct,
	snapshot::{CatalogSnapshot, PriceFact, PricelistIdx, PricelistMeta},
};

/// Walk the mask and produce a `PricedProduct` per candidate with an effective price.
/// Products with no matching pricelist entry are dropped (filtered earlier by `has_any_price_mask`,
/// but we re-check here defensively so a stale mask can't OOB the result list).
pub fn compute_effective_prices(
	snap: &CatalogSnapshot,
	mask: &RoaringBitmap,
	pricelists: &[PricelistIdx],
	modifiers: &PriceModifiers,
) -> Result<Vec<PricedProduct>> {
	let set: ahash::AHashSet<PricelistIdx> = pricelists.iter().copied().collect();
	let mut out = Vec::with_capacity(mask.len() as usize);

	for product in mask {
		let Some(range) = snap.prices_by_product.get(product as usize) else {
			continue;
		};
		let slice = &snap.prices[range.start as usize..range.end as usize];
		let Some(hit) = pick_best_price(slice, &set) else {
			continue;
		};

		out.push(apply_modifiers(snap, product, hit, modifiers));
	}
	Ok(out)
}

/// Given a product's prices (sorted priority ASC) and the customer's pricelist set,
/// return the first non-hidden match.
#[inline]
fn pick_best_price(prices: &[PriceFact], set: &ahash::AHashSet<PricelistIdx>) -> Option<PriceFact> {
	for p in prices {
		if !p.flags.is_hidden() && set.contains(&p.pricelist) {
			return Some(*p);
		}
	}
	None
}

/// Round `x` to `prec` decimal places using PHP `round()` semantics (half away from zero).
/// For non-negative prices this matches Rust f64 `.round()` applied after scaling.
#[inline]
#[must_use]
pub fn round_to_prec(x: f64, prec: u8) -> f64 {
	let m = 10f64.powi(i32::from(prec));
	(x * m).round() / m
}

/// Snapshot adapter — looks up `PricelistMeta` and product discount, then delegates to
/// `compute_price` for the pure math. Kept separate so integration tests can exercise the
/// arithmetic without constructing a full `CatalogSnapshot`.
#[must_use]
fn apply_modifiers(snap: &CatalogSnapshot, product: u32, hit: PriceFact, modifiers: &PriceModifiers) -> PricedProduct {
	// `snap.pricelists` is indexed by `PricelistIdx` (SnapshotBuilder pushes in intern order,
	// so `pricelists[i].idx == i`). O(1) lookup beats the O(n) `.iter().find()` in the hot path.
	let pricelist_meta = snap
		.pricelists
		.get(hit.pricelist as usize)
		.copied()
		.unwrap_or_else(PricelistMeta::neutral);

	// PHP `?? 0` — ProductRow::discount_level_pct is already non-null (u8, SQL COALESCE in loaders.rs).
	let product_discount_pct = snap.products.get(product as usize).map_or(0, |p| p.discount_level_pct);

	compute_price(product, hit, pricelist_meta, product_discount_pct, modifiers)
}

/// Pure port of the inner loop of `LiveProductsProvider::computeEffectivePrices`
/// (PHP lines 1128–1180). No snapshot dependency — takes everything it needs as parameters,
/// so it's directly driven from parity fixtures in `tests/integration_pricing.rs`.
#[must_use]
pub fn compute_price(
	product: u32,
	hit: PriceFact,
	pricelist_meta: PricelistMeta,
	product_discount_pct: u8,
	modifiers: &PriceModifiers,
) -> PricedProduct {
	let prec = modifiers.calculation_precision;
	let convert_ratio = modifiers.currency_rate;
	let max_product_discount = i32::from(modifiers.max_product_discount_level);
	let global_discount_pct = i32::from(modifiers.discount_level_pct);
	let product_pct = i32::from(product_discount_pct);

	// PHP line 1127: max(min(product_pct, maxProductDiscount), globalDiscountPct).
	let effective_discount = product_pct.min(max_product_discount).max(global_discount_pct);

	// Currency conversion step — PHP lines 1142–1151 apply rate + round per price independently.
	let mut price = apply_rate(hit.price, convert_ratio, prec);
	let mut price_vat = apply_rate(hit.price_vat, convert_ratio, prec);
	let mut price_before = apply_rate(hit.price_before, convert_ratio, prec);
	let mut price_vat_before = apply_rate(hit.price_vat_before, convert_ratio, prec);

	// Surcharge divisor — PHP lines 1153–1160. Only price/priceVat are affected (not priceBefore).
	if pricelist_meta.allow_surcharge && modifiers.surcharge_level_pct > 0.0 {
		let surcharge_divisor = 1.0 - (modifiers.surcharge_level_pct / 100.0);
		if surcharge_divisor > 0.0 {
			price = round_to_prec(price / surcharge_divisor, prec);
			price_vat = round_to_prec(price_vat / surcharge_divisor, prec);
		}
	}

	// Discount factor — PHP lines 1162–1171.
	if pricelist_meta.allow_discount_level && effective_discount > 0 {
		let discount_factor = f64::from(100 - effective_discount) / 100.0;
		price = round_to_prec(price * discount_factor, prec);
		price_vat = round_to_prec(price_vat * discount_factor, prec);

		// PHP line 1167: reverse-engineer priceBefore so the UI can display "was X, now Y".
		// Only when the DB column was null/0 — otherwise the explicit value wins.
		if price_before == 0.0 {
			price_before = round_to_prec(price / discount_factor, prec);
			price_vat_before = round_to_prec(price_vat / discount_factor, prec);
		}
	}

	PricedProduct {
		product,
		price,
		price_vat,
		price_before,
		price_vat_before,
		pricelist: hit.pricelist,
	}
}

/// Apply a currency rate with PHP-style rounding. `None` rate == identity (PHP `$convertRatio === null`).
#[inline]
#[must_use]
fn apply_rate(x: f64, rate: Option<f64>, prec: u8) -> f64 {
	match rate {
		None => x,
		Some(r) => round_to_prec(x * r, prec),
	}
}

/// Drop products outside the user's price band (`priceFrom`, `priceTo`, `priceGt`).
///
/// `priceFrom` / `priceTo` are inclusive; `priceGt` is **strict** (mirrors PHP `priceGt`
/// dynamic filter expression in `LiveProductsProvider::startUp`).
///
/// PHP parity: `priceFrom`/`priceTo`/`priceGt` compare against `priceVat` when `showVat` is true,
/// against `price` otherwise (see `ProductList.php:139-144`). `show_vat` picks the field.
pub fn apply_price_band(priced: Vec<PricedProduct>, filters: &FilterPayload, show_vat: bool) -> Vec<PricedProduct> {
	let lo = filters.price_from;
	let hi = filters.price_to;
	let gt = filters.price_gt;
	if lo.is_none() && hi.is_none() && gt.is_none() {
		return priced;
	}
	priced
		.into_iter()
		.filter(|p| {
			let compare = if show_vat { p.price_vat } else { p.price };
			lo.is_none_or(|v| compare >= v) && hi.is_none_or(|v| compare <= v) && gt.is_none_or(|v| compare > v)
		})
		.collect()
}
