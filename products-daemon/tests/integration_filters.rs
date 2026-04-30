//! Filter-expression parity tests — new filters added in the PHP `LiveProductsProvider` port
//! (price_gt, ribbon AND, not_ribbon, internal_ribbon, uuids subset, isSold).
//!
//! Exercises `filter::apply_bitmap_filters` + `pricing::apply_price_band` on a hand-rolled
//! snapshot. Reference behavior: PHP `LiveProductsProvider::startUp` registration of
//! `allowedCollectionFilterExpressions` / `allowedDynamicFilterExpressions`.

use std::sync::Arc;

use abel_products_daemon::{
	protocol::{
		CrossSellFilter, FilterPayload, GetProductsRequest, OrderDirection, PriceModifiers, PriceVisibility,
		RelatedFilter, RelatedSlaveFilter,
	},
	query::{
		filter::{apply_bitmap_filters, base_mask},
		pricing::apply_price_band,
		PricedProduct,
	},
	snapshot::{
		bitmaps::BitmapIndex, CatalogSnapshot, CategoryNode, PriceFact, PriceFactFlags, PricelistIdx, PricelistMeta,
		ProductRow,
	},
};
use roaring::RoaringBitmap;
use smallvec::SmallVec;
use smol_str::SmolStr;

/// Build a six-product snapshot covering every filter dimension we care about:
/// - 3 ribbons (r0, r1, r2) and 2 internal ribbons (ir0, ir1)
/// - products carry different combinations
/// - `is_sold_by_product` varies across 0 (in stock) / 1 (sold out) / 2 (unknown)
/// - `product_pool` is populated so `uuids` filter can resolve PKs
fn build_test_snapshot() -> Arc<CatalogSnapshot> {
	let mut snap = Arc::into_inner(CatalogSnapshot::empty()).expect("fresh Arc");

	// --- intern product PKs so the `uuids` filter can resolve them ---
	let p_uuids = ["prod-0", "prod-1", "prod-2", "prod-3", "prod-4", "prod-5"];
	for u in &p_uuids {
		snap.product_pool.intern(u).unwrap();
	}

	// --- ribbons / internal_ribbons pool setup ---
	let r0 = snap.ribbon_pool.intern("r0").unwrap();
	let r1 = snap.ribbon_pool.intern("r1").unwrap();
	let r2 = snap.ribbon_pool.intern("r2").unwrap();
	let ir0 = snap.internal_ribbon_pool.intern("ir0").unwrap();
	let ir1 = snap.internal_ribbon_pool.intern("ir1").unwrap();

	// --- product ribbon membership (bitmaps + product rows) ---
	// p0: r0, r1           p1: r0, r2           p2: r1
	// p3: r0, r1, r2       p4: (no ribbons)     p5: r2
	let mut ribbon_bitmaps = BitmapIndex::new();
	let ribbons_per_product: [Vec<u32>; 6] = [vec![r0, r1], vec![r0, r2], vec![r1], vec![r0, r1, r2], vec![], vec![r2]];
	for (product, rs) in ribbons_per_product.iter().enumerate() {
		for r in rs {
			ribbon_bitmaps.insert(*r, product as u32);
		}
	}
	snap.ribbon_bitmaps = ribbon_bitmaps;

	let mut internal_ribbon_bitmaps = BitmapIndex::new();
	// p0: ir0      p1: ir0, ir1      p2: (none)       p3: ir1       p4: (none)      p5: ir0
	let iribbons_per_product: [Vec<u32>; 6] = [vec![ir0], vec![ir0, ir1], vec![], vec![ir1], vec![], vec![ir0]];
	for (product, rs) in iribbons_per_product.iter().enumerate() {
		for r in rs {
			internal_ribbon_bitmaps.insert(*r, product as u32);
		}
	}
	snap.internal_ribbon_bitmaps = internal_ribbon_bitmaps;

	// --- product rows (mirror ribbons into ProductRow for consistency, although filter path
	// uses the bitmaps) ---
	let mk_row = |uuid_idx: u32, ribbons: &[u32], internal_ribbons: &[u32]| ProductRow {
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
		ribbons: ribbons.iter().copied().collect(),
		internal_ribbons: internal_ribbons.iter().copied().collect(),
		buy_count: 0,
		published: 0,
	};
	snap.products = (0u32..6)
		.map(|i| mk_row(i, &ribbons_per_product[i as usize], &iribbons_per_product[i as usize]))
		.collect();

	// --- is_sold: p0=0(in stock), p1=1(sold out), p2=2(unknown), p3=0, p4=1, p5=2 ---
	snap.is_sold_by_product = vec![0, 1, 2, 0, 1, 2];

	// --- all_products_mask = {0..6} ---
	for i in 0..6u32 {
		snap.all_products_mask.insert(i);
	}

	// --- a single pricelist + a price per product so pricing pipeline has something to anchor ---
	snap.pricelists.push(PricelistMeta {
		idx: 0,
		priority: 0,
		rate: 1.0,
		allow_discount_level: false,
		allow_surcharge: false,
		is_active: true,
		has_customer_binding: true,
	});
	// Prices vary so `price_gt` filter has spread: 50, 100, 150, 200, 250, 300.
	snap.prices = (0..6u32)
		.map(|i| {
			let price = 50.0 + f64::from(i) * 50.0;
			PriceFact::new(i, 0, PriceFactFlags::empty(), price, price * 1.21, 0.0, 0.0)
		})
		.collect();
	snap.prices_by_product = (0..6).map(|i| (i as u32)..((i + 1) as u32)).collect();

	Arc::new(snap)
}

fn base_req() -> GetProductsRequest {
	GetProductsRequest {
		pricelist_pks: Vec::new(),
		visibility_list_pks: Vec::new(),
		filters: FilterPayload::default(),
		dynamic_filter_attributes: None,
		price_modifiers: PriceModifiers::default(),
		order_by: None,
		order_direction: OrderDirection::Asc,
		count_categories: false,
		debug: false,
		order_uuids: None,
		favourite_pricelist_pks: Vec::new(),
		contract_ribbon_pk: None,
		not_public_ribbon_pk: None,
		project_filter: None,
		price_visibility: PriceVisibility::default(),
	}
}

fn mask_as_vec(mask: &RoaringBitmap) -> Vec<u32> {
	mask.iter().collect()
}

fn req_with_filters(filters: FilterPayload) -> GetProductsRequest {
	let mut req = base_req();
	req.filters = filters;
	req
}

#[test]
fn ribbon_uuids_requires_all_listed_ribbons() {
	// `ribbon_uuids=[r0, r1]` (AND) must return only p0 and p3.
	let snap = build_test_snapshot();
	let req = req_with_filters(FilterPayload {
		ribbon_uuids: Some(vec!["r0".into(), "r1".into()]),
		..Default::default()
	});

	let mask = base_mask(&snap, &[]).unwrap();
	let filtered = apply_bitmap_filters(&snap, mask, &req).unwrap();
	assert_eq!(
		mask_as_vec(&filtered),
		vec![0, 3],
		"only p0 and p3 carry both r0 and r1"
	);
}

#[test]
fn not_ribbon_uuids_drops_matching_products() {
	// `not_ribbon_uuids=[r0]` must drop everyone with r0: p0, p1, p3.
	let snap = build_test_snapshot();
	let req = req_with_filters(FilterPayload {
		not_ribbon_uuids: Some(vec!["r0".into()]),
		..Default::default()
	});

	let mask = base_mask(&snap, &[]).unwrap();
	let filtered = apply_bitmap_filters(&snap, mask, &req).unwrap();
	assert_eq!(mask_as_vec(&filtered), vec![2, 4, 5]);
}

#[test]
fn internal_ribbon_uuids_ands_across() {
	// `internal_ribbon_uuids=[ir0, ir1]` — only p1 has both.
	let snap = build_test_snapshot();
	let req = req_with_filters(FilterPayload {
		internal_ribbon_uuids: Some(vec!["ir0".into(), "ir1".into()]),
		..Default::default()
	});

	let mask = base_mask(&snap, &[]).unwrap();
	let filtered = apply_bitmap_filters(&snap, mask, &req).unwrap();
	assert_eq!(mask_as_vec(&filtered), vec![1]);
}

#[test]
fn uuids_restricts_to_subset() {
	// `uuids=[prod-1, prod-4]` + unknown `prod-99` → only p1 and p4 (unknown silently dropped).
	let snap = build_test_snapshot();
	let req = req_with_filters(FilterPayload {
		uuids: Some(vec!["prod-1".into(), "prod-4".into(), "prod-99".into()]),
		..Default::default()
	});

	let mask = base_mask(&snap, &[]).unwrap();
	let filtered = apply_bitmap_filters(&snap, mask, &req).unwrap();
	assert_eq!(mask_as_vec(&filtered), vec![1, 4]);
}

#[test]
fn is_sold_filter_matches_availability_weight() {
	// isSold=1 → sold-out products: p1 and p4.
	let snap = build_test_snapshot();
	let req = req_with_filters(FilterPayload {
		is_sold: Some(1),
		..Default::default()
	});

	let mask = base_mask(&snap, &[]).unwrap();
	let filtered = apply_bitmap_filters(&snap, mask, &req).unwrap();
	assert_eq!(mask_as_vec(&filtered), vec![1, 4]);
}

#[test]
fn master_product_true_keeps_only_masters() {
	// `masterProduct=true` → only master products (p0, p2, p4 = masters; p1, p3, p5 = slaves).
	let mut snap = Arc::into_inner(build_test_snapshot()).expect("fresh Arc");
	for (i, row) in snap.products.iter_mut().enumerate() {
		row.is_master = i % 2 == 0;
	}
	snap.master_mask.clear();
	for i in [0u32, 2, 4] {
		snap.master_mask.insert(i);
	}
	let snap = Arc::new(snap);

	let req = req_with_filters(FilterPayload {
		master_product: Some(true),
		..Default::default()
	});

	let mask = base_mask(&snap, &[]).unwrap();
	let filtered = apply_bitmap_filters(&snap, mask, &req).unwrap();
	assert_eq!(mask_as_vec(&filtered), vec![0, 2, 4]);
}

#[test]
fn master_product_false_keeps_only_slaves() {
	let mut snap = Arc::into_inner(build_test_snapshot()).expect("fresh Arc");
	for (i, row) in snap.products.iter_mut().enumerate() {
		row.is_master = i % 2 == 0;
	}
	snap.master_mask.clear();
	for i in [0u32, 2, 4] {
		snap.master_mask.insert(i);
	}
	let snap = Arc::new(snap);

	let req = req_with_filters(FilterPayload {
		master_product: Some(false),
		..Default::default()
	});

	let mask = base_mask(&snap, &[]).unwrap();
	let filtered = apply_bitmap_filters(&snap, mask, &req).unwrap();
	assert_eq!(mask_as_vec(&filtered), vec![1, 3, 5]);
}

#[test]
fn unknown_ribbon_drops_all_when_required() {
	// Required ribbon not present in the pool ⇒ result is empty.
	let snap = build_test_snapshot();
	let req = req_with_filters(FilterPayload {
		ribbon_uuids: Some(vec!["r0".into(), "r-does-not-exist".into()]),
		..Default::default()
	});

	let mask = base_mask(&snap, &[]).unwrap();
	let filtered = apply_bitmap_filters(&snap, mask, &req).unwrap();
	assert!(
		filtered.is_empty(),
		"unknown ribbon forces empty result; got: {:?}",
		mask_as_vec(&filtered)
	);
}

// --- price_gt (strict greater-than) -------------------------------------------------------

fn priced_fixture() -> Vec<PricedProduct> {
	// price = 50 + i*50, price_vat = price * 1.21 (matches build_test_snapshot fixture).
	(0u32..6)
		.map(|i| {
			let price = 50.0 + f64::from(i) * 50.0;
			PricedProduct {
				product: i,
				price,
				price_vat: price * 1.21,
				price_before: 0.0,
				price_vat_before: 0.0,
				pricelist: 0,
			}
		})
		.collect()
}

#[test]
fn price_gt_is_strict_and_drops_equals() {
	// show_vat=false → compare p.price; priceGt=100 → strict > 100 → keeps p2..p5.
	let priced = priced_fixture();
	let filters = FilterPayload {
		price_gt: Some(100.0),
		..Default::default()
	};

	let filtered = apply_price_band(priced, &filters, false);
	let ids: Vec<u32> = filtered.iter().map(|p| p.product).collect();
	assert_eq!(
		ids,
		vec![2, 3, 4, 5],
		"priceGt=100 strict excludes p1 (price=100); got {ids:?}"
	);
}

#[test]
fn price_gt_uses_price_vat_when_show_vat_true() {
	// show_vat=true → compare price_vat. priceGt=242.0 → keeps products where price_vat > 242.0.
	// Prices: p0=50 vat=60.5, p1=100 vat=121, p2=150 vat=181.5, p3=200 vat=242,
	// p4=250 vat=302.5, p5=300 vat=363.
	let priced = priced_fixture();
	let filters = FilterPayload {
		price_gt: Some(242.0),
		..Default::default()
	};

	let filtered = apply_price_band(priced, &filters, true);
	let ids: Vec<u32> = filtered.iter().map(|p| p.product).collect();
	assert_eq!(
		ids,
		vec![4, 5],
		"priceGt=242.0 on price_vat keeps only p4 (302.5) and p5 (363)"
	);
}

#[test]
fn price_from_is_inclusive() {
	// Baseline sanity: priceFrom=150 (inclusive) keeps p2..p5.
	let priced = priced_fixture();
	let filters = FilterPayload {
		price_from: Some(150.0),
		..Default::default()
	};

	let filtered = apply_price_band(priced, &filters, false);
	let ids: Vec<u32> = filtered.iter().map(|p| p.product).collect();
	assert_eq!(ids, vec![2, 3, 4, 5]);
}

// --- has_any_price_mask + PriceVisibility cascade ----------------------------------------

fn mini_price_snapshot(price: f64, price_vat: f64) -> Arc<CatalogSnapshot> {
	let mut snap = Arc::into_inner(CatalogSnapshot::empty()).expect("fresh Arc");
	snap.product_pool.intern("prod-0").unwrap();
	snap.pricelists.push(PricelistMeta {
		idx: 0,
		priority: 0,
		rate: 1.0,
		allow_discount_level: false,
		allow_surcharge: false,
		is_active: true,
		has_customer_binding: true,
	});
	snap.products.push(ProductRow {
		uuid_idx: 0,
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
		buy_count: 0,
		published: 0,
	});
	snap.is_sold_by_product = vec![2];
	snap.all_products_mask.insert(0);
	snap.prices.push(PriceFact::new(
		0,
		0,
		PriceFactFlags::empty(),
		price,
		price_vat,
		0.0,
		0.0,
	));
	snap.prices_by_product.push(0..1);
	Arc::new(snap)
}

#[test]
fn zero_pricevat_drops_product_when_show_vat_is_default() {
	// Default PriceVisibility: show_vat=true, show_zero_prices=false → require priceVat > 0.
	// PHP parity: `setProductsConditions` arm 2 when only showVat is true.
	use abel_products_daemon::query::filter::has_any_price_mask;

	let snap = mini_price_snapshot(100.0, 0.0);
	let vis = PriceVisibility::default();
	let pricelists: Vec<PricelistIdx> = vec![0];

	let mask = has_any_price_mask(&snap, &pricelists, vis);
	assert!(mask.is_empty(), "priceVat=0 with showVat default must drop product");
}

#[test]
fn zero_price_allowed_when_show_zero_prices_true() {
	use abel_products_daemon::query::filter::has_any_price_mask;

	let snap = mini_price_snapshot(0.0, 0.0);
	let vis = PriceVisibility {
		show_zero_prices: true,
		..PriceVisibility::default()
	};
	let pricelists: Vec<PricelistIdx> = vec![0];

	let mask = has_any_price_mask(&snap, &pricelists, vis);
	assert_eq!(mask_as_vec(&mask), vec![0]);
}

#[test]
fn cascade_bug_both_vat_flags_checks_only_price() {
	// PHP cascade quirk: when `show_vat && show_without_vat`, arm 3 overwrites arm 1.
	// Product has priceVat=0 but price=100 → passes (only price>0 is checked).
	use abel_products_daemon::query::filter::has_any_price_mask;

	let snap = mini_price_snapshot(100.0, 0.0);
	let vis = PriceVisibility {
		show_zero_prices: false,
		show_vat: true,
		show_without_vat: true,
		include_hidden_prices: false,
	};
	let pricelists: Vec<PricelistIdx> = vec![0];

	let mask = has_any_price_mask(&snap, &pricelists, vis);
	assert_eq!(
		mask_as_vec(&mask),
		vec![0],
		"PHP cascade: showVat && showWithoutVat → only price>0 check"
	);
}

// --- inStock (filterInStock) -------------------------------------------------------------

/// Snapshot 4 products:
/// p0: display_amount=Some(DA0) is_sold=0 (in stock) → pass
/// p1: display_amount=Some(DA1) is_sold=1 (sold out) → drop
/// p2: display_amount=Some(DA2) is_sold=2 (unknown)  → drop
/// p3: display_amount=None                           → pass (PHP `fk_displayAmount IS NULL`)
fn build_in_stock_snapshot() -> std::sync::Arc<CatalogSnapshot> {
	let mut snap = std::sync::Arc::into_inner(CatalogSnapshot::empty()).expect("fresh Arc");
	for u in &["prod-0", "prod-1", "prod-2", "prod-3"] {
		snap.product_pool.intern(u).unwrap();
	}
	let mk_row = |uuid_idx: u32, display_amount: Option<u32>| ProductRow {
		uuid_idx,
		name: None,
		producer: None,
		display_amount,
		display_delivery: None,
		discount_level_pct: 0,
		is_project: false,
		is_master: true,
		project_ics: SmallVec::new(),
		attr_values: SmallVec::new(),
		ribbons: SmallVec::new(),
		internal_ribbons: SmallVec::new(),
		buy_count: 0,
		published: 0,
	};
	snap.products = vec![
		mk_row(0, Some(100)),
		mk_row(1, Some(101)),
		mk_row(2, Some(102)),
		mk_row(3, None),
	];
	snap.is_sold_by_product = vec![0, 1, 2, 2];
	for i in 0..4u32 {
		snap.all_products_mask.insert(i);
	}
	std::sync::Arc::new(snap)
}

#[test]
fn in_stock_filter_keeps_in_stock_and_null_display_amount() {
	let snap = build_in_stock_snapshot();
	let req = req_with_filters(FilterPayload {
		in_stock: Some(true),
		..Default::default()
	});
	let mask = base_mask(&snap, &[]).unwrap();
	let filtered = apply_bitmap_filters(&snap, mask, &req).unwrap();
	assert_eq!(
		mask_as_vec(&filtered),
		vec![0, 3],
		"pass: in-stock (p0) and NULL displayAmount (p3); drop: sold-out (p1) and unknown (p2)"
	);
}

#[test]
fn in_stock_filter_off_when_none_or_false() {
	let snap = build_in_stock_snapshot();
	for val in [None, Some(false)] {
		let req = req_with_filters(FilterPayload {
			in_stock: val,
			..Default::default()
		});
		let mask = base_mask(&snap, &[]).unwrap();
		let filtered = apply_bitmap_filters(&snap, mask, &req).unwrap();
		assert_eq!(
			mask_as_vec(&filtered),
			vec![0, 1, 2, 3],
			"in_stock={val:?} must be no-op"
		);
	}
}

// --- related (primary category match) ---------------------------------------------------

#[test]
fn related_filter_matches_primary_category_and_excludes_self() {
	let mut snap = std::sync::Arc::into_inner(CatalogSnapshot::empty()).expect("fresh Arc");
	for u in &["prod-0", "prod-1", "prod-2", "prod-3"] {
		snap.product_pool.intern(u).unwrap();
	}
	for i in 0..4u32 {
		snap.all_products_mask.insert(i);
	}
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
		buy_count: 0,
		published: 0,
	};
	snap.products = (0u32..4).map(mk_row).collect();

	// p0, p1, p2 mají pod type=T1 primární kategorii cat-A; p3 nemá tu kategorii.
	let mut bm = roaring::RoaringBitmap::new();
	for i in [0u32, 1, 2] {
		bm.insert(i);
	}
	snap.primary_category_by_type_cat
		.insert((SmolStr::new("T1"), SmolStr::new("cat-A")), bm);

	let snap = std::sync::Arc::new(snap);
	let req = req_with_filters(FilterPayload {
		related: Some(RelatedFilter {
			exclude_uuid: "prod-1".into(),
			primary_category_uuid: "cat-A".into(),
			main_category_type_pk: "T1".into(),
		}),
		..Default::default()
	});
	let mask = base_mask(&snap, &[]).unwrap();
	let filtered = apply_bitmap_filters(&snap, mask, &req).unwrap();
	assert_eq!(
		mask_as_vec(&filtered),
		vec![0, 2],
		"p1 excluded, p3 not in primary-category set"
	);
}

#[test]
fn related_filter_unknown_primary_category_is_empty() {
	let snap = std::sync::Arc::new(std::sync::Arc::into_inner(CatalogSnapshot::empty()).expect("fresh"));
	let req = req_with_filters(FilterPayload {
		related: Some(RelatedFilter {
			exclude_uuid: "x".into(),
			primary_category_uuid: "unknown".into(),
			main_category_type_pk: "T1".into(),
		}),
		..Default::default()
	});
	let mask = base_mask(&snap, &[]).unwrap();
	let filtered = apply_bitmap_filters(&snap, mask, &req).unwrap();
	assert!(filtered.is_empty());
}

// --- relatedSlave (eshop_related lookup) ------------------------------------------------

#[test]
fn related_slave_filter_matches_type_master_pair() {
	let mut snap = std::sync::Arc::into_inner(CatalogSnapshot::empty()).expect("fresh Arc");
	for u in &["prod-0", "prod-1", "prod-2"] {
		snap.product_pool.intern(u).unwrap();
	}
	for i in 0..3u32 {
		snap.all_products_mask.insert(i);
	}
	let mk_row = |i: u32| ProductRow {
		uuid_idx: i,
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
		buy_count: 0,
		published: 0,
	};
	snap.products = (0u32..3).map(mk_row).collect();

	let mut bm = roaring::RoaringBitmap::new();
	bm.insert(1);
	bm.insert(2);
	snap.related_slaves_by_type_master
		.insert((SmolStr::new("type-TONER"), SmolStr::new("master-X")), bm);

	let snap = std::sync::Arc::new(snap);
	let req = req_with_filters(FilterPayload {
		related_slave: Some(RelatedSlaveFilter {
			type_uuid: "type-TONER".into(),
			master_uuid: "master-X".into(),
		}),
		..Default::default()
	});
	let mask = base_mask(&snap, &[]).unwrap();
	let filtered = apply_bitmap_filters(&snap, mask, &req).unwrap();
	assert_eq!(mask_as_vec(&filtered), vec![1, 2]);
}

#[test]
fn related_slave_unknown_pair_is_empty() {
	let snap = std::sync::Arc::new(std::sync::Arc::into_inner(CatalogSnapshot::empty()).expect("fresh"));
	let req = req_with_filters(FilterPayload {
		related_slave: Some(RelatedSlaveFilter {
			type_uuid: "nope".into(),
			master_uuid: "nope".into(),
		}),
		..Default::default()
	});
	let mask = base_mask(&snap, &[]).unwrap();
	let filtered = apply_bitmap_filters(&snap, mask, &req).unwrap();
	assert!(filtered.is_empty());
}

// --- crossSellFilter (category.path suffix OR-chain) -----------------------------------

#[test]
fn cross_sell_filter_matches_path_chunks_and_excludes_self() {
	let mut snap = std::sync::Arc::into_inner(CatalogSnapshot::empty()).expect("fresh Arc");
	for u in &["prod-0", "prod-1", "prod-2", "prod-3"] {
		snap.product_pool.intern(u).unwrap();
	}
	for i in 0..4u32 {
		snap.all_products_mask.insert(i);
	}
	let mk_row = |i: u32| ProductRow {
		uuid_idx: i,
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
		buy_count: 0,
		published: 0,
	};
	snap.products = (0u32..4).map(mk_row).collect();

	// Dvě kategorie: cat-A path="AABB", cat-B path="AABBCCDD". Produkty v kategoriích:
	// cat-A: p0, p1; cat-B: p2. p3 není v žádné.
	let cat_a = snap.category_pool.intern("cat-A").unwrap();
	let cat_b = snap.category_pool.intern("cat-B").unwrap();
	snap.category_bitmaps.insert(cat_a, 0);
	snap.category_bitmaps.insert(cat_a, 1);
	snap.category_bitmaps.insert(cat_b, 2);
	snap.categories = vec![
		CategoryNode {
			idx: cat_a,
			parent: None,
			path: SmolStr::new("AABB"),
			descendants: roaring::RoaringBitmap::new(),
			show_descendant_products: true,
			show_products_in_ancestors: true,
		},
		CategoryNode {
			idx: cat_b,
			parent: None,
			path: SmolStr::new("AABBCCDD"),
			descendants: roaring::RoaringBitmap::new(),
			show_descendant_products: true,
			show_products_in_ancestors: true,
		},
	];
	// Suffix index: "AABB" → [cat_a, cat_b? no — cat_b suffix is "CCDD"]; "CCDD" → [cat_b].
	snap.categories_by_path_suffix
		.insert(SmolStr::new("AABB"), [cat_a].into_iter().collect());
	snap.categories_by_path_suffix
		.insert(SmolStr::new("CCDD"), [cat_b].into_iter().collect());

	let snap = std::sync::Arc::new(snap);
	// path="AABBCCDD" → chunks ["AABB","CCDD"] → union cat_a + cat_b produkty = {0,1,2}.
	// exclude prod-1 → výsledek {0,2}.
	let req = req_with_filters(FilterPayload {
		cross_sell: Some(CrossSellFilter {
			path: "AABBCCDD".into(),
			exclude_uuid: "prod-1".into(),
		}),
		..Default::default()
	});
	let mask = base_mask(&snap, &[]).unwrap();
	let filtered = apply_bitmap_filters(&snap, mask, &req).unwrap();
	assert_eq!(mask_as_vec(&filtered), vec![0, 2]);
}

#[test]
fn cross_sell_filter_single_chunk_path() {
	let mut snap = std::sync::Arc::into_inner(CatalogSnapshot::empty()).expect("fresh Arc");
	for u in &["prod-0", "prod-1"] {
		snap.product_pool.intern(u).unwrap();
	}
	for i in 0..2u32 {
		snap.all_products_mask.insert(i);
	}
	let mk_row = |i: u32| ProductRow {
		uuid_idx: i,
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
		buy_count: 0,
		published: 0,
	};
	snap.products = (0u32..2).map(mk_row).collect();
	let cat = snap.category_pool.intern("cat-X").unwrap();
	snap.category_bitmaps.insert(cat, 0);
	snap.categories_by_path_suffix
		.insert(SmolStr::new("ZZZZ"), [cat].into_iter().collect());
	let snap = std::sync::Arc::new(snap);

	let req = req_with_filters(FilterPayload {
		cross_sell: Some(CrossSellFilter {
			path: "ZZZZ".into(),
			exclude_uuid: "nonexistent".into(),
		}),
		..Default::default()
	});
	let mask = base_mask(&snap, &[]).unwrap();
	let filtered = apply_bitmap_filters(&snap, mask, &req).unwrap();
	assert_eq!(mask_as_vec(&filtered), vec![0]);
}
