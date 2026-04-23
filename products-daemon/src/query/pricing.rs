//! Priority-first best-price resolution + discount / surcharge / currency modifiers.
//!
//! ## PHP parity reference
//! Aligned with `ProductRepository::sqlHandlePrice` semantics (the canonical SQL pricing in
//! `getProducts()`) — mirrors `LiveProductsProvider::computeEffectivePrices` after its own
//! alignment to SQL. Any divergence must be paired with an updated golden fixture in
//! `tests/fixtures/pricing/`.
//!
//! ## Algorithm
//! For each candidate product, walk its `prices_by_product[p]` slice (sorted by pricelist
//! priority **ASC** at snapshot-build time — lower number = higher precedence). Priority-first
//! selection with a price + UUID tie-break (SQL `LEAST(CONCAT_WS(priority, price, ..., uuid))`
//! semantics); then:
//!
//! 1. Apply `currency_rate` to all four prices (price, priceVat, priceBefore, priceVatBefore)
//!    via `round(x * rate, prec)` when rate is `Some` — matches SQL `ROUND(price * rate, prec)`
//!    inner ROUND in `sqlHandlePrice`.
//! 2. Compute `effective_discount = max(min(product_pct, max_product_discount_level), discount_level_pct)`.
//! 3. If `pricelist.allow_surcharge && surcharge_level_pct > 0`: `price /= (1 - pct/100)`
//!    — **no intermediate round**, matches SQL inline `/ (1-s/100)` in sqlHandlePrice.
//! 4. If `pricelist.allow_discount_level && effective_discount > 0`: `price *= (100 - eff) / 100`,
//!    single final round to `prec`. When no discount applies, still round once to match SQL
//!    `LPAD(CAST(x AS DECIMAL(n, prec)), ...)` in CONCAT_WS. If `price_before == 0`,
//!    reverse-engineer `price_before = price / discount_factor` (PHP semantic: "show the
//!    pre-discount price").
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
		let Some(hit) = pick_best_price(snap, slice, &set) else {
			continue;
		};

		out.push(apply_modifiers(snap, product, hit, modifiers));
	}
	Ok(out)
}

/// Given a product's prices (sorted priority ASC) and the customer's pricelist set,
/// return the winning `PriceFact` after priority-first selection with a price + UUID tie-break.
///
/// ## Tie-break
/// SQL `ProductRepository::sqlHandlePrice` builds one LPAD-serialized string per pricelist and
/// wraps them in `LEAST(...)`. Lexicographic LEAST over those strings means priority ASC first,
/// then price ASC, then `fk_pricelist` ASC (UUID). We replicate the same semantics: lower
/// priority wins; on priority tie, lower raw price wins; on price tie, lower pricelist UUID
/// wins (pure determinism). The price compared here is the raw DB value — full parity with SQL
/// would apply modifiers to each same-priority candidate, but raw price matches SQL whenever
/// the tied pricelists share `allow_discount_level` / `allow_surcharge` flags (typical config).
#[inline]
fn pick_best_price(
	snap: &CatalogSnapshot,
	prices: &[PriceFact],
	set: &ahash::AHashSet<PricelistIdx>,
) -> Option<PriceFact> {
	let mut best: Option<PriceFact> = None;
	let mut best_priority: i32 = i32::MAX;

	for p in prices {
		if p.flags.is_hidden() {
			continue;
		}
		if !set.contains(&p.pricelist) {
			continue;
		}

		let priority = snap
			.pricelists
			.get(p.pricelist as usize)
			.map_or(i32::MAX, |pm| pm.priority);

		// Prices per-product are sorted `(priority ASC)` at snapshot-build time. Once we see a
		// strictly higher priority than the current best, no further candidate can tie.
		if priority > best_priority {
			break;
		}

		match best {
			None => {
				best = Some(*p);
				best_priority = priority;
			}
			Some(current) => {
				// `priority > best_priority` was handled by `break` above, so here
				// `priority == best_priority` (strict `<` impossible since slice is sorted
				// and we've already seen a match at `best_priority`). Tie-break by price,
				// then by UUID for determinism.
				let p_wins = if p.price < current.price {
					true
				} else if (p.price - current.price).abs() < f64::EPSILON {
					let p_uuid = snap.pricelist_pool.get(u32::from(p.pricelist)).unwrap_or("");
					let c_uuid = snap.pricelist_pool.get(u32::from(current.pricelist)).unwrap_or("");
					p_uuid < c_uuid
				} else {
					false
				};

				if p_wins {
					best = Some(*p);
				}
			}
		}
	}
	best
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

	// Currency conversion step — inner `ROUND(raw * rate, prec)` matches SQL `sqlHandlePrice`:
	// rate step is the only place SQL rounds before the final outer ROUND/CAST.
	let mut price = apply_rate(hit.price, convert_ratio, prec);
	let mut price_vat = apply_rate(hit.price_vat, convert_ratio, prec);
	let mut price_before = apply_rate(hit.price_before, convert_ratio, prec);
	let mut price_vat_before = apply_rate(hit.price_vat_before, convert_ratio, prec);

	// Surcharge divisor — **no intermediate round**, matches SQL inline `$expression$surchargeExpression`
	// where the divide is a raw SQL expression (no ROUND wrap) and the outer ROUND/CAST handles
	// final precision. The previous `round(.../divisor, prec)` caused halíř-level divergence vs. SQL
	// (three rounds vs. SQL's two).
	if pricelist_meta.allow_surcharge && modifiers.surcharge_level_pct > 0.0 {
		let surcharge_divisor = 1.0 - (modifiers.surcharge_level_pct / 100.0);
		if surcharge_divisor > 0.0 {
			price /= surcharge_divisor;
			price_vat /= surcharge_divisor;
		}
	}

	// Discount — single round on the combined expression matches SQL
	// `ROUND($expression$surchargeExpression * ((100 - effDisc) / 100), $prec)` in sqlHandlePrice.
	// Use `* divisor / 100` (integer-first) instead of `* discount_factor` where
	// `discount_factor = (100-d)/100`. The factor form compounds f64 rounding: `discount_factor`
	// is inexact, and reversing via `/ discount_factor` produces a different f64 bit pattern
	// than multiplying by `100 / divisor`. Integer-first matches SQL DECIMAL arithmetic more
	// closely and keeps PHP parity on the reverse-engineered `priceBefore` edge case.
	if pricelist_meta.allow_discount_level && effective_discount > 0 {
		let discount_divisor = f64::from(100 - effective_discount);
		price = round_to_prec(price * discount_divisor / 100.0, prec);
		price_vat = round_to_prec(price_vat * discount_divisor / 100.0, prec);

		// Reverse-engineer priceBefore from the (now rounded) discounted price — matches SQL
		// `($priceSelect) * 100/(100 - effDisc)` branch byte-for-byte.
		if price_before == 0.0 {
			price_before = round_to_prec(price * 100.0 / discount_divisor, prec);
			price_vat_before = round_to_prec(price_vat * 100.0 / discount_divisor, prec);
		}
	} else {
		// No discount path: SQL stores `$expression$surchargeExpression` into
		// `LPAD(CAST(x AS DECIMAL($priceLpad, $prec)), ...)` in CONCAT_WS, which rounds to `$prec`
		// places. Explicit round here mirrors that CAST so surcharge-only prices don't keep raw
		// f64 precision that would diverge from SQL output.
		price = round_to_prec(price, prec);
		price_vat = round_to_prec(price_vat, prec);
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
