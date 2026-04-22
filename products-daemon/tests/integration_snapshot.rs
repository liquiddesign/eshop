//! End-to-end snapshot-build test against a fixtures database.
//!
//! Runs only when `ABEL_TEST_DSN` is exported (CI / local DDEV) — otherwise skipped so the
//! bare `cargo test` works on a fresh checkout.

#[tokio::test]
#[ignore = "needs ABEL_TEST_DSN pointing at a MariaDB with fixtures; M2+"]
async fn snapshot_loads_from_ddev_db() {
	// TODO(#M2-snapshot): implement once fixture DSN is wired.
	// Expected flow:
	//   1. Connect to test DSN.
	//   2. Build CatalogSnapshot.
	//   3. Assert product_count() / price_count() are in the expected ranges.
	//   4. Probe a known product: `snap.products.get(known_idx).unwrap().producer.is_some()`.
	//   5. Check drift hash is stable across two consecutive builds.
	unimplemented!("wire after M2 hot path stabilizes")
}
