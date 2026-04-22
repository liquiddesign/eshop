//! UUID → dense integer interning pools.
//!
//! PHP stores product PKs as 32-character hex strings. Holding 186k of them as `String`
//! would cost ~9 MB (heap + alignment). Interning them into a dense `Vec<SmolStr>` and
//! storing `u32` indexes in hot rows:
//! - cuts hot-row memory 8× (24 B String vs 4 B index)
//! - improves cache locality for scans (4 B stride vs pointer-chase)
//! - lets us use `RoaringBitmap<u32>` directly for inverted indexes

use ahash::AHashMap;
use smol_str::SmolStr;

use crate::error::SnapshotBuildError;

/// Generic intern pool: lookup by `&str`, dense `u32` indexes, reverse-mapping via `Vec`.
#[derive(Debug, Default, Clone)]
pub struct InternPool {
	values: Vec<SmolStr>,
	// AHashMap over SmolStr keys — hashed by the string bytes; avoids cloning on insert.
	lookup: AHashMap<SmolStr, u32>,
	pool_name: &'static str,
	limit: u32,
}

impl InternPool {
	pub fn new(pool_name: &'static str, capacity: usize) -> Self {
		Self {
			values: Vec::with_capacity(capacity),
			lookup: AHashMap::with_capacity(capacity),
			pool_name,
			limit: u32::MAX,
		}
	}

	/// Cap the pool; inserting beyond returns `InternOverflow`. Used when the caller needs
	/// `u16` indexes (e.g. pricelists, where `PricelistIdx = u16`).
	#[must_use]
	pub fn with_limit(mut self, limit: u32) -> Self {
		self.limit = limit;
		self
	}

	pub fn intern(&mut self, value: &str) -> Result<u32, SnapshotBuildError> {
		if let Some(&idx) = self.lookup.get(value) {
			return Ok(idx);
		}
		let next = u32::try_from(self.values.len()).map_err(|_| SnapshotBuildError::InternOverflow {
			pool: self.pool_name,
			limit: self.limit as usize,
		})?;
		if next >= self.limit {
			return Err(SnapshotBuildError::InternOverflow {
				pool: self.pool_name,
				limit: self.limit as usize,
			});
		}
		let smol = SmolStr::new(value);
		self.values.push(smol.clone());
		self.lookup.insert(smol, next);
		Ok(next)
	}

	#[must_use]
	pub fn lookup(&self, value: &str) -> Option<u32> {
		self.lookup.get(value).copied()
	}

	#[must_use]
	pub fn get(&self, idx: u32) -> Option<&str> {
		self.values.get(idx as usize).map(SmolStr::as_str)
	}

	#[must_use]
	pub fn len(&self) -> usize {
		self.values.len()
	}

	#[must_use]
	pub fn is_empty(&self) -> bool {
		self.values.is_empty()
	}

	#[must_use]
	pub fn values(&self) -> &[SmolStr] {
		&self.values
	}
}

#[cfg(test)]
mod tests {
	use super::*;

	#[test]
	fn intern_assigns_stable_indexes() {
		let mut pool = InternPool::new("test", 8);
		let a = pool.intern("a").unwrap();
		let b = pool.intern("b").unwrap();
		let a_again = pool.intern("a").unwrap();
		assert_eq!(a, 0);
		assert_eq!(b, 1);
		assert_eq!(a_again, a);
		assert_eq!(pool.get(a), Some("a"));
	}

	#[test]
	fn intern_respects_limit() {
		let mut pool = InternPool::new("limited", 2).with_limit(2);
		pool.intern("x").unwrap();
		pool.intern("y").unwrap();
		let overflow = pool.intern("z");
		assert!(matches!(overflow, Err(SnapshotBuildError::InternOverflow { .. })));
	}
}
