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
#[serde(rename_all = "camelCase")]
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
#[serde(rename_all = "camelCase")]
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
#[serde(rename_all = "camelCase", default)]
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

	/// `crossSellFilter` — mirror `ProductRepository::filterCrossSellFilter` (1157–1170).
	/// Path je rozdělen na 4-char chunky; produkt prochází pokud má kategorii s `path LIKE '%chunk'`
	/// pro aspoň jeden chunk, AND `this.uuid != exclude_uuid`.
	pub cross_sell: Option<CrossSellFilter>,
}

#[derive(Debug, Clone, Serialize, Deserialize)]
#[serde(rename_all = "camelCase")]
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
#[serde(rename_all = "camelCase")]
pub struct RelatedSlaveFilter {
	/// `related.fk_type` — UUID `eshop_relatedtype`.
	pub type_uuid: String,
	/// `related.fk_master` — UUID master produktu.
	pub master_uuid: String,
}

#[derive(Debug, Clone, Serialize, Deserialize)]
#[serde(rename_all = "camelCase")]
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
#[serde(rename_all = "camelCase")]
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
#[serde(rename_all = "camelCase")]
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
#[serde(rename_all = "camelCase")]
pub struct GetCategoryCountRequest {
	pub pricelist_pks: Vec<String>,
	pub visibility_list_pks: Vec<String>,
	pub filters: FilterPayload,
	#[serde(default)]
	pub dynamic_filter_attributes: Option<HashMap<String, Vec<String>>>,

	/// Mirror of `GetProductsRequest::price_visibility`. Ovlivňuje `has_any_price_mask`
	/// (zero-price / VAT / hidden routing) — bez něj by count pro B2B session byl spočtený
	/// s B2C defaulty a lišil se od PHP `LiveProductsProvider::fetchAllCategoryCountsDirect`.
	#[serde(default)]
	pub price_visibility: PriceVisibility,
}

// -------- Get all category counts (batched) --------

/// Batched mirror of `GetCategoryCountRequest`. Sémanticky identická filter sada —
/// caller typicky vynechá `filters.category_uuids`, aby dostal counts pro všechny
/// kategorie naráz; pokud ho pošle, daemon omezí mask na subtree té kategorie.
#[derive(Debug, Clone, Default, Serialize, Deserialize)]
#[serde(rename_all = "camelCase")]
pub struct GetAllCategoryCountsRequest {
	pub pricelist_pks: Vec<String>,
	pub visibility_list_pks: Vec<String>,
	pub filters: FilterPayload,
	#[serde(default)]
	pub dynamic_filter_attributes: Option<HashMap<String, Vec<String>>>,
	#[serde(default)]
	pub price_visibility: PriceVisibility,
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
