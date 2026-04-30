//! Wire protocol (v1) — JSON over length-prefixed frames on a Unix socket.
//!
//! The shape of `GetProductsRequest` / `GetProductsResponse` is load-bearing for the PHP
//! proxy (`RustProxyProductsProvider`). Any breaking change must be paired with a bump of
//! `crate::PROTOCOL_VERSION` and a server-side `VersionMismatch` rejection for the old client.

pub mod framing;

use std::collections::HashMap;

use serde::{Deserialize, Serialize};

use crate::error::Result;

/// The envelope sent over the socket. One per request.
#[derive(Debug, Clone, Serialize, Deserialize)]
#[serde(rename_all = "camelCase", tag = "method", content = "params")]
pub enum RequestEnvelope {
	/// Cheap liveness check — `{ "method": "ping" }` returns `{ "ok": true }`.
	Ping,
	/// Main product listing query.
	GetProducts(GetProductsRequest),
	/// Category count query — mirrors `GeneralProductsCacheProvider::getCategoryCount`.
	GetCategoryCount(GetCategoryCountRequest),
	/// Batched count — jedním dotazem vrátí `categoryUuid → count` pro celý snapshot se
	/// stejnou filter logikou jako `GetCategoryCount`, ale navíc propaguje direct kategorie
	/// na ancestors/descendants dle `show_descendant_products` / `show_products_in_ancestors`
	/// flagů — mirror `ProductsCacheGetterService::…` line 734 (walk ancestors + descendants
	/// per direct category). Caller má vynechat `filters.category_uuids`; pokud pošle, daemon
	/// omezí mask na subtree té kategorie (užitečné pro menu-stromek pod aktuální kategorií).
	GetAllCategoryCounts(GetAllCategoryCountsRequest),
	/// Vrátí seznam UUID všech produktů, které mají nenulovou cenu v aspoň jednom aktivním
	/// ceníku. Určeno pro export exportéry (Algolia) — odpovídá `ProductsCacheProvider::getSellableProductPKs`.
	/// Explicitní rename aby wire name PKs byl zachován přesně (heck camelCase by PKs špatně přepsal).
	#[serde(rename = "getSellableProductPKs")]
	GetSellableProductPKs,
	/// Diagnostické runtime metriky pro Tracy panel (uptime, RSS, total requests,
	/// avg/max latence, historie snapshot buildů). Nemutuje stav; cheap, safe to poll.
	GetStats,
	/// Fire-and-forget signál pro probuzení refresheru. PHP volá po dokončení velkého
	/// importu, aby snapshot rebuild proběhl dřív než po `quick_check_interval` (60s).
	/// Daemon vyšle `Notify::notify_one()` (idempotentní — opakované volání před
	/// receiverovým wakeupem konsoliduje na jeden permit), takže storm volání nemůže
	/// způsobit storm rebuildů; další ochranou je `MIN_REBUILD_INTERVAL_SECS` floor
	/// uvnitř refresheru. Server odpovídá okamžitě (nečeká na rebuild).
	RequestRebuild,
}

/// Server response envelope. Always includes `fallback_required`; PHP treats `true` as
/// "delegate to `LiveProductsProvider`".
///
/// `Ok` is boxed so the enum variant sizes stay close (the contained `GetProductsResponse`
/// is ~200 B, `ErrorResponse` ~40 B; without boxing we'd pay the full size on every wire).
#[derive(Debug, Clone, Serialize, Deserialize)]
#[serde(untagged)]
pub enum ResponseEnvelope {
	Ok(Box<OkResponse>),
	Error(ErrorResponse),
}

#[derive(Debug, Clone, Serialize, Deserialize)]
#[serde(rename_all = "camelCase")]
pub struct OkResponse {
	pub protocol_version: u16,
	#[serde(flatten)]
	pub body: ResponseBody,
	/// Per-step latency breakdown. `None` (a tedy nepřítomné v JSON) když request neměl
	/// `debug: true`. PHP `RustDaemonBarPanel` mapuje keys jako sub-rows v Tracy panelu.
	/// Hodnoty v ms (f64 — lépe lidsky čitelné než µs u sub-ms kroků jako parse).
	#[serde(skip_serializing_if = "Option::is_none")]
	pub timings: Option<TimingsBreakdown>,
}

/// Per-step měření daemon pipeline — opt-in přes `GetProductsRequest::debug = true`.
///
/// `parse_ms` / `serialize_ms` měří samotný handler (mimo `query::run`); zbytek pochází
/// z `query::Timings`. Hodnoty jsou v ms. Klíče matchují PHP-side sub-rows v Tracy panelu.
#[derive(Debug, Clone, Default, Serialize, Deserialize)]
#[serde(rename_all = "camelCase")]
pub struct TimingsBreakdown {
	/// `serde_json::from_slice(frame) → RequestEnvelope`.
	pub parse_ms: f64,
	/// `filter::base_mask` — visibility-list priority-first selection (hlavní pipeline).
	pub base_mask_ms: f64,
	/// `filter::apply_bitmap_filters` — category, attributes, producer, ribbons, ...
	pub bitmap_filters_ms: f64,
	/// `filter::has_any_price_mask` + `pricing::compute_effective_prices` + price-band.
	pub pricing_ms: f64,
	/// `facets::compute` — per-dim leave-one-out + price min/max.
	pub facets_ms: f64,
	/// `ordering::order_and_serialize` — sort + UUID materializace.
	pub ordering_ms: f64,
	/// `serde_json::to_vec(&response)` — výstupní serializace.
	pub serialize_ms: f64,
}

#[derive(Debug, Clone, Serialize, Deserialize)]
#[serde(tag = "type", rename_all = "camelCase")]
pub enum ResponseBody {
	Pong {
		ok: bool,
	},
	/// Boxed — same reason as `ResponseEnvelope::Ok`: `GetProductsResponse` is ~200 B and
	/// other variants are tiny.
	Products(Box<GetProductsResponse>),
	CategoryCount {
		count: u64,
	},
	/// Mapa `categoryUuid → count` po aplikaci filtrů (bez ordering / pricing pipeline).
	/// Propagace direct → ancestors/descendants se řídí flagy per kategorie (viz
	/// `CategoryNode::show_descendant_products` / `show_products_in_ancestors`).
	AllCategoryCounts {
		counts: HashMap<String, u64>,
	},
	/// Explicit "PHP please fall back" response. No business payload — PHP proxy calls
	/// `LiveProductsProvider` and returns its result instead.
	FallbackRequired {
		reason: String,
	},
	/// Seznam UUID produktů s nenulovou cenou v aspoň jednom aktivním ceníku.
	/// Explicitní rename aby wire name PKs byl zachován přesně.
	#[serde(rename = "sellableProductPKs")]
	SellableProductPKs {
		pks: Vec<String>,
	},
	/// Diagnostické metriky daemonu pro Tracy panel.
	Stats(Box<StatsResponse>),
	/// Acknowledgement pro `RequestRebuild`. `queued = true` znamená, že wakeup permit
	/// byl uložen — refresher se probudí v nejbližší `select!` iteraci a (pokud floor
	/// dovolí) provede rebuild. Není to potvrzení, že rebuild fakticky proběhl.
	RebuildAccepted {
		queued: bool,
	},
}

/// Snapshot runtime metrik daemonu — konzumuje PHP `RustDaemonBarPanel`.
///
/// Request-countery jsou rozdělené do dvou kategorií:
/// - `total_requests` — kumulativní počet **všech** volání (query + ping + getStats). Tracy
///   panel polling i health-check ping se tedy v něm projeví. Ukazatel "daemon je živý".
/// - `work_requests` + `avg_request_ms` + `max_request_ms` — jen "skutečná práce" (query
///   proti snapshotu, včetně fallback_required). Meta-calls (ping, getStats) jsou z latencí
///   vyřazené, aby polling nezkresloval business SLO.
#[derive(Debug, Clone, Serialize, Deserialize)]
#[serde(rename_all = "camelCase")]
pub struct StatsResponse {
	/// Unix epoch seconds, kdy byl proces startován.
	pub started_at_unix: u64,
	/// Uptime v celých sekundách.
	pub uptime_secs: u64,
	/// Resident-set-size procesu v MB (z `/proc/self/statm`), `None` pokud se nepodařilo přečíst.
	pub rss_mb: Option<u64>,
	/// Kumulativní počet zpracovaných requestů (všechny metody dohromady, včetně ping/getStats).
	pub total_requests: u64,
	/// Počet "work" requestů (query proti snapshotu, bez ping/getStats). Podmnožina
	/// `total_requests`. Je to to, co počítá avg/max latence.
	pub work_requests: u64,
	/// Průměrná latence jednoho work requestu v ms. `None` pokud `work_requests == 0`.
	pub avg_request_ms: Option<f64>,
	/// Nejpomalejší zpracovaný work request v ms.
	pub max_request_ms: f64,
	/// Bounded historie wallclock časů snapshot buildů (Unix epoch seconds) — initial
	/// build + každý drift rebuild. Nejnovější naposledy.
	pub snapshot_timestamps_unix: Vec<u64>,
	/// Počet produktů v aktuálně publikovaném snapshotu.
	pub product_count: u64,
	/// Počet price rows v aktuálně publikovaném snapshotu.
	pub price_count: u64,
	/// Hrubý odhad RAM držené snapshotem (MB).
	pub snapshot_memory_estimate_mb: u64,
	/// Hash drift signálů pro aktuální snapshot — viz `Refresher::tick`. Posílá se jako
	/// string: u64 hash přesahuje PHP_INT_MAX (~9.22e18) i JS MAX_SAFE_INTEGER (~9e15),
	/// takže JSON float repre by tiše ztrácel precision a PHP 8.4 by na `(int)$float`
	/// vyhodil ErrorException.
	pub schema_version: String,
}

#[derive(Debug, Clone, Serialize, Deserialize)]
#[serde(rename_all = "camelCase")]
pub struct ErrorResponse {
	pub protocol_version: u16,
	pub error: ErrorKind,
	pub message: String,
}

#[derive(Debug, Clone, Serialize, Deserialize)]
#[serde(rename_all = "snake_case")]
pub enum ErrorKind {
	SnapshotNotReady,
	UnknownVisibilityList,
	UnknownPricelist,
	UnknownCategory,
	UnknownAttribute,
	ProtocolVersion,
	MalformedRequest,
	Internal,
}

// -------- Get products --------

/// A full query for `LiveProductsProvider::getProductsFromCacheTable`.
#[derive(Debug, Clone, Default, Serialize, Deserialize)]
#[serde(rename_all = "camelCase", deny_unknown_fields)]
pub struct GetProductsRequest {
	/// Pricelist UUIDs belonging to the current customer (visibility/sorted by priority DESC).
	/// Empty means "no eligible pricelist" — response will have no products.
	pub pricelist_pks: Vec<String>,

	/// Visibility list UUIDs. Empty means "no visibility filter".
	pub visibility_list_pks: Vec<String>,

	/// Bitmap-applicable filters (category, producer, display*, price band, etc.).
	pub filters: FilterPayload,

	/// Attribute filters: attributePk → list of attributeValuePks (OR within, AND across).
	/// Use `None` to skip attribute filtering entirely.
	pub dynamic_filter_attributes: Option<HashMap<String, Vec<String>>>,

	/// Per-customer price modifiers applied after the best-price pick.
	pub price_modifiers: PriceModifiers,

	/// `priority` (default), `price`, `name`. Anything else triggers fallback.
	pub order_by: Option<String>,
	pub order_direction: OrderDirection,

	/// Compute `categoriesCounts` in the response when `true`.
	pub count_categories: bool,

	/// Debug flag mirrored from PHP — currently unused server-side, kept for parity.
	pub debug: bool,

	/// UUID pořadí pro `order_by = "uuidField"` (assistant create-order flow). Když non-empty
	/// a order_by=="uuidField", daemon seřadí productPKs přesně v tomto pořadí. Odpovídá
	/// `addCollectionOrderExpression('uuidField', …)` → `FIELD(this.product, …)` v PHP.
	#[serde(default)]
	pub order_uuids: Option<Vec<String>>,

	/// Seznam favourite pricelist UUIDů aktuálního zákazníka. Používají filter `contract`
	/// a `notPublic` — produkt s restricted ribbon smí zůstat jen když patří do některého
	/// z těchto pricelistů.
	#[serde(default)]
	pub favourite_pricelist_pks: Vec<String>,

	/// UUID internal ribbonu pro filter `contract`. Produkty s tímto ribbonem se filtrují
	/// přes `favourite_pricelist_pks` check (produkt bez ribbonu = auto include).
	#[serde(default)]
	pub contract_ribbon_pk: Option<String>,

	/// UUID internal ribbonu pro filter `notPublic`. Stejná sémantika jako contract, jiný ribbon.
	#[serde(default)]
	pub not_public_ribbon_pk: Option<String>,

	/// Parametry pro filter `project` (authorizace project produktů). `None` vypne filter.
	#[serde(default)]
	pub project_filter: Option<ProjectFilter>,

	/// Price visibility context forwarded from PHP `ShopperUser`. Controls the `has_any_price_mask`
	/// OR expression (zero-price + hidden handling) and the showVat branch for
	/// `price_from`/`price_to`/`price_gt`. Defaults mimic PHP B2C config (showVat=true, others false).
	#[serde(default)]
	pub price_visibility: PriceVisibility,
}

#[derive(Debug, Clone, Default, Serialize, Deserialize)]
#[serde(rename_all = "camelCase", deny_unknown_fields)]
pub struct ProjectFilter {
	/// Customer IČ (nebo merchant-proxied zákazník). `None` = žádné IČ → filter odmítá project produkty.
	pub customer_ic: Option<String>,
	/// True = merchant pohled (benevolentní: empty projectIcs = allow, non-empty = musí match).
	/// False = regular customer (striktnější: empty projectIcs ⇒ reject).
	pub is_merchant: bool,
}

/// `#[serde(default)]` lets a `{}` JSON body deserialize into the struct's default
/// (everything `None`) instead of erroring out on "missing field". PHP proxy emits `{}`
/// whenever no filters are active.
#[derive(Debug, Clone, Default, Serialize, Deserialize)]
#[serde(rename_all = "camelCase", default, deny_unknown_fields)]
pub struct FilterPayload {
	pub category_uuids: Option<Vec<String>>,
	pub producer_uuids: Option<Vec<String>>,
	pub display_amount_uuids: Option<Vec<String>>,
	pub display_delivery_uuids: Option<Vec<String>>,
	/// Inclusive lower bound for effective price (post-modifiers). PHP matches against
	/// `float` (double), so we accept f64 here to avoid a lossy f32 round-trip.
	pub price_from: Option<f64>,
	/// Inclusive upper bound for effective price (post-modifiers). Same type as `price_from`.
	pub price_to: Option<f64>,
	/// **Strict** lower bound (`price > value`) for effective price. Separate from `price_from`
	/// (inclusive); mirrors PHP `priceGt` dynamic filter expression in `LiveProductsProvider::startUp`.
	pub price_gt: Option<f64>,

	/// Restrict products to those present in this UUID list. Mirrors `allowedCollectionFilterExpressions['uuids']`.
	/// Unknown UUIDs silently drop out — no error (PHP `where(... IN [])` just returns no match).
	pub uuids: Option<Vec<String>>,

	/// Require EACH ribbon UUID to be present on the product (AND within dimension). PHP's
	/// `array_flip + isset` loop in `allowedDynamicFilterExpressions['ribbon']`.
	pub ribbon_uuids: Option<Vec<String>>,
	/// Reject products carrying ANY of these ribbon UUIDs. PHP `notRibbon`.
	pub not_ribbon_uuids: Option<Vec<String>>,
	/// Same as `ribbon_uuids` but for `eshop_internalribbon` (PHP `internalRibbon`).
	pub internal_ribbon_uuids: Option<Vec<String>>,
	/// Negation of `internal_ribbon_uuids` (PHP `notInternalRibbon`).
	pub not_internal_ribbon_uuids: Option<Vec<String>>,

	/// `visibilityListItem.hidden` must equal this value (on at least one matching VL item).
	/// Mirrors `allowedCollectionFilterColumns['hidden']`.
	pub hidden: Option<bool>,
	/// `visibilityListItem.hiddenInMenu`.
	pub hidden_in_menu: Option<bool>,
	/// `visibilityListItem.recommended`.
	pub recommended: Option<bool>,
	/// `visibilityListItem.unavailable`.
	pub unavailable: Option<bool>,
	/// `displayAmount.isSold` must equal this value (0 = in stock, 1 = sold out, 2 = unknown).
	/// Mirrors `allowedCollectionFilterColumns['isSold']`.
	pub is_sold: Option<u8>,
	/// `this.fk_masterProduct IS NULL` (pouze master) nebo `IS NOT NULL` (pouze slave). `None` =
	/// filter neaktivní. Mirror PHP `allowedCollectionFilterExpressions['masterProduct']`
	/// (LiveProductsProvider:2105–2110) — strict bool porovnání, nestrictní (truthy) hodnoty PHP
	/// provider ignoruje, tak je dropuje i PHP proxy před forwardem.
	pub master_product: Option<bool>,

	/// `inStock` filter — mirror PHP `ProductRepository::filterInStock` (1172–1179):
	/// pass když `fk_displayAmount IS NULL OR eshop_displayamount.isSold = 0`. `None`/`Some(false)` =
	/// filter no-op, `Some(true)` = aktivuje filter.
	pub in_stock: Option<bool>,

	/// `related` filter — mirror `ProductRepository::filterRelated`:
	/// `WHERE this.uuid != exclude_uuid AND productPrimaryCategory.fk_category = primary_category_uuid`.
	/// `productPrimaryCategory` je per-categoryType — PHP používá `shopperUser->getMainCategoryType()`,
	/// proxy forwarduje PK jako `main_category_type_pk` na requestu.
	pub related: Option<RelatedFilter>,

	/// `relatedSlave` filter — mirror `ProductRepository::filterRelatedSlave` (1196–1202):
	/// `JOIN eshop_related ON this.uuid=related.fk_slave WHERE related.fk_type=$typeUuid AND related.fk_master=$masterUuid`.
	pub related_slave: Option<RelatedSlaveFilter>,

	/// `relatedTypeMaster` filter — mirror `ProductRepository::filterRelatedTypeMaster` (1225–1236):
	/// `JOIN eshop_related ON this.uuid=related.fk_slave WHERE related.fk_master=$masterUuid AND related.fk_type=$typeUuid`.
	/// Sémanticky stejné jako `related_slave` (jiné pořadí argumentů v PHP), proto sahá do
	/// stejného snapshot indexu `related_slaves_by_type_master`.
	pub related_type_master: Option<RelatedTypeMasterFilter>,

	/// `relatedTypeSlave` filter — mirror `ProductRepository::filterRelatedTypeSlave` (1238–1249):
	/// `JOIN eshop_related ON this.uuid=related.fk_master WHERE related.fk_slave=$slaveUuid AND related.fk_type=$typeUuid`.
	/// Vrací produkty, které jsou MASTER v relaci s daným slave. Snapshot lookup
	/// `related_masters_by_type_slave[(type_uuid, slave_uuid)] → bitmap master ProductIdx`.
	pub related_type_slave: Option<RelatedTypeSlaveFilter>,

	/// `crossSellFilter` — mirror `ProductRepository::filterCrossSellFilter` (1157–1170).
	/// Path je rozdělen na 4-char chunky; produkt prochází pokud má kategorii s `path LIKE '%chunk'`
	/// pro aspoň jeden chunk, AND `this.uuid != exclude_uuid`.
	pub cross_sell: Option<CrossSellFilter>,

	/// `toners` filter — mirror `ProductRepository::filterToners` (1207–1212, @deprecated): hardcoded
	/// `related.fk_type = 'tonerForPrinter'`. Hodnota je single UUID (printer master). Daemon
	/// ji namapuje na `related_masters_by_type_slave[("tonerForPrinter", value)]` (= produkty,
	/// které jsou MASTER tonerForPrinter relace pro daný printer).
	pub toners: Option<String>,

	/// `compatiblePrinters` filter — mirror `ProductRepository::filterCompatiblePrinters`
	/// (1217–1223, @deprecated): hardcoded `related.fk_type = 'tonerForPrinter'`, lookup z opačné
	/// strany (`related.fk_master = $value`). Daemon: `related_slaves_by_type_master[("tonerForPrinter", value)]`.
	pub compatible_printers: Option<String>,

	/// `relatedTextSlave` filter — mirror `ProductRepository::filterRelatedTextSlave` (1255–1267):
	/// matchuje konkrétní text-only `eshop_related` row (`related.uuid = $rowUuid AND fk_type = $typeCode
	/// AND fk_slave IS NULL`).
	pub related_text_slave: Option<RelatedTextSlaveFilter>,

	/// `relatedTextSlaveByName` filter — mirror `ProductRepository::filterRelatedTextSlaveByName`
	/// (1273–1285): matchuje text-only relace podle `slaveName + fk_type` (vrátí všechny mastery
	/// se stejným `slaveName` v daném typu).
	pub related_text_slave_by_name: Option<RelatedTextSlaveByNameFilter>,

	/// `similarProducts` filter — mirror `ProductRepository::filterSimilarProducts` (1287–1293):
	/// vrátí produkty, které jsou v `eshop_related` přes nějaký type s `similar = 1` s daným
	/// produktem (z obou stran), AND `this.uuid != $value`. Hodnota je UUID referenčního produktu.
	pub similar_products: Option<String>,
}

#[derive(Debug, Clone, Serialize, Deserialize)]
#[serde(rename_all = "camelCase", deny_unknown_fields)]
pub struct RelatedFilter {
	/// UUID produktu, který má být ze seznamu vyloučen (PHP `whereNot('this.uuid', $values['uuid'])`).
	pub exclude_uuid: String,
	/// UUID primární kategorie, kterou musí mít produkt přiřazenou (pro daný `main_category_type_pk`).
	pub primary_category_uuid: String,
	/// PK `eshop_categorytype` — z PHP `ShopperUser::getMainCategoryType()->getPK()`. Pokud daemon
	/// tento type nezná (nebo není v loaderu), vrátí fallback required.
	pub main_category_type_pk: String,
}

#[derive(Debug, Clone, Serialize, Deserialize)]
#[serde(rename_all = "camelCase", deny_unknown_fields)]
pub struct RelatedSlaveFilter {
	/// `related.fk_type` — UUID `eshop_relatedtype`.
	pub type_uuid: String,
	/// `related.fk_master` — UUID master produktu.
	pub master_uuid: String,
}

#[derive(Debug, Clone, Serialize, Deserialize)]
#[serde(rename_all = "camelCase", deny_unknown_fields)]
pub struct RelatedTypeMasterFilter {
	/// `related.fk_type` — UUID `eshop_relatedtype`.
	pub type_uuid: String,
	/// `related.fk_master` — UUID master produktu, jehož slave produkty hledáme.
	pub master_uuid: String,
}

#[derive(Debug, Clone, Serialize, Deserialize)]
#[serde(rename_all = "camelCase", deny_unknown_fields)]
pub struct RelatedTypeSlaveFilter {
	/// `related.fk_type` — UUID `eshop_relatedtype`.
	pub type_uuid: String,
	/// `related.fk_slave` — UUID slave produktu, jehož master produkty hledáme.
	pub slave_uuid: String,
}

#[derive(Debug, Clone, Serialize, Deserialize)]
#[serde(rename_all = "camelCase", deny_unknown_fields)]
pub struct RelatedTextSlaveFilter {
	/// `eshop_related.uuid` — primary key konkrétního row.
	pub row_uuid: String,
	/// `eshop_relatedtype.uuid` — defensive check že daný row patří k tomuto typu (PHP
	/// `where('related.fk_type', $value[1])`). Mismatch = filter clear.
	pub type_uuid: String,
}

#[derive(Debug, Clone, Serialize, Deserialize)]
#[serde(rename_all = "camelCase", deny_unknown_fields)]
pub struct RelatedTextSlaveByNameFilter {
	/// `eshop_related.slaveName` — text label k matchování.
	pub slave_name: String,
	/// `eshop_relatedtype.uuid` — typ relace.
	pub type_uuid: String,
}

#[derive(Debug, Clone, Serialize, Deserialize)]
#[serde(rename_all = "camelCase", deny_unknown_fields)]
pub struct CrossSellFilter {
	/// Category path (multiple-of-4 chars); rozdělen na 4-char chunky.
	pub path: String,
	/// UUID aktuálního produktu, který má být vyloučen.
	pub exclude_uuid: String,
}

/// Mirror of PHP `ShopperUser` price-visibility settings. Drives the `setProductsConditions`
/// pricelist OR-expression (see `ProductRepository::setProductsConditions`) and the
/// `priceFrom`/`priceTo`/`priceGt` `show_vat` routing (`ProductList.php:139-144`).
///
/// ## Defaults (parity with PHP `ShopperUser`)
/// - `show_vat = true` (Czech B2C default)
/// - everything else `false`
///
/// ## Important: PHP `setProductsConditions` has an if-without-else cascade (lines 727–739)
/// so when both `show_vat` and `show_without_vat` are true the **third** branch wins —
/// only `price > 0` is added, NOT both. We replicate that bug for 1:1 parity; see
/// `has_any_price_mask` docstring.
#[derive(Debug, Copy, Clone, Serialize, Deserialize)]
#[serde(rename_all = "camelCase", deny_unknown_fields)]
pub struct PriceVisibility {
	/// PHP `ShopperUser::getShowZeroPrices()`. When `true`, price rows with `0` are accepted.
	/// Default: `false` (hide free/placeholder SKUs).
	pub show_zero_prices: bool,
	/// PHP `ShopperUser::getShowVat()`. When `true` the VAT-inclusive price is the one shown /
	/// compared by `priceFrom`/`priceTo`/`priceGt`. Default: `true`.
	pub show_vat: bool,
	/// PHP `ShopperUser::getShowWithoutVat()`. Default: `false`.
	pub show_without_vat: bool,
	/// PHP `ShopperUser::canViewHiddenPrices()`. When `true`, rows with `hidden = 1` on the
	/// pricelist join still count towards `setProductsConditions`. Default: `false`.
	pub include_hidden_prices: bool,
}

impl Default for PriceVisibility {
	fn default() -> Self {
		Self {
			show_zero_prices: false,
			show_vat: true,
			show_without_vat: false,
			include_hidden_prices: false,
		}
	}
}

/// Per-customer modifiers applied after the priority-first pick.
///
/// Shape mirrors `LiveProductsProvider::computeEffectivePrices` (PHP lines 1080–1090). The
/// daemon does not consult `ShopperUser` — the PHP proxy resolves all of these from the
/// current session (customer, customerGroup, discountCoupon, currency) and forwards them in
/// every request. Defaults are the "no modifier applied" identity: 0 % discount, 0 % surcharge,
/// `None` currency rate, precision 2 (the standard eshop_currency default).
#[derive(Debug, Copy, Clone, Serialize, Deserialize)]
#[serde(rename_all = "camelCase", deny_unknown_fields)]
pub struct PriceModifiers {
	/// Global discount percent resolved from customer / customer group / discount coupon
	/// (`ProductRepository::getDiscountPct`). `0` means no customer-level discount.
	pub discount_level_pct: u8,
	/// Upper bound applied to `ProductRow::discount_level_pct` before it is compared against
	/// `discount_level_pct`. PHP: `customer->maxDiscountProductPct ?? customerGroup->defaultMaxDiscountProductPct ?? 100`.
	pub max_product_discount_level: u8,
	/// Surcharge percent (not a factor) resolved from customer
	/// (`ProductRepository::getSurchargePct`). PHP computes `price /= (1 - pct/100)` when
	/// `pricelist.allow_surcharge && pct > 0`. f64 (not f32) so values near 100 (near-zero
	/// divisor region) stay bit-identical with PHP's double arithmetic.
	pub surcharge_level_pct: f64,
	/// Currency conversion rate (`currency.convertRatio`). `None` means "no conversion" — the
	/// pricelist already has prices in the target currency.
	pub currency_rate: Option<f64>,
	/// Decimal precision for every intermediate `round()` call. PHP `currency.calculationPrecision`.
	pub calculation_precision: u8,
}

impl Default for PriceModifiers {
	fn default() -> Self {
		Self {
			discount_level_pct: 0,
			max_product_discount_level: 100,
			surcharge_level_pct: 0.0,
			currency_rate: None,
			calculation_precision: 2,
		}
	}
}

#[derive(Debug, Copy, Clone, Default, Serialize, Deserialize)]
#[serde(rename_all = "UPPERCASE")]
pub enum OrderDirection {
	#[default]
	Asc,
	Desc,
}

/// Response body for a products query. Shape mirrors
/// `GeneralProductsCacheProvider::getProductsFromCacheTable`'s PHPDoc return type.
#[derive(Debug, Clone, Default, Serialize, Deserialize)]
#[serde(rename_all = "camelCase")]
pub struct GetProductsResponse {
	pub product_pks: Vec<String>,
	pub attribute_values_counts: HashMap<String, u64>,
	pub display_amounts_counts: HashMap<String, u64>,
	pub display_deliveries_counts: HashMap<String, u64>,
	pub producers_counts: HashMap<String, u64>,
	#[serde(skip_serializing_if = "Option::is_none")]
	pub categories_counts: Option<HashMap<String, u64>>,
	pub price_min: f64,
	pub price_max: f64,
	pub price_vat_min: f64,
	pub price_vat_max: f64,
	/// Echoed back to PHP. Under `false` the payload above is authoritative. Under `true`,
	/// everything else is undefined and PHP must delegate to `LiveProductsProvider`.
	pub fallback_required: bool,
}

// -------- Get category count --------

#[derive(Debug, Clone, Default, Serialize, Deserialize)]
#[serde(rename_all = "camelCase", deny_unknown_fields)]
pub struct GetCategoryCountRequest {
	pub pricelist_pks: Vec<String>,
	pub visibility_list_pks: Vec<String>,
	pub filters: FilterPayload,
	#[serde(default)]
	pub dynamic_filter_attributes: Option<HashMap<String, Vec<String>>>,

	/// Mirror of `GetProductsRequest::price_visibility`. Ovlivňuje `has_any_price_mask`
	/// (zero-price / VAT / hidden routing) — bez něj by count pro B2B session byl spočtený
	/// s B2C defaulty a lišil se od PHP listingu.
	#[serde(default)]
	pub price_visibility: PriceVisibility,

	/// Per-customer price modifiers (discount/surcharge/currency) — musí matchovat hodnoty
	/// poslané v hlavním `GetProductsRequest`, jinak by `apply_price_band` (priceFrom/priceTo)
	/// vyhodnotila kategorii odlišně než hlavní listing.
	#[serde(default)]
	pub price_modifiers: PriceModifiers,

	/// Customer favourite pricelists — používané restrictive filtry `contract` / `notPublic`
	/// (produkt s restricted ribbon prochází jen když jeho winning pricelist patří mezi tyto).
	#[serde(default)]
	pub favourite_pricelist_pks: Vec<String>,

	/// UUID internal ribbonu pro restrictive filter `contract` (viz `apply_restrictive_filters`).
	#[serde(default)]
	pub contract_ribbon_pk: Option<String>,

	/// UUID internal ribbonu pro restrictive filter `notPublic` (stejná sémantika jako contract).
	#[serde(default)]
	pub not_public_ribbon_pk: Option<String>,

	/// Project authorization (customer IČ × product `projectIcs`) — viz `apply_restrictive_filters`.
	#[serde(default)]
	pub project_filter: Option<ProjectFilter>,
}

// -------- Get all category counts (batched) --------

/// Batched mirror of `GetCategoryCountRequest`. Sémanticky identická filter sada —
/// caller typicky vynechá `filters.category_uuids`, aby dostal counts pro všechny
/// kategorie naráz; pokud ho pošle, daemon omezí mask na subtree té kategorie.
#[derive(Debug, Clone, Default, Serialize, Deserialize)]
#[serde(rename_all = "camelCase", deny_unknown_fields)]
pub struct GetAllCategoryCountsRequest {
	pub pricelist_pks: Vec<String>,
	pub visibility_list_pks: Vec<String>,
	pub filters: FilterPayload,
	#[serde(default)]
	pub dynamic_filter_attributes: Option<HashMap<String, Vec<String>>>,
	#[serde(default)]
	pub price_visibility: PriceVisibility,

	/// Viz `GetCategoryCountRequest::price_modifiers`. Plná pricing pipeline závisí na nich.
	#[serde(default)]
	pub price_modifiers: PriceModifiers,

	/// Viz `GetCategoryCountRequest::favourite_pricelist_pks`.
	#[serde(default)]
	pub favourite_pricelist_pks: Vec<String>,

	/// Viz `GetCategoryCountRequest::contract_ribbon_pk`.
	#[serde(default)]
	pub contract_ribbon_pk: Option<String>,

	/// Viz `GetCategoryCountRequest::not_public_ribbon_pk`.
	#[serde(default)]
	pub not_public_ribbon_pk: Option<String>,

	/// Viz `GetCategoryCountRequest::project_filter`.
	#[serde(default)]
	pub project_filter: Option<ProjectFilter>,
}

impl GetProductsRequest {
	/// Sanity-check shape invariants. Dřívější `ensure_supported` odmítal custom expression
	/// names; po M3 jsou `contract` / `notPublic` / `project` / `uuidField` nativně
	/// implementované, takže bezpodmínečně vrací Ok. Metoda zůstává pro future guards.
	#[allow(clippy::unnecessary_wraps)]
	pub const fn ensure_supported(&self) -> Result<()> {
		Ok(())
	}
}

impl GetCategoryCountRequest {
	/// Postaví plný `GetProductsRequest` se všemi customer-specific poli (priceModifiers,
	/// favourites, contract/notPublic/project) — daemon-side count tak prochází identickou
	/// pipeline jako hlavní listing. Žádné defaults pro restrictive filtry: pokud je caller
	/// nepošle, count vyjde jiný než listing a invariant "počty přesně sedí" se rozpadne.
	#[must_use]
	pub fn to_pseudo_request(&self) -> GetProductsRequest {
		GetProductsRequest {
			pricelist_pks: self.pricelist_pks.clone(),
			visibility_list_pks: self.visibility_list_pks.clone(),
			filters: self.filters.clone(),
			dynamic_filter_attributes: self.dynamic_filter_attributes.clone(),
			price_modifiers: self.price_modifiers,
			price_visibility: self.price_visibility,
			favourite_pricelist_pks: self.favourite_pricelist_pks.clone(),
			contract_ribbon_pk: self.contract_ribbon_pk.clone(),
			not_public_ribbon_pk: self.not_public_ribbon_pk.clone(),
			project_filter: self.project_filter.clone(),
			..GetProductsRequest::default()
		}
	}
}

impl GetAllCategoryCountsRequest {
	/// Viz `GetCategoryCountRequest::to_pseudo_request` — identická sémantika, jiný request typ.
	#[must_use]
	pub fn to_pseudo_request(&self) -> GetProductsRequest {
		GetProductsRequest {
			pricelist_pks: self.pricelist_pks.clone(),
			visibility_list_pks: self.visibility_list_pks.clone(),
			filters: self.filters.clone(),
			dynamic_filter_attributes: self.dynamic_filter_attributes.clone(),
			price_modifiers: self.price_modifiers,
			price_visibility: self.price_visibility,
			favourite_pricelist_pks: self.favourite_pricelist_pks.clone(),
			contract_ribbon_pk: self.contract_ribbon_pk.clone(),
			not_public_ribbon_pk: self.not_public_ribbon_pk.clone(),
			project_filter: self.project_filter.clone(),
			..GetProductsRequest::default()
		}
	}
}
