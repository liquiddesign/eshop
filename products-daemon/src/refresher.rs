//! Polling refresher — rebuilds the snapshot when cheap drift signals change.
//!
//! Invariant: the currently published snapshot is never mutated. Instead, we build a new
//! `CatalogSnapshot` offline and hot-swap the `Arc` via `ArcSwap::store`. Existing readers
//! continue working against the old snapshot; once their last `load()` drops, it's freed.

use std::{sync::Arc, time::Duration};

use arc_swap::ArcSwap;
use tokio::time::{interval, MissedTickBehavior};
use tracing::{error, info, instrument};

use crate::{db::Pool, snapshot::CatalogSnapshot};

pub struct Refresher {
	pool: Pool,
	catalog: Arc<ArcSwap<CatalogSnapshot>>,
	interval: Duration,
}

impl Refresher {
	#[must_use]
	pub const fn new(pool: Pool, catalog: Arc<ArcSwap<CatalogSnapshot>>, interval: Duration) -> Self {
		Self {
			pool,
			catalog,
			interval,
		}
	}

	/// Loop forever. `main` orchestrates shutdown via `tokio::select!`.
	#[instrument(skip(self), level = "info")]
	pub async fn run(self) {
		let mut ticker = interval(self.interval);
		ticker.set_missed_tick_behavior(MissedTickBehavior::Delay);

		// Skip the first immediate tick — the initial snapshot was just built at startup.
		ticker.tick().await;

		loop {
			ticker.tick().await;
			if let Err(err) = self.tick().await {
				error!(?err, "refresher tick failed — retrying on next interval");
			}
		}
	}

	async fn tick(&self) -> Result<(), crate::error::DaemonError> {
		let current_version = self.catalog.load().schema_version;
		let drift = self.pool.load_drift().await?;
		let new_version = hash_drift(&drift);

		if new_version == current_version {
			info!(version = current_version, "snapshot still fresh");
			return Ok(());
		}

		info!(
			old_version = current_version,
			new_version, "drift detected — rebuilding snapshot"
		);
		let new_snap = CatalogSnapshot::load_from_pool(&self.pool).await?;
		self.catalog.store(Arc::new(new_snap));
		Ok(())
	}
}

/// Same hash used in `SnapshotBuilder::build` — kept in sync so comparison is meaningful.
fn hash_drift(drift: &crate::db::DriftSignals) -> u64 {
	use std::hash::{Hash, Hasher};
	let mut h = ahash::AHasher::default();
	drift.hash(&mut h);
	h.finish()
}
