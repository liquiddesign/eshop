//! Criterion benchmark for the hot filter+pricing pipeline.
//!
//! Target: p95 < 50 ms against a 50k-product / 256-pricelist synthetic snapshot.

use criterion::{criterion_group, criterion_main, Criterion};

fn hot_path_filter(_c: &mut Criterion) {
	// TODO(#M2-bench): seed a realistic fixture snapshot and benchmark the full pipeline.
	// criterion_group layout:
	//   c.bench_function("filter_guest_no_filters", |b| b.iter(|| ... ));
	//   c.bench_function("filter_with_3_attrs_and_category", |b| b.iter(|| ... ));
	//   c.bench_function("filter_b2b_with_custom_pricelist", |b| b.iter(|| ... ));
}

criterion_group!(benches, hot_path_filter);
criterion_main!(benches);
