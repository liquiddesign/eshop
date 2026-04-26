//! Protocol wire format regression tests.
//!
//! These are the only tests that can run without DB fixtures — they exercise the
//! serde JSON shape contract that PHP's `RustDaemonClient` depends on.

use abel_products_daemon::protocol::{
	ErrorKind, ErrorResponse, FilterPayload, GetProductsRequest, GetProductsResponse, OkResponse, OrderDirection,
	PriceModifiers, RequestEnvelope, ResponseBody, ResponseEnvelope,
};

#[test]
fn ping_request_round_trips() {
	let env = RequestEnvelope::Ping;
	let json = serde_json::to_string(&env).unwrap();
	assert!(json.contains(r#""method":"ping""#), "encoded as: {json}");

	let decoded: RequestEnvelope = serde_json::from_str(&json).unwrap();
	assert!(matches!(decoded, RequestEnvelope::Ping));
}

#[test]
fn get_products_request_camel_case_fields() {
	let req = GetProductsRequest {
		pricelist_pks: vec!["pl1".into()],
		visibility_list_pks: vec!["vl1".into()],
		filters: FilterPayload {
			category_uuids: Some(vec!["c1".into()]),
			..Default::default()
		},
		dynamic_filter_attributes: None,
		price_modifiers: PriceModifiers {
			discount_level_pct: 10,
			max_product_discount_level: 100,
			surcharge_level_pct: 5.0,
			currency_rate: None,
			calculation_precision: 2,
		},
		order_by: Some("price".into()),
		order_direction: OrderDirection::Desc,
		count_categories: true,
		debug: false,
		order_uuids: None,
		favourite_pricelist_pks: Vec::new(),
		contract_ribbon_pk: None,
		not_public_ribbon_pk: None,
		project_filter: None,
		price_visibility: Default::default(),
	};
	let env = RequestEnvelope::GetProducts(req);
	let json = serde_json::to_string(&env).unwrap();
	// Matches what PHP RustProxyProductsProvider::buildRequest emits.
	assert!(json.contains("pricelistPks"), "{json}");
	assert!(json.contains("visibilityListPks"), "{json}");
	assert!(json.contains("categoryUuids"), "{json}");
	assert!(json.contains("orderDirection"), "{json}");
	assert!(json.contains(r#""orderDirection":"DESC""#), "{json}");
}

#[test]
fn fallback_required_response_shape() {
	let env = ResponseEnvelope::Ok(Box::new(OkResponse {
		protocol_version: abel_products_daemon::PROTOCOL_VERSION,
		body: ResponseBody::FallbackRequired {
			reason: "custom order".into(),
		},
		timings: None,
	}));
	let json = serde_json::to_string(&env).unwrap();
	assert!(json.contains(r#""type":"fallbackRequired""#), "{json}");
	assert!(json.contains(r#""reason":"custom order""#), "{json}");
}

#[test]
fn error_response_uses_snake_case_error_kind() {
	let env = ResponseEnvelope::Error(ErrorResponse {
		protocol_version: 1,
		error: ErrorKind::UnknownPricelist,
		message: "pk=abc".into(),
	});
	let json = serde_json::to_string(&env).unwrap();
	// `#[serde(rename_all = "snake_case")]` on ErrorKind.
	assert!(json.contains(r#""error":"unknown_pricelist""#), "{json}");
}

#[test]
fn products_response_serializes_categories_counts_only_when_present() {
	let mut resp = GetProductsResponse::default();
	resp.product_pks.push("prod-1".into());
	let without = serde_json::to_string(&resp).unwrap();
	assert!(!without.contains("categoriesCounts"), "absent: {without}");

	resp.categories_counts = Some(std::collections::HashMap::from([("cat-1".into(), 5)]));
	let with = serde_json::to_string(&resp).unwrap();
	assert!(with.contains("categoriesCounts"), "present: {with}");
}
