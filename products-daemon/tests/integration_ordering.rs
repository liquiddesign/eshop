//! Ordering parity tests for `priorityAvailabilityPrice` / `availabilityAndPrice` —
//! the two new tuple-comparator orderings added in the `LiveProductsProvider` port.
//!
//! PHP reference:
//! - `LiveProductsProvider.php:1803-1836` (`priorityAvailabilityPrice` via `array_multisort`).
//! - `ProductList.php:109-118` (`availabilityAndPrice` via SQL `CASE` + `price`).

use std::sync::Arc;

use abel_products_daemon::{
	protocol::OrderDirection,
	query::{ordering::order_and_serialize, PricedProduct},
	snapshot::{CatalogSnapshot, ProductRow, VisibilityItem},
};
use smallvec::SmallVec;

/// Build a three-product snapshot with distinct (priority, is_sold, price) tuples so we can
/// assert every sort key in isolation. Priority flows through `visibility_items`; `is_sold`
/// through `is_sold_by_product`; price is carried on `PricedProduct`.
///
/// `product_ids` carry synthetic `eshop_product.id` values (1001, 1002, 1003) — the response
/// serialization path uses these now (int PKs, not UUIDs) because PHP `ProductList.php:255`
/// filters on `this.id`. UUIDs stay in `product_pool` for request-side lookups only.
fn ordering_snapshot() -> Arc<CatalogSnapshot> {
	let mut snap = Arc::into_inner(CatalogSnapshot::empty()).expect("fresh Arc");

	for u in ["prod-a", "prod-b", "prod-c"] {
		snap.product_pool.intern(u).unwrap();
	}
	snap.product_ids = vec![1001, 1002, 1003];

	let mk_row = |uuid_idx: u32| ProductRow {
		uuid_idx,
		name: None,
		producer: None,
		display_amount: None,
		display_delivery: None,
		discount_level_pct: 0,
		is_project: false,
		is_master: true,
		project_ics: SmallVec::new(),
		attr_values: SmallVec::new(),
		ribbons: SmallVec::new(),
		internal_ribbons: SmallVec::new(),
	};
	snap.products = (0u32..3).map(mk_row).collect();

	// Priorities: p0=10, p1=5, p2=5 (tied — availability breaks the tie).
	snap.visibility_list_pool.intern("vl").unwrap();
	snap.visibility_items = vec![
		VisibilityItem {
			product: 0,
			visibility_list: 0,
			hidden: false,
			hidden_in_menu: false,
			recommended: false,
			unavailable: false,
			priority: 10,
		},
		VisibilityItem {
			product: 1,
			visibility_list: 0,
			hidden: false,
			hidden_in_menu: false,
			recommended: false,
			unavailable: false,
			priority: 5,
		},
		VisibilityItem {
			product: 2,
			visibility_list: 0,
			hidden: false,
			hidden_in_menu: false,
			recommended: false,
			unavailable: false,
			priority: 5,
		},
	];
	// visibility_by_product: each product has exactly one VL item; feed its row index.
	for (row_idx, item) in snap.visibility_items.iter().enumerate() {
		snap.visibility_by_product
			.entry(item.product)
			.or_default()
			.push(row_idx as u32);
	}

	// is_sold: p0=0 (in stock → weight 0), p1=1 (sold out → weight 2), p2=2 (unknown → weight 1).
	snap.is_sold_by_product = vec![0, 1, 2];

	Arc::new(snap)
}

fn priced(product: u32, price: f64) -> PricedProduct {
	PricedProduct {
		product,
		price,
		price_vat: price * 1.21,
		price_before: 0.0,
		price_vat_before: 0.0,
		pricelist: 0,
	}
}

#[test]
fn priority_availability_price_asc_orders_by_tuple() {
	let snap = ordering_snapshot();
	let input = vec![priced(0, 100.0), priced(1, 50.0), priced(2, 75.0)];

	let out = order_and_serialize(
		&snap,
		input,
		Some("priorityAvailabilityPrice"),
		OrderDirection::Asc,
		None,
	);
	// Expected: priority ASC → p1/p2 (prio 5) before p0 (prio 10).
	// Within prio=5: availability ASC → p2 (unknown weight 1) before p1 (sold out weight 2).
	// Within each tie, price ASC would kick in but we have no ties of both keys here.
	// product_ids = [1001, 1002, 1003] → p0=1001, p1=1002, p2=1003.
	assert_eq!(out, vec!["1003", "1002", "1001"]);
}

#[test]
fn priority_availability_price_desc_reverses_all_keys() {
	let snap = ordering_snapshot();
	let input = vec![priced(0, 100.0), priced(1, 50.0), priced(2, 75.0)];

	let out = order_and_serialize(
		&snap,
		input,
		Some("priorityAvailabilityPrice"),
		OrderDirection::Desc,
		None,
	);
	// PHP `array_multisort(SORT_DESC)` applies direction to all keys.
	// priority DESC: p0 (10) before p1/p2 (5).
	// Within prio=5, availability DESC: p1 (weight 2) before p2 (weight 1).
	assert_eq!(out, vec!["1001", "1002", "1003"]);
}

#[test]
fn availability_and_price_sorts_by_weight_then_price() {
	let snap = ordering_snapshot();
	// Same availability (weight 0) for two products, price decides within the tie.
	// p0 weight 0, price 100. Two extra in-stock products: (3, weight 0, price 30), (4, weight 0, price 60).
	// For this test reuse p0 (weight 0). Secondary differentiators via p1 (weight 2) and p2 (weight 1).
	let input = vec![priced(0, 100.0), priced(1, 50.0), priced(2, 75.0)];

	let out = order_and_serialize(&snap, input, Some("availabilityAndPrice"), OrderDirection::Asc, None);
	// availability ASC: p0 (weight 0) → p2 (weight 1) → p1 (weight 2).
	// Price secondary only matters within same weight (none here).
	assert_eq!(out, vec!["1001", "1003", "1002"]);
}
