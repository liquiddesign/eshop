//! Bitmap filtering — customer-independent intersections over the inverted indexes.
//!
//! PHP parity reference: `LiveProductsProvider::applyFilters` and the
//! `$allowedCollectionFilterColumns` / `$allowedDynamicFilterColumns` maps.

use roaring::RoaringBitmap;

use std::collections::HashMap;

use crate::{
	error::{RequestError, Result},
	protocol::{FilterPayload, GetProductsRequest, PriceVisibility},
	snapshot::{CatalogSnapshot, PricelistIdx, VisibilityItem},
};

/// Start from the full active-product mask and AND-in visibility-list membership using
/// priority-first selection (mirrors SQL `ProductRepository::joinVisibilityListItemToProductCollection`).
///
/// For each product we pick the single effective `VisibilityItem` across the customer's allowed
/// visibility lists: lowest `eshop_visibilitylist.priority` wins, with the list's UUID ASC as the
/// deterministic tie-break (matches the SQL `ORDER BY priority ASC, uuid ASC LIMIT 1`). The
/// product enters the mask only when that winner has `hidden = 0`. A product whose winner is
/// hidden drops out — this differs from the previous UNION-based implementation, which would
/// have kept any product whose *any* allowed list entry was `hidden = 0` and caused false
/// positives when a higher-priority list marked the product hidden.
///
/// If `visibility_list_pks` is empty we treat "no visibility filter" as "everything active" —
/// mirrors PHP default that skips the VL join when no list is supplied.
pub fn base_mask(snap: &CatalogSnapshot, visibility_list_pks: &[String]) -> Result<RoaringBitmap> {
	if visibility_list_pks.is_empty() {
		return Ok(snap.all_products_mask.clone());
	}

	// Resolve allowed visibility list idxs once (error out on unknowns — fallback path catches that).
	let mut allowed_vl_idxs: ahash::AHashSet<u32> = ahash::AHashSet::with_capacity(visibility_list_pks.len());
	for vl_pk in visibility_list_pks {
		let Some(vl_idx_u32) = snap.visibility_list_pool.lookup(vl_pk) else {
			return Err(RequestError::UnknownVisibilityList(vl_pk.clone()).into());
		};
		allowed_vl_idxs.insert(vl_idx_u32);
	}

	// Phase 3 fast path: single-VL request. Priority/uuid tie-break je no-op (jen jedna VL
	// v sadě), takže "winner" je pro každý produkt první encountered item v té VL —
	// přesně to, co `snap.visibility_winner_bitmaps` zachycuje při build-time.
	// Multi-VL request musí dál procházet skrz priority-first selection napříč VL setem.
	if visibility_list_pks.len() == 1 {
		let vl_idx_u32 = *allowed_vl_idxs.iter().next().expect("len == 1");
		if let Ok(vl_idx) = u16::try_from(vl_idx_u32) {
			if let Some(precomputed) = snap.visibility_winner_bitmaps.get(&vl_idx) {
				return Ok(precomputed.clone());
			}
			// Žádný precomputed bitmap pro známou VL znamená "VL nemá ani jeden produkt
			// s hidden=0". Build-time precompute prázdné bitmapy neukládá. Fallback na
			// `RoaringBitmap::new()` se shoduje se scanem níž (žádný winner → empty mask).
			return Ok(RoaringBitmap::new());
		}
	}

	Ok(base_mask_scan(snap, &allowed_vl_idxs))
}

/// Multi-VL `base_mask` rychlý lookup nad precomputed `product_vl_winners`.
///
/// Pro každý produkt walk pre-sorted seznamu `(vl_idx, hidden)` v priority order; první
/// `vl_idx ∈ allowed_vl_idxs` je winner. Pokud má `hidden=false`, produkt prochází.
///
/// Build-time pre-sort šetří per-comparison priority/uuid loop a `visibility_items[idx]`
/// indirection (cache-cold pole). Pre-Phase-7 implementace dělala 186 k × ≤4 row lookups
/// + porovnání ~3.66 ms; teď je to flat scan nad inline SmallVec entries ~< 1 ms.
///
/// Snapshot fixtures (před prvním rebuildem) mohou mít prázdný `product_vl_winners` —
/// fallback na původní lineární scan přes `visibility_items` zachová parity v testech.
pub(crate) fn base_mask_scan(snap: &CatalogSnapshot, allowed_vl_idxs: &ahash::AHashSet<u32>) -> RoaringBitmap {
	if snap.product_vl_winners.is_empty() {
		return base_mask_scan_legacy(snap, allowed_vl_idxs);
	}
	let mut mask = RoaringBitmap::new();
	for product in &snap.all_products_mask {
		let Some(winners) = snap.product_vl_winners.get(product as usize) else {
			continue;
		};
		for entry in winners {
			if allowed_vl_idxs.contains(&u32::from(entry.visibility_list)) {
				if !entry.hidden {
					mask.insert(product);
				}
				break;
			}
		}
	}
	mask
}

/// Pre-Phase-7 fallback used when `product_vl_winners` is empty (test fixtures, pre-rebuild).
fn base_mask_scan_legacy(snap: &CatalogSnapshot, allowed_vl_idxs: &ahash::AHashSet<u32>) -> RoaringBitmap {
	let mut mask = RoaringBitmap::new();

	for product in &snap.all_products_mask {
		let Some(item_idxs) = snap.visibility_by_product.get(&product) else {
			continue;
		};

		let mut best: Option<VisibilityItem> = None;

		for &row_idx in item_idxs {
			let Some(&item) = snap.visibility_items.get(row_idx as usize) else {
				continue;
			};
			if !allowed_vl_idxs.contains(&u32::from(item.visibility_list)) {
				continue;
			}

			let wins = match best {
				None => true,
				Some(current) => {
					if item.priority < current.priority {
						true
					} else if item.priority == current.priority {
						let item_uuid = snap.visibility_list_pool.get(u32::from(item.visibility_list)).unwrap_or("");
						let current_uuid = snap
							.visibility_list_pool
							.get(u32::from(current.visibility_list))
							.unwrap_or("");
						item_uuid < current_uuid
					} else {
						false
					}
				}
			};

			if wins {
				best = Some(item);
			}
		}

		if let Some(winner) = best {
			if !winner.hidden {
				mask.insert(product);
			}
		}
	}

	mask
}

/// Intersect the mask with the user's category/attribute/producer/displayAmount constraints.
///
/// OR-within-dimension, AND-across-dimensions — standard facet semantics.
pub fn apply_bitmap_filters(
	snap: &CatalogSnapshot,
	mask: RoaringBitmap,
	req: &GetProductsRequest,
) -> Result<RoaringBitmap> {
	apply_bitmap_filters_inner(
		snap,
		mask,
		&req.filters,
		req.dynamic_filter_attributes.as_ref(),
		&req.visibility_list_pks,
		None,
	)
}

/// Same as [`apply_bitmap_filters`] but accepts the filter components directly so the facet
/// leave-one-out path can override `filters` / `dynamic_filter_attributes` without cloning the
/// whole `GetProductsRequest` per call. `omit_attr_pk` skips that one attribute key during the
/// dynamic-attributes loop — used by `facets::leave_attribute_out`.
pub(crate) fn apply_bitmap_filters_inner(
	snap: &CatalogSnapshot,
	mut mask: RoaringBitmap,
	filter_payload: &FilterPayload,
	dynamic_filter_attributes: Option<&HashMap<String, Vec<String>>>,
	visibility_list_pks: &[String],
	omit_attr_pk: Option<&str>,
) -> Result<RoaringBitmap> {
	let req_filters = filter_payload;
	if let Some(cat_pks) = &req_filters.category_uuids {
		// Parita s PHP `LiveProvider::applyCategoryFilter` (LiveProvider.php:1317-1320):
		// `FIND_IN_SET(category_uuid, denormalizedCategories)`. CSV `denormalizedCategories` na
		// eshop_product už obsahuje "direct + applicable ancestors" (viz LiveProvider.php:1313),
		// takže stačí přímé membership přes `category_bitmaps` — descendant expansion tady by
		// vrátil nadmnožinu (produkty ve subkategoriích, jejichž denormalizedCategories root
		// nezahrnuje kvůli `showProductsInAncestors=false` na nějakém mezičlánku) a následná
		// SQL stage `ProductRepository::filterCategory` je vyhodila z paginované stránky.
		// Unknown UUIDs tiše dropujeme — kategorie bez produktů se vůbec neinternuje
		// při buildu snapshotu (`snapshot/build.rs` interne jen z `raw_p.category_uuids`),
		// takže lookup miss = prázdná kategorie, ne corrupt request. Stejný pattern jako
		// `producer_uuids` níže. Pokud žádné PK nezná, `union_of(&[])` vrátí prázdný bitmap
		// → mask &= empty = empty → 0 produktů, sémanticky správně.
		let mut idxs = Vec::with_capacity(cat_pks.len());
		for pk in cat_pks {
			if let Some(idx) = snap.category_pool.lookup(pk) {
				idxs.push(idx);
			}
		}
		mask &= snap.category_bitmaps.union_of(&idxs);
	}

	if let Some(attr_value_pks) = dynamic_filter_attributes {
		// Each attribute is its own dimension: OR within attribute, AND across attributes.
		for (attr_pk, value_pks) in attr_value_pks {
			if omit_attr_pk.is_some_and(|omit| omit == attr_pk.as_str()) {
				continue;
			}
			let mut idxs = Vec::with_capacity(value_pks.len());
			for pk in value_pks {
				let Some(idx) = snap.attribute_value_pool.lookup(pk) else {
					return Err(RequestError::UnknownAttribute(format!("{attr_pk}:{pk}")).into());
				};
				idxs.push(idx);
			}
			mask &= snap.attr_value_bitmaps.union_of(&idxs);
		}
	}

	if let Some(producer_pks) = &req_filters.producer_uuids {
		let mut idxs = Vec::with_capacity(producer_pks.len());
		for pk in producer_pks {
			if let Some(idx) = snap.producer_pool.lookup(pk) {
				idxs.push(idx);
			}
		}
		mask &= snap.producer_bitmaps.union_of(&idxs);
	}

	if let Some(display_amount_pks) = &req_filters.display_amount_uuids {
		let idxs: Vec<u32> = display_amount_pks.iter().filter_map(|pk| hash_display(pk)).collect();
		mask &= snap.display_amount_bitmaps.union_of(&idxs);
	}

	if let Some(display_delivery_pks) = &req_filters.display_delivery_uuids {
		let idxs: Vec<u32> = display_delivery_pks.iter().filter_map(|pk| hash_display(pk)).collect();
		mask &= snap.display_delivery_bitmaps.union_of(&idxs);
	}

	// --- `uuids` subset restrict --------------------------------------------------------
	// PHP `where('this.uuid', $uuids)` — unknown UUIDs are silently dropped (StORM IN-empty = no hits,
	// but any missing ones just drop from the union, so we skip unresolved rather than error).
	if let Some(uuids) = &req_filters.uuids {
		let mut allowed = RoaringBitmap::new();
		for pk in uuids {
			if let Some(idx) = snap.product_pool.lookup(pk) {
				allowed.insert(idx);
			}
		}
		mask &= allowed;
	}

	// --- `masterProduct` (fk_masterProduct IS NULL / IS NOT NULL) -----------------------
	// Mirror PHP `allowedCollectionFilterExpressions['masterProduct']`. Bitmap ops nad
	// předbudovaným `snap.master_mask`: true = průnik s master sadou, false = množinový rozdíl.
	if let Some(want_master) = req_filters.master_product {
		if want_master {
			mask &= &snap.master_mask;
		} else {
			mask -= &snap.master_mask;
		}
	}

	// --- ribbon AND (require each) -------------------------------------------------------
	// PHP `allowedDynamicFilterExpressions['ribbon']` flips the product's CSV and `isset`-checks
	// every requested ribbon. Missing ribbon in the pool ⇒ no product has it ⇒ mask drops to empty.
	if let Some(ribbons) = &req_filters.ribbon_uuids {
		for uuid in ribbons {
			let Some(idx) = snap.ribbon_pool.lookup(uuid) else {
				// Unknown ribbon UUID ⇒ no product carries it ⇒ result is empty.
				mask.clear();
				break;
			};
			match snap.ribbon_bitmaps.get(idx) {
				Some(bm) => mask &= bm,
				None => {
					mask.clear();
					break;
				}
			}
		}
	}

	if let Some(not_ribbons) = &req_filters.not_ribbon_uuids {
		for uuid in not_ribbons {
			if let Some(idx) = snap.ribbon_pool.lookup(uuid) {
				if let Some(bm) = snap.ribbon_bitmaps.get(idx) {
					mask -= bm;
				}
			}
		}
	}

	if let Some(internal_ribbons) = &req_filters.internal_ribbon_uuids {
		for uuid in internal_ribbons {
			let Some(idx) = snap.internal_ribbon_pool.lookup(uuid) else {
				mask.clear();
				break;
			};
			match snap.internal_ribbon_bitmaps.get(idx) {
				Some(bm) => mask &= bm,
				None => {
					mask.clear();
					break;
				}
			}
		}
	}

	if let Some(not_internal_ribbons) = &req_filters.not_internal_ribbon_uuids {
		for uuid in not_internal_ribbons {
			if let Some(idx) = snap.internal_ribbon_pool.lookup(uuid) {
				if let Some(bm) = snap.internal_ribbon_bitmaps.get(idx) {
					mask -= bm;
				}
			}
		}
	}

	// --- isSold (display_amount.isSold) --------------------------------------------------
	if let Some(wanted) = req_filters.is_sold {
		mask = filter_by_is_sold(snap, mask, wanted);
	}

	// --- inStock ------------------------------------------------------------------------
	// PHP `filterInStock` (ProductRepository.php:1172-1179): `fk_displayAmount IS NULL OR
	// eshop_displayamount.isSold = 0`. Pozor: `is_sold_by_product[p]` je 2 když produkt nemá
	// display_amount nebo když display_amount má unknown isSold — tyto dva případy se liší
	// sémanticky (NULL → pass, unknown → reject). Proto bereme v úvahu `display_amount.is_none()`
	// zvlášť.
	if matches!(req_filters.in_stock, Some(true)) {
		let mut allowed = RoaringBitmap::new();
		for product in &mask {
			let Some(row) = snap.products.get(product as usize) else {
				continue;
			};
			if row.display_amount.is_none() {
				allowed.insert(product);
				continue;
			}
			if snap.is_sold_by_product.get(product as usize).copied() == Some(0) {
				allowed.insert(product);
			}
		}
		mask = allowed;
	}

	// --- related (primary category match) ----------------------------------------------
	// PHP `filterRelated` (ProductRepository.php:1076-1079): `whereNot this.uuid = excludeUuid
	// AND productPrimaryCategory.fk_category = category`. `productPrimaryCategory` je
	// per-categoryType tabulka — PHP joinne ji na `shopperUser->getMainCategoryType()`. Klíč
	// do snapshot lookupu je tedy `(main_category_type_pk, primary_category_uuid)`.
	if let Some(related) = &req_filters.related {
		let key = (
			smol_str::SmolStr::new(&related.main_category_type_pk),
			smol_str::SmolStr::new(&related.primary_category_uuid),
		);
		if let Some(bm) = snap.primary_category_by_type_cat.get(&key) {
			mask &= bm;
		} else {
			mask.clear();
		}
		if let Some(exclude_idx) = snap.product_pool.lookup(&related.exclude_uuid) {
			mask.remove(exclude_idx);
		}
	}

	// --- relatedSlave (eshop_related JOIN) ---------------------------------------------
	// PHP `filterRelatedSlave` (ProductRepository.php:1196-1202): `this.uuid = related.fk_slave
	// AND related.fk_type = $typeUuid AND related.fk_master = $masterUuid`. Snapshot lookup:
	// `(type_uuid, master_uuid)` → bitmap slave ProductIdx.
	if let Some(rs) = &req_filters.related_slave {
		let key = (
			smol_str::SmolStr::new(&rs.type_uuid),
			smol_str::SmolStr::new(&rs.master_uuid),
		);
		if let Some(bm) = snap.related_slaves_by_type_master.get(&key) {
			mask &= bm;
		} else {
			mask.clear();
		}
	}

	// --- relatedTypeMaster (eshop_related JOIN, alias k relatedSlave s opačným pořadím args)
	// PHP `filterRelatedTypeMaster` (ProductRepository.php:1225-1236) má identickou sémantiku
	// jako `filterRelatedSlave`, jen v PHP volání se argumenty předávají v opačném pořadí
	// (`[$masterUuid, $typeUuid]` vs `[$typeUuid, $masterUuid]`). Sahá do stejného indexu.
	if let Some(rtm) = &req_filters.related_type_master {
		let key = (
			smol_str::SmolStr::new(&rtm.type_uuid),
			smol_str::SmolStr::new(&rtm.master_uuid),
		);
		if let Some(bm) = snap.related_slaves_by_type_master.get(&key) {
			mask &= bm;
		} else {
			mask.clear();
		}
	}

	// --- relatedTypeSlave (eshop_related JOIN, opačná strana) ---------------------------
	// PHP `filterRelatedTypeSlave` (ProductRepository.php:1238-1249): `this.uuid = related.fk_master
	// AND related.fk_slave = $slaveUuid AND related.fk_type = $typeUuid`. Vrací produkty, které
	// jsou MASTER. Lookup: `(type_uuid, slave_uuid)` → bitmap master ProductIdx.
	if let Some(rts) = &req_filters.related_type_slave {
		let key = (
			smol_str::SmolStr::new(&rts.type_uuid),
			smol_str::SmolStr::new(&rts.slave_uuid),
		);
		if let Some(bm) = snap.related_masters_by_type_slave.get(&key) {
			mask &= bm;
		} else {
			mask.clear();
		}
	}

	// --- toners (alias k relatedTypeSlave s pevným type 'tonerForPrinter') --------------
	// PHP `filterToners($masterUuid)` (1207-1212, @deprecated): vrátí mastery (= toner produkty)
	// pro daný printer master. Sémanticky `relatedTypeSlave([masterUuid, "tonerForPrinter"])` —
	// daemon používá stejný snapshot index `related_masters_by_type_slave`.
	if let Some(slave_uuid) = req_filters.toners.as_deref() {
		let key = (smol_str::SmolStr::new("tonerForPrinter"), smol_str::SmolStr::new(slave_uuid));
		if let Some(bm) = snap.related_masters_by_type_slave.get(&key) {
			mask &= bm;
		} else {
			mask.clear();
		}
	}

	// --- compatiblePrinters (alias k relatedTypeMaster s pevným type 'tonerForPrinter') --
	// PHP `filterCompatiblePrinters($value)` (1217-1223, @deprecated): vrátí slaves (= printer
	// produkty) pro daný toner master.
	if let Some(master_uuid) = req_filters.compatible_printers.as_deref() {
		let key = (smol_str::SmolStr::new("tonerForPrinter"), smol_str::SmolStr::new(master_uuid));
		if let Some(bm) = snap.related_slaves_by_type_master.get(&key) {
			mask &= bm;
		} else {
			mask.clear();
		}
	}

	// --- relatedTextSlave (text-only `eshop_related` row by UUID) -----------------------
	// PHP `filterRelatedTextSlave([rowUuid, typeCode])` (1255-1267): matchuje konkrétní řádek
	// (`related.uuid = $rowUuid AND fk_type = $typeCode AND fk_slave IS NULL`). Daemon: lookup
	// master přes `text_related_master_by_row_uuid`, defensive check typu přes
	// `text_related_type_by_row_uuid`.
	if let Some(rts) = &req_filters.related_text_slave {
		let row_key = smol_str::SmolStr::new(&rts.row_uuid);
		let type_match = snap
			.text_related_type_by_row_uuid
			.get(&row_key)
			.is_some_and(|t| t.as_str() == rts.type_uuid.as_str());
		if type_match {
			if let Some(&master_idx) = snap.text_related_master_by_row_uuid.get(&row_key) {
				let mut allowed = RoaringBitmap::new();
				allowed.insert(master_idx);
				mask &= allowed;
			} else {
				mask.clear();
			}
		} else {
			mask.clear();
		}
	}

	// --- relatedTextSlaveByName (text-only `eshop_related` rows by slaveName) -----------
	// PHP `filterRelatedTextSlaveByName([slaveName, typeCode])` (1273-1285): vrátí mastery,
	// kteří mají v daném typu stejný `slaveName`. Daemon: `text_related_master_by_type_name`.
	if let Some(rtn) = &req_filters.related_text_slave_by_name {
		let key = (
			smol_str::SmolStr::new(&rtn.type_uuid),
			smol_str::SmolStr::new(&rtn.slave_name),
		);
		if let Some(bm) = snap.text_related_master_by_type_name.get(&key) {
			mask &= bm;
		} else {
			mask.clear();
		}
	}

	// --- similarProducts (graf "similar" relací) ----------------------------------------
	// PHP `filterSimilarProducts($value)` (1287-1293): produkt je v relaci přes type s
	// `similar=1` s `$value` (z obou stran), AND `this.uuid != $value`. Daemon má symetrický
	// graf předpočítaný v `similar_neighbors_by_product`.
	if let Some(value_uuid) = req_filters.similar_products.as_deref() {
		if let Some(value_idx) = snap.product_pool.lookup(value_uuid) {
			if let Some(neighbors) = snap.similar_neighbors_by_product.get(&value_idx) {
				mask &= neighbors;
			} else {
				mask.clear();
			}
			mask.remove(value_idx);
		} else {
			// Neznámý produkt = 0 výsledků (PHP `where this.uuid != $unknown` projde, ale
			// JOIN `relation` na neexistujícím produktu nedá žádné rows).
			mask.clear();
		}
	}

	// --- crossSellFilter (category.path LIKE '%chunk' OR-chain) ------------------------
	// PHP `filterCrossSellFilter` (ProductRepository.php:1157-1170): rozdělí vstupní path na
	// 4-char chunky a pro každý přidá `categories.path LIKE '%chunk'` přes OR. Rust verze:
	// pro každý chunk najde CategoryIdx seznam v `categories_by_path_suffix`, unionuje jejich
	// category_bitmaps (= produkty v té kategorii) a intersectuje do mask.
	if let Some(cs) = &req_filters.cross_sell {
		let mut cat_idxs: Vec<u32> = Vec::new();
		let bytes = cs.path.as_bytes();
		// `str_split` v PHP na 4 bere po bytech, čímž je konzistentní s naším ASCII path formátem
		// (eshop_category.path je vždy ASCII 4-char prefix string).
		let mut i = 0;
		while i < bytes.len() {
			let end = (i + 4).min(bytes.len());
			if end - i < 4 {
				break;
			}
			let chunk = match std::str::from_utf8(&bytes[i..end]) {
				Ok(s) => s,
				Err(_) => break,
			};
			if let Some(bucket) = snap.categories_by_path_suffix.get(chunk) {
				cat_idxs.extend(bucket.iter().copied());
			}
			i += 4;
		}
		// Union category bitmaps → produkt-level mask.
		let union = snap.category_bitmaps.union_of(&cat_idxs);
		mask &= union;
		if let Some(exclude_idx) = snap.product_pool.lookup(&cs.exclude_uuid) {
			mask.remove(exclude_idx);
		}
	}

	// --- visibility boolean flags (hidden / hiddenInMenu / recommended / unavailable) ---
	// All four dimensions share the same pass: produce a bitmap of products that have at least
	// one VL item in `req.visibility_list_pks` matching the requested flag values. Skip the pass
	// entirely if no flag is set to avoid a linear scan on the hot path.
	let has_bool_filter = req_filters.hidden.is_some()
		|| req_filters.hidden_in_menu.is_some()
		|| req_filters.recommended.is_some()
		|| req_filters.unavailable.is_some();
	if has_bool_filter {
		mask &= visibility_boolean_mask(snap, visibility_list_pks, req_filters)?;
	}

	Ok(mask)
}

/// Intersect mask with products whose denormalized `is_sold` equals `wanted`.
fn filter_by_is_sold(snap: &CatalogSnapshot, mask: RoaringBitmap, wanted: u8) -> RoaringBitmap {
	let mut allowed = RoaringBitmap::new();
	for product in &mask {
		let Some(actual) = snap.is_sold_by_product.get(product as usize).copied() else {
			continue;
		};
		if actual == wanted {
			allowed.insert(product);
		}
	}
	allowed
}

/// Build a bitmap of products where AT LEAST ONE visibility-list item (restricted to
/// `visibility_list_pks`) has every requested boolean flag matching the filter value.
///
/// Mirrors the collection-column filters PHP registers on `this.visibilityListItem.*` — those
/// live on the joined VL item row, so the equivalence is "there exists a VL-item that satisfies
/// all specified column predicates".
fn visibility_boolean_mask(
	snap: &CatalogSnapshot,
	visibility_list_pks: &[String],
	filters: &FilterPayload,
) -> Result<RoaringBitmap> {
	// Resolve allowed VL idxs. Empty set = match any VL (PHP default when no VL filter active).
	let mut allowed_vls: ahash::AHashSet<u32> = ahash::AHashSet::with_capacity(visibility_list_pks.len());
	for pk in visibility_list_pks {
		let Some(idx) = snap.visibility_list_pool.lookup(pk) else {
			return Err(RequestError::UnknownVisibilityList(pk.clone()).into());
		};
		allowed_vls.insert(idx);
	}

	let mut mask = RoaringBitmap::new();
	for item in &snap.visibility_items {
		if !allowed_vls.is_empty() && !allowed_vls.contains(&u32::from(item.visibility_list)) {
			continue;
		}
		if let Some(want) = filters.hidden {
			if item.hidden != want {
				continue;
			}
		}
		if let Some(want) = filters.hidden_in_menu {
			if item.hidden_in_menu != want {
				continue;
			}
		}
		if let Some(want) = filters.recommended {
			if item.recommended != want {
				continue;
			}
		}
		if let Some(want) = filters.unavailable {
			if item.unavailable != want {
				continue;
			}
		}
		mask.insert(item.product);
	}
	Ok(mask)
}

/// Drop priced products that fail any of the registered dynamic filter expressions:
/// `contract`, `notPublic`, `project`. Applied after `compute_effective_prices` because
/// `contract`/`notPublic` check the *selected* pricelist (must know which pricelist won).
///
/// Mirror [PHP port](FrontendPresenter.php:481–561):
/// - `contract` / `notPublic`: when a product carries the restricted internal-ribbon, it is
///   kept only if its selected pricelist is in the customer's `favouritePriceLists`.
/// - `project`: for products with `isProjectProduct=true`, authorization depends on customer
///   IČ match against the product's `projectIc` CSV (merchant = lenient, customer = strict).
pub fn apply_restrictive_filters(
	snap: &crate::snapshot::CatalogSnapshot,
	priced: Vec<crate::query::PricedProduct>,
	req: &crate::protocol::GetProductsRequest,
) -> Vec<crate::query::PricedProduct> {
	let contract_ribbon_idx = req
		.contract_ribbon_pk
		.as_deref()
		.and_then(|pk| snap.internal_ribbon_pool.lookup(pk));
	let not_public_ribbon_idx = req
		.not_public_ribbon_pk
		.as_deref()
		.and_then(|pk| snap.internal_ribbon_pool.lookup(pk));

	// Customer's favourite pricelists — translated to indexes for O(1) `AHashSet` membership.
	let favourites: ahash::AHashSet<PricelistIdx> = req
		.favourite_pricelist_pks
		.iter()
		.filter_map(|pk| snap.pricelist_pk_to_idx.get(pk.as_str()).copied())
		.collect();

	let project_filter = req.project_filter.as_ref();

	// No active restrictions — skip the whole pass so happy-path requests pay nothing.
	if contract_ribbon_idx.is_none() && not_public_ribbon_idx.is_none() && project_filter.is_none() {
		return priced;
	}

	priced
		.into_iter()
		.filter(|p| {
			let Some(product) = snap.products.get(p.product as usize) else {
				return true;
			};

			if let Some(ribbon_idx) = contract_ribbon_idx {
				if product.internal_ribbons.contains(&ribbon_idx) && !favourites.contains(&p.pricelist) {
					return false;
				}
			}

			if let Some(ribbon_idx) = not_public_ribbon_idx {
				if product.internal_ribbons.contains(&ribbon_idx) && !favourites.contains(&p.pricelist) {
					return false;
				}
			}

			if let Some(pf) = project_filter {
				if product.is_project {
					return project_authorized(product, pf);
				}
			}

			true
		})
		.collect()
}

fn project_authorized(product: &crate::snapshot::ProductRow, pf: &crate::protocol::ProjectFilter) -> bool {
	let customer_ic = pf.customer_ic.as_deref().filter(|s| !s.is_empty());

	if pf.is_merchant {
		// Merchant = benevolent: empty projectIcs allow all, non-empty needs customer IC match.
		if product.project_ics.is_empty() {
			return true;
		}

		return customer_ic.is_some_and(|ic| product.project_ics.iter().any(|p| p.as_str() == ic));
	}

	// Regular customer = strict: empty projectIcs reject, otherwise IC must match.
	let Some(ic) = customer_ic else {
		return false;
	};
	if product.project_ics.is_empty() {
		return false;
	}

	product.project_ics.iter().any(|p: &smol_str::SmolStr| p.as_str() == ic)
}

/// Mask of products that have at least one price in the given pricelist set, matching the
/// visibility OR-expression assembled by PHP `ProductRepository::setProductsConditions`.
///
/// ## PHP parity — the if-without-else cascade
/// PHP `setProductsConditions` (ProductRepository.php:727-739) assembles `$priceZeroWhere` via
/// a three-step `if` chain with no `elseif`. When both `showVat` and `showWithoutVat` are true
/// the final clause overwrites previous assignments, so the SQL only ends up checking
/// `price > 0` (not `price > 0 AND priceVat > 0`). We preserve this behavior bit-for-bit —
/// changing it here would diverge from the SQL that validates `productPKs` downstream.
///
/// ## Logic summary (per visibility config)
/// - `showZeroPrices=true` → accept any row with `price IS NOT NULL` (no zero guard).
/// - `showZeroPrices=false` & `showVat=true` & `showWithoutVat=false` → require `priceVat > 0`.
/// - `showZeroPrices=false` & `showVat=false` & `showWithoutVat=true` → require `price > 0`.
/// - `showZeroPrices=false` & both true → require `price > 0` only (PHP cascade bug).
/// - `showZeroPrices=false` & both false → no zero guard applied (mirrors PHP: no `if` arm fires).
///
/// `include_hidden_prices=false` additionally requires `prices.hidden = 0` (same as the earlier
/// implementation). When `true`, hidden rows also count.
pub fn has_any_price_mask(
	snap: &CatalogSnapshot,
	pricelists: &[PricelistIdx],
	price_visibility: PriceVisibility,
) -> RoaringBitmap {
	let mut mask = RoaringBitmap::new();
	if pricelists.is_empty() {
		return mask;
	}
	let set: ahash::AHashSet<PricelistIdx> = pricelists.iter().copied().collect();

	// Walk product-sorted prices once. `prices_by_product[p] = range` points at the
	// contiguous slice for product `p`.
	for (product_idx, range) in snap.prices_by_product.iter().enumerate() {
		if range.is_empty() {
			continue;
		}
		#[allow(clippy::cast_possible_truncation)]
		let product = product_idx as u32;
		for fact in &snap.prices[range.start as usize..range.end as usize] {
			if !price_visibility.include_hidden_prices && fact.flags.is_hidden() {
				continue;
			}
			if !set.contains(&fact.pricelist) {
				continue;
			}
			if !price_row_passes_zero_guard(fact.price, fact.price_vat, price_visibility) {
				continue;
			}
			mask.insert(product);
			break;
		}
	}
	mask
}

/// Mirror of PHP `$priceZeroWhere` assembly — including the cascade bug (see `has_any_price_mask` doc).
#[inline]
fn price_row_passes_zero_guard(price: f64, price_vat: f64, vis: PriceVisibility) -> bool {
	if vis.show_zero_prices {
		return true;
	}
	// PHP cascade: last matching arm wins.
	// Arm 1: showVat && showWithoutVat → `price > 0 AND priceVat > 0`.
	// Arm 2: showVat → `priceVat > 0`.
	// Arm 3: showWithoutVat → `price > 0`.
	// None matching → no guard added → row passes by default.
	if vis.show_vat && vis.show_without_vat {
		// Arm 1 runs, but arm 3 immediately overwrites it (PHP bug we must mirror).
		return price > 0.0;
	}
	if vis.show_vat {
		// Arm 2 runs; no arm 3 (showWithoutVat false).
		return price_vat > 0.0;
	}
	if vis.show_without_vat {
		// Only arm 3 runs.
		return price > 0.0;
	}
	true
}

/// Helper for the display_* dimensions — mirrors the hash used in `snapshot::build::display_amount_idx`.
/// TODO(#M2-filter): if we ever need cross-run index stability (e.g. persisting baselines),
/// replace this with a dedicated intern pool.
#[inline]
fn hash_display(uuid: &str) -> Option<u32> {
	use std::hash::{Hash, Hasher};
	let mut hasher = ahash::AHasher::default();
	uuid.hash(&mut hasher);
	Some((hasher.finish() & 0xFFFF_FFFF) as u32)
}

impl FilterPayload {
	// Helper used when re-scanning bitmaps for facet leave-one-out masks.
	pub(crate) fn dims_besides(&self, dim: FilterDim) -> FilterPayload {
		let mut copy = self.clone();
		match dim {
			FilterDim::Category => copy.category_uuids = None,
			FilterDim::Producer => copy.producer_uuids = None,
			FilterDim::DisplayAmount => copy.display_amount_uuids = None,
			FilterDim::DisplayDelivery => copy.display_delivery_uuids = None,
		}
		copy
	}
}

#[derive(Debug, Copy, Clone, Eq, PartialEq)]
pub(crate) enum FilterDim {
	Category,
	Producer,
	DisplayAmount,
	DisplayDelivery,
}

#[cfg(test)]
mod tests {
	use ahash::AHashMap;
	use roaring::RoaringBitmap;
	use smallvec::SmallVec;

	use crate::snapshot::{CatalogSnapshot, VisibilityItem, VisibilityListIdx, VisibilityListMeta};

	use super::base_mask;

	/// Postaví test snapshot se třemi produkty a dvěma VLs:
	/// - p0: VL_A hidden=false (winner) → visible v VL_A
	/// - p1: VL_A hidden=true (winner)  → invisible v VL_A
	/// - p2: VL_B hidden=false (winner) → visible v VL_B, ne v VL_A
	fn build_vl_snapshot(with_precompute: bool) -> CatalogSnapshot {
		let mut snap = std::sync::Arc::into_inner(CatalogSnapshot::empty()).unwrap();

		let vl_a = snap.visibility_list_pool.intern("vl-a").unwrap();
		let vl_b = snap.visibility_list_pool.intern("vl-b").unwrap();
		snap.visibility_lists.push(VisibilityListMeta {
			idx: u16::try_from(vl_a).unwrap(),
			is_active: true,
			has_customer_binding: true,
		});
		snap.visibility_lists.push(VisibilityListMeta {
			idx: u16::try_from(vl_b).unwrap(),
			is_active: true,
			has_customer_binding: true,
		});

		for i in 0..3u32 {
			snap.product_pool.intern(&format!("p-{i}")).unwrap();
			snap.all_products_mask.insert(i);
		}

		let mk_item = |product: u32, vl: u32, hidden: bool| VisibilityItem {
			product,
			visibility_list: VisibilityListIdx::try_from(vl).unwrap(),
			hidden,
			hidden_in_menu: false,
			recommended: false,
			unavailable: false,
			priority: 0,
		};
		snap.visibility_items.push(mk_item(0, vl_a, false));
		snap.visibility_items.push(mk_item(1, vl_a, true));
		snap.visibility_items.push(mk_item(2, vl_b, false));

		let mut by_product: AHashMap<u32, SmallVec<[u32; 4]>> = AHashMap::new();
		by_product.insert(0, SmallVec::from_iter([0u32]));
		by_product.insert(1, SmallVec::from_iter([1u32]));
		by_product.insert(2, SmallVec::from_iter([2u32]));
		snap.visibility_by_product = by_product;

		if with_precompute {
			// Mirror SnapshotBuilder Phase 3 logic: per-VL = produkty s non-hidden winner.
			let mut bitmaps: AHashMap<VisibilityListIdx, RoaringBitmap> = AHashMap::new();
			let mut a_bm = RoaringBitmap::new();
			a_bm.insert(0); // p0 winner v vl_a, non-hidden
			bitmaps.insert(VisibilityListIdx::try_from(vl_a).unwrap(), a_bm);
			let mut b_bm = RoaringBitmap::new();
			b_bm.insert(2);
			bitmaps.insert(VisibilityListIdx::try_from(vl_b).unwrap(), b_bm);
			snap.visibility_winner_bitmaps = bitmaps;
		}

		snap
	}

	#[test]
	fn single_vl_fast_path_matches_scan() {
		let snap = build_vl_snapshot(true);

		for vl_pk in ["vl-a", "vl-b"] {
			let pks = vec![vl_pk.to_string()];
			let fast = base_mask(&snap, &pks).unwrap();

			let mut allowed: ahash::AHashSet<u32> = ahash::AHashSet::new();
			allowed.insert(snap.visibility_list_pool.lookup(vl_pk).unwrap());
			let scan = super::base_mask_scan(&snap, &allowed);

			assert_eq!(
				fast.iter().collect::<Vec<_>>(),
				scan.iter().collect::<Vec<_>>(),
				"single-VL fast path drift for {vl_pk}"
			);
		}
	}

	#[test]
	fn unknown_single_vl_errors() {
		let snap = build_vl_snapshot(true);
		// Unknown VL => UnknownVisibilityList error (resolution happens před fast path).
		let err = base_mask(&snap, &[String::from("does-not-exist")]).unwrap_err();
		assert!(format!("{err}").contains("does-not-exist"));
	}

	/// Phase 7: ověř že multi-VL `base_mask_scan` přes `product_vl_winners` respektuje
	/// priority-first selekci uvnitř customer subsetu a vyloučí produkty, jejichž winner
	/// má `hidden = true`. Single-VL fast path je pokrytý zvlášť (testy výš).
	///
	/// Scenario (oba multi-VL volání):
	/// - VL_HIGH (priority 1, uuid "vl-high"), VL_LOW (priority 5, uuid "vl-low"), VL_EXTRA (uuid "vl-extra")
	/// - p0: HIGH non-hidden + LOW non-hidden → winner v {HIGH,LOW} = HIGH (priority 1), visible
	/// - p1: HIGH hidden + LOW non-hidden → winner = HIGH (hidden), drops out
	/// - p2: jen LOW non-hidden → winner v {HIGH,LOW} = LOW, visible
	#[test]
	fn multi_vl_priority_first_winner_with_hidden() {
		use crate::snapshot::VlWinnerEntry;

		let mut snap = std::sync::Arc::into_inner(CatalogSnapshot::empty()).unwrap();

		let vl_high = snap.visibility_list_pool.intern("vl-high").unwrap();
		let vl_low = snap.visibility_list_pool.intern("vl-low").unwrap();
		let vl_extra = snap.visibility_list_pool.intern("vl-extra").unwrap();
		let vl_high_idx = u16::try_from(vl_high).unwrap();
		let vl_low_idx = u16::try_from(vl_low).unwrap();
		let vl_extra_idx = u16::try_from(vl_extra).unwrap();
		for idx in [vl_high_idx, vl_low_idx, vl_extra_idx] {
			snap.visibility_lists.push(VisibilityListMeta {
				idx,
				is_active: true,
				has_customer_binding: true,
			});
		}

		for i in 0..3u32 {
			snap.product_pool.intern(&format!("p-{i}")).unwrap();
			snap.all_products_mask.insert(i);
		}

		// `product_vl_winners` plněné build-time logikou: kandidáti seřazení podle
		// (priority ASC, vl_uuid ASC). HIGH má prio 1, LOW prio 5 → HIGH stojí první.
		snap.product_vl_winners = vec![
			SmallVec::from_vec(vec![
				VlWinnerEntry::new(vl_high_idx, false),
				VlWinnerEntry::new(vl_low_idx, false),
			]),
			SmallVec::from_vec(vec![
				VlWinnerEntry::new(vl_high_idx, true),
				VlWinnerEntry::new(vl_low_idx, false),
			]),
			SmallVec::from_vec(vec![VlWinnerEntry::new(vl_low_idx, false)]),
		];

		// Customer subset = {LOW, EXTRA} (multi-VL → product_vl_winners path).
		// p0 → LOW (HIGH chybí v subsetu) → visible.
		// p1 → LOW → visible.
		// p2 → LOW → visible.
		let mask_low = base_mask(&snap, &["vl-low".to_string(), "vl-extra".to_string()]).unwrap();
		assert_eq!(
			mask_low.iter().collect::<Vec<_>>(),
			vec![0, 1, 2],
			"subset {{LOW, EXTRA}}"
		);

		// Customer subset = {HIGH, LOW}. Winner per produkt:
		// p0 → HIGH (priority 1, non-hidden) → visible.
		// p1 → HIGH (priority 1, hidden) → drops out.
		// p2 → LOW (jediná entry) → visible.
		let mask_both = base_mask(&snap, &["vl-high".to_string(), "vl-low".to_string()]).unwrap();
		assert_eq!(mask_both.iter().collect::<Vec<_>>(), vec![0, 2], "subset {{HIGH,LOW}}");
	}
}
