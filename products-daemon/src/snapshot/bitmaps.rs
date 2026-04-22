//! Inverted bitmap indexes for attribute/category/producer/displayAmount filtering.
//!
//! Each unique value gets a `RoaringBitmap` listing the `ProductIdx` values with that
//! attribute. Filtering a request then reduces to bitmap AND across the value buckets,
//! which is `O(|result|)` regardless of total catalog size — the hot path for a
//! guest customer filtering by "color=red & category=/elektro" is microseconds.

use ahash::AHashMap;
use roaring::RoaringBitmap;

use crate::snapshot::ProductIdx;

/// Name -> bitmap lookup for a single dimension (e.g. all attribute values).
#[derive(Debug, Default, Clone)]
pub struct BitmapIndex {
	inner: AHashMap<u32, RoaringBitmap>,
}

impl BitmapIndex {
	#[must_use]
	pub fn new() -> Self {
		Self::default()
	}

	pub fn insert(&mut self, key: u32, product: ProductIdx) {
		self.inner.entry(key).or_default().insert(product);
	}

	#[must_use]
	pub fn get(&self, key: u32) -> Option<&RoaringBitmap> {
		self.inner.get(&key)
	}

	#[must_use]
	pub fn len(&self) -> usize {
		self.inner.len()
	}

	#[must_use]
	pub fn is_empty(&self) -> bool {
		self.inner.is_empty()
	}

	/// Bitwise OR across the union of given keys. Used for OR-of-values semantics within a
	/// single filter dimension (e.g. `color IN (red, blue)` becomes `color[red] | color[blue]`).
	#[must_use]
	pub fn union_of(&self, keys: &[u32]) -> RoaringBitmap {
		let mut acc = RoaringBitmap::new();
		for k in keys {
			if let Some(bm) = self.inner.get(k) {
				acc |= bm;
			}
		}
		acc
	}

	/// Iterate (key, bitmap) — used for facet count computation where we need
	/// candidate ∩ bucket per value.
	pub fn iter(&self) -> impl Iterator<Item = (u32, &RoaringBitmap)> {
		self.inner.iter().map(|(k, v)| (*k, v))
	}

	/// Freeze into a deterministic BTreeMap — used for snapshot hashing / stable iteration
	/// in tests.
	#[cfg(test)]
	pub fn to_sorted(&self) -> std::collections::BTreeMap<u32, RoaringBitmap> {
		self.inner.iter().map(|(k, v)| (*k, v.clone())).collect()
	}

	pub fn shrink_to_fit(&mut self) {
		self.inner.shrink_to_fit();
		// RoaringBitmap in 0.10 exposes no public `optimize` / `run_optimize` in the default
		// feature set; container choice is automatic based on density. Nothing to do per bitmap.
	}
}
