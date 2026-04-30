//! Criterion benchmark for full snapshot rebuild.
//!
//! Target: < 15 s against ddev fixtures (production will be slower but within REFRESH_INTERVAL_SECS).

use criterion::{criterion_group, criterion_main, Criterion};

fn build_snapshot(_c: &mut Criterion) {
	// TODO(#M2-bench): point at ABEL_TEST_DSN when available.
}

criterion_group!(benches, build_snapshot);
criterion_main!(benches);
