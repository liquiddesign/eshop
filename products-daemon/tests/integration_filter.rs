//! Fixture-driven filter correctness tests.
//!
//! Compares the daemon's bitmap filter output against a golden set produced by
//! `LiveProductsProvider::getProductsFromCacheTable` on the same inputs — parity diff.

#[test]
#[ignore = "needs golden fixtures; M2+"]
fn category_filter_matches_php_output() {
	// TODO(#M2-filter): load fixtures/filter/*.json — one per scenario (guest, B2B tier 1, asistent).
	// Each fixture contains:
	//   - request: GetProductsRequest
	//   - expected: GetProductsResponse (as emitted by LiveProductsProvider in a recorded run)
	// Test asserts productPks (order-sensitive) and facet counts match byte-for-byte.
	unimplemented!("wire after M2 query engine fill-in")
}
