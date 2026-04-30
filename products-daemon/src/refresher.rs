//! Polling refresher — rebuilds the snapshot with rate-limited drift detection.
//!
//! Invariant: the currently published snapshot is never mutated. Instead, we build a new
//! `CatalogSnapshot` offline and hot-swap the `Arc` via `ArcSwap::store`. Existing readers
//! continue working against the old snapshot; once their last `load()` drops, it's freed.
//!
//! ## Rebuild cadence — three parameters
//!
//! | Parameter                | Role                                                  |
//! | ------------------------ | ----------------------------------------------------- |
//! | `quick_check_interval`   | How often drift probe runs (cheap COUNT/MAX queries). |
//! | `min_rebuild_interval`   | Floor between rebuilds — rate limit during imports.   |
//! | `max_fresh_interval`     | Ceiling without rebuild — safety net vs UPDATE.       |
//!
//! Drift probe detects INSERT/DELETE/schema changes via 9 index-only queries. It is blind to
//! in-place UPDATE of existing rows (notably `eshop_price.price`) — the safety net covers that
//! case by forcing a full rebuild every `max_fresh_interval` regardless of drift.
//!
//! Rate limit exists because a running import can flip drift every minute; without the floor,
//! the daemon would rebuild minute-by-minute for hours, churning DB IO and daemon RAM.
//!
//! ## Rebuild trigger branches (logged via `reason=…`)
//!
//! | `reason`     | Branch                                                                  |
//! | ------------ | ----------------------------------------------------------------------- |
//! | `ttl`        | `max_fresh_interval` ceiling reached — forced rebuild even sans drift.  |
//! | `drift`      | Quick-check probe saw INSERT/DELETE/schema change.                      |
//! | `rate_limited` | Drift seen but `min_rebuild_interval` floor not yet elapsed.          |
//! | `external`   | PHP volal `requestRebuild` RPC — `Notify::notified()` fired ticker.     |
//!
//! `external` triggers go through the same floor/ceiling logic as ticker triggers — spamming
//! `requestRebuild` cannot create rebuild storms (`MIN_REBUILD_INTERVAL_SECS` still applies).

use std::{
	sync::Arc,
	time::{Duration, Instant},
};

use arc_swap::ArcSwap;
use tokio::{
	sync::Notify,
	time::{interval, MissedTickBehavior},
};
use tracing::{error, info, instrument};

use crate::{db::Pool, metrics::DaemonMetrics, snapshot::CatalogSnapshot};

pub struct Refresher {
	pool: Pool,
	catalog: Arc<ArcSwap<CatalogSnapshot>>,
	metrics: Arc<DaemonMetrics>,
	quick_check_interval: Duration,
	min_rebuild_interval: Duration,
	max_fresh_interval: Duration,
	wakeup: Arc<Notify>,
}

/// What woke the refresher this iteration. Drives the `reason=…` log tag and lets us treat
/// external wakeups as "drift assumed seen" (skip the cheap probe — PHP wouldn't have called
/// us if there weren't writes to consume).
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
enum RebuildTrigger {
	/// Periodic `quick_check_interval` ticker — run the drift probe normally.
	Ticker,
	/// `Notify::notified()` fired — PHP requested a rebuild via RPC.
	External,
}

impl Refresher {
	// `Notify::new()` is non-const, so this constructor cannot be `const fn`.
	#[must_use]
	pub fn new(
		pool: Pool,
		catalog: Arc<ArcSwap<CatalogSnapshot>>,
		metrics: Arc<DaemonMetrics>,
		quick_check_interval: Duration,
		min_rebuild_interval: Duration,
		max_fresh_interval: Duration,
		wakeup: Arc<Notify>,
	) -> Self {
		Self {
			pool,
			catalog,
			metrics,
			quick_check_interval,
			min_rebuild_interval,
			max_fresh_interval,
			wakeup,
		}
	}

	/// Loop forever. `main` orchestrates shutdown via `tokio::select!`.
	#[instrument(skip(self), level = "info")]
	pub async fn run(self) {
		let mut ticker = interval(self.quick_check_interval);
		ticker.set_missed_tick_behavior(MissedTickBehavior::Delay);

		// Skip the first immediate tick — the initial snapshot was just built at startup.
		ticker.tick().await;

		// `last_rebuild` = Instant of the most recent `.store()`. Seeded to "now" because the
		// initial snapshot (built in `main`) counts as rebuild #0 for rate-limit purposes.
		let mut last_rebuild = Instant::now();

		loop {
			let trigger = tokio::select! {
				_ = ticker.tick() => RebuildTrigger::Ticker,
				() = self.wakeup.notified() => RebuildTrigger::External,
			};
			match self.tick(last_rebuild, trigger).await {
				Ok(RebuildOutcome::Rebuilt) => last_rebuild = Instant::now(),
				Ok(RebuildOutcome::Skipped) => {}
				Err(err) => {
					error!(?err, "refresher tick failed — retrying on next interval");
				}
			}
		}
	}

	async fn tick(
		&self,
		last_rebuild: Instant,
		trigger: RebuildTrigger,
	) -> Result<RebuildOutcome, crate::error::DaemonError> {
		let elapsed = last_rebuild.elapsed();

		// Ceiling: forced rebuild even when drift probe sees nothing.
		// This is the only path that invalidates an UPDATE-only change.
		if elapsed >= self.max_fresh_interval {
			info!(
				reason = "ttl",
				elapsed_s = elapsed.as_secs(),
				"rebuild forced by max_fresh_interval — UPDATE blind-spot safety net"
			);
			return self.rebuild().await.map(|()| RebuildOutcome::Rebuilt);
		}

		// External wakeup = "PHP just finished writes, please refresh now". We skip the cheap
		// drift probe because (a) PHP wouldn't have called us if no writes happened, and
		// (b) drift probe is INSERT/DELETE-only and could miss UPDATE-only changes anyway.
		// The floor below still applies — spamming requestRebuild can't storm rebuilds.
		if trigger == RebuildTrigger::External {
			if elapsed < self.min_rebuild_interval {
				let wait = self.min_rebuild_interval.saturating_sub(elapsed);
				info!(
					reason = "external",
					wait_s = wait.as_secs(),
					"external wakeup throttled by min_rebuild_interval"
				);
				return Ok(RebuildOutcome::Skipped);
			}

			info!(
				reason = "external",
				elapsed_s = elapsed.as_secs(),
				"rebuild due to external wakeup (requestRebuild RPC)"
			);
			return self.rebuild().await.map(|()| RebuildOutcome::Rebuilt);
		}

		let current_version = self.catalog.load().schema_version;
		let drift = self.pool.load_drift().await?;
		let new_version = hash_drift(&drift);
		let drift_changed = new_version != current_version;

		if !drift_changed {
			info!(version = current_version, "snapshot still fresh");
			return Ok(RebuildOutcome::Skipped);
		}

		// Floor: drift seen but we just rebuilt — wait out the rate limit.
		// Next tick will re-check and rebuild if drift still present.
		if elapsed < self.min_rebuild_interval {
			let wait = self.min_rebuild_interval.saturating_sub(elapsed);
			info!(
				reason = "rate_limited",
				wait_s = wait.as_secs(),
				old_version = current_version,
				new_version,
				"drift detected, rebuild throttled by min_rebuild_interval"
			);
			return Ok(RebuildOutcome::Skipped);
		}

		info!(
			reason = "drift",
			elapsed_s = elapsed.as_secs(),
			old_version = current_version,
			new_version,
			"rebuild due to drift"
		);
		self.rebuild().await.map(|()| RebuildOutcome::Rebuilt)
	}

	async fn rebuild(&self) -> Result<(), crate::error::DaemonError> {
		let new_snap = CatalogSnapshot::load_from_pool(&self.pool).await?;
		self.catalog.store(Arc::new(new_snap));
		self.metrics.record_snapshot();
		Ok(())
	}
}

#[derive(Debug, Clone, Copy, PartialEq, Eq)]
enum RebuildOutcome {
	Rebuilt,
	Skipped,
}

/// Same hash used in `SnapshotBuilder::build` — kept in sync so comparison is meaningful.
fn hash_drift(drift: &crate::db::DriftSignals) -> u64 {
	use std::hash::{Hash, Hasher};
	let mut h = ahash::AHasher::default();
	drift.hash(&mut h);
	h.finish()
}
