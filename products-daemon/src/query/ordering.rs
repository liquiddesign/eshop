//! Ordering: priority / name / price.
//!
//! ## PHP parity reference
//! - `LiveProductsProvider::$allowedCollectionOrderColumns` (priority | price | name).
//! - Custom expressions registered via `addCollectionOrderExpression` (e.g. `uuidField` for
//!   the assistant order flow) are **not** supported by the daemon — handler returns
//!   `fallback_required: true` before reaching ordering.

use std::sync::Arc;

use crate::{
	protocol::OrderDirection,
	query::PricedProduct,
	snapshot::{CatalogSnapshot, VisibilityItem},
};

/// Sort in place and serialize product indexes to their wire PKs.
///
/// Returns the ordered list of stringified `eshop_product.id` values ready to embed in
/// `GetProductsResponse::product_pks`. PHP `ProductList.php:255` does
/// `$source->where('this.id', $pagedProducts)` — `this.id` is the INT auto-increment PK, not
/// the UUID. Stringified int matches LiveProductsProvider fallback (`(string) $product->id`).
pub fn order_and_serialize(
	snap: &Arc<CatalogSnapshot>,
	mut priced: Vec<PricedProduct>,
	order_by: Option<&str>,
	direction: OrderDirection,
	order_uuids: Option<&[String]>,
) -> Vec<String> {
	match order_by.unwrap_or("priority") {
		"uuidField" if order_uuids.is_some_and(|u| !u.is_empty()) => {
			sort_by_uuid_field(snap, &mut priced, order_uuids.unwrap());
		}
		"priority" => sort_by_priority(snap, &mut priced, direction),
		"price" => sort_by_price(&mut priced, direction),
		"name" => sort_by_name(snap, &mut priced, direction),
		"priorityAvailabilityPrice" => sort_by_priority_availability_price(snap, &mut priced, direction),
		"availabilityAndPrice" => sort_by_availability_and_price(snap, &mut priced, direction),
		_ => sort_by_priority(snap, &mut priced, direction),
	}

	priced
		.into_iter()
		.filter_map(|p| snap.product_ids.get(p.product as usize).map(|id| id.to_string()))
		.collect()
}

/// PHP `FIELD(this.product, 'uuid1', 'uuid2', …)` ekvivalent. Seřadí priced podle pozice UUID
/// v `order_uuids`. Produkty, jejichž UUID není v seznamu, sort na konec (ASC order).
fn sort_by_uuid_field(snap: &CatalogSnapshot, priced: &mut [PricedProduct], order_uuids: &[String]) {
	let rank_by_uuid: ahash::AHashMap<&str, usize> =
		order_uuids.iter().enumerate().map(|(i, u)| (u.as_str(), i)).collect();

	priced.sort_unstable_by_key(|p| {
		let uuid = snap.product_pool.get(p.product).unwrap_or("");
		rank_by_uuid.get(uuid).copied().unwrap_or(usize::MAX)
	});
}

fn sort_by_priority(snap: &CatalogSnapshot, priced: &mut [PricedProduct], direction: OrderDirection) {
	// PHP default: `visibilityListItem.priority ASC` — products explicitly promoted in the VL.
	// Fallback for products without a visibility item: priority = i32::MAX so they sort last.
	priced.sort_unstable_by(|a, b| {
		let pa = visibility_priority_for(snap, a.product);
		let pb = visibility_priority_for(snap, b.product);
		match direction {
			OrderDirection::Asc => pa.cmp(&pb),
			OrderDirection::Desc => pb.cmp(&pa),
		}
	});
}

fn sort_by_price(priced: &mut [PricedProduct], direction: OrderDirection) {
	priced.sort_unstable_by(|a, b| {
		// f64::total_cmp is NaN-safe; price with NaN would be a snapshot bug, but we bias NaNs
		// to the end regardless of direction so they can't escape into the response head.
		match direction {
			OrderDirection::Asc => a.price.total_cmp(&b.price),
			OrderDirection::Desc => b.price.total_cmp(&a.price),
		}
	});
}

fn sort_by_name(snap: &CatalogSnapshot, priced: &mut [PricedProduct], direction: OrderDirection) {
	// Products without a localized name fall back to their UUID — keeps ordering deterministic
	// for draft/admin states where `name_cs` is still NULL. PHP does the same via MySQL
	// `ORDER BY name ASC` treating NULL as greater than any string value.
	priced.sort_unstable_by(|a, b| {
		let na = snap
			.products
			.get(a.product as usize)
			.and_then(|p| p.name.as_deref())
			.unwrap_or_else(|| snap.product_pool.get(a.product).unwrap_or(""));
		let nb = snap
			.products
			.get(b.product as usize)
			.and_then(|p| p.name.as_deref())
			.unwrap_or_else(|| snap.product_pool.get(b.product).unwrap_or(""));
		match direction {
			OrderDirection::Asc => na.cmp(nb),
			OrderDirection::Desc => nb.cmp(na),
		}
	});
}

fn visibility_priority_for(snap: &CatalogSnapshot, product: u32) -> i32 {
	// First row in `visibility_by_product[product]` wins (snapshot loader preserves VL priority
	// ordering). If the product has no VL membership, treat it as maximally deprioritized.
	snap.visibility_by_product
		.get(&product)
		.and_then(|ids| ids.first())
		.and_then(|id| snap.visibility_items.get(*id as usize))
		.map(|v: &VisibilityItem| v.priority)
		.unwrap_or(i32::MAX)
}

/// PHP `array_multisort(priorities, availability_weights, prices)` — default "Doporučujeme"
/// ordering (`LiveProductsProvider.php:1803-1836`). Tuple comparator; `direction` applies to
/// all three keys identically (both `SORT_ASC` or both `SORT_DESC`).
///
/// Availability weight mapping (PHP line 1813-1817):
/// - `isSold = 0` → weight `0` (in stock sorts first)
/// - `isSold = 2` → weight `1` (unknown sorts middle)
/// - any other   → weight `2` (sold out sorts last)
///
/// Price key uses `price` (not `priceVat`) — mirrors PHP line 1818.
fn sort_by_priority_availability_price(
	snap: &CatalogSnapshot,
	priced: &mut [PricedProduct],
	direction: OrderDirection,
) {
	priced.sort_unstable_by(|a, b| {
		let key_a = availability_tuple(snap, a);
		let key_b = availability_tuple(snap, b);
		match direction {
			OrderDirection::Asc => cmp_availability_tuple(&key_a, &key_b),
			OrderDirection::Desc => cmp_availability_tuple(&key_b, &key_a),
		}
	});
}

/// PHP `availabilityAndPrice` expression (`ProductList.php:109-118`). Tuple `(availability_weight, price)`
/// with both keys sharing the same direction.
fn sort_by_availability_and_price(snap: &CatalogSnapshot, priced: &mut [PricedProduct], direction: OrderDirection) {
	priced.sort_unstable_by(|a, b| {
		let wa = availability_weight(snap, a.product);
		let wb = availability_weight(snap, b.product);
		let primary = match direction {
			OrderDirection::Asc => wa.cmp(&wb),
			OrderDirection::Desc => wb.cmp(&wa),
		};
		if primary != std::cmp::Ordering::Equal {
			return primary;
		}
		match direction {
			OrderDirection::Asc => a.price.total_cmp(&b.price),
			OrderDirection::Desc => b.price.total_cmp(&a.price),
		}
	});
}

#[inline]
fn availability_tuple(snap: &CatalogSnapshot, p: &PricedProduct) -> (i32, u8, f64) {
	let priority = visibility_priority_for(snap, p.product);
	let weight = availability_weight(snap, p.product);
	(priority, weight, p.price)
}

#[inline]
fn cmp_availability_tuple(a: &(i32, u8, f64), b: &(i32, u8, f64)) -> std::cmp::Ordering {
	a.0.cmp(&b.0)
		.then_with(|| a.1.cmp(&b.1))
		.then_with(|| a.2.total_cmp(&b.2))
}

/// Map raw `is_sold` (0/1/2/…) to availability weight (0 = best, 2 = worst) matching PHP
/// `match ((int) ($product->displayAmount_isSold ?? 2)) { 0 => 0, 2 => 1, default => 2 }`.
#[inline]
fn availability_weight(snap: &CatalogSnapshot, product: u32) -> u8 {
	let raw = snap.is_sold_by_product.get(product as usize).copied().unwrap_or(2);
	match raw {
		0 => 0,
		2 => 1,
		_ => 2,
	}
}
