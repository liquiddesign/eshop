//! MariaDB connectivity — pool, loaders, raw row types.
//!
//! The daemon treats MariaDB as **read-only**; all writes remain with PHP. This module is a
//! thin wrapper over `mysql_async` that:
//!
//! 1. Opens a small connection pool (typically ≤ 4 conns — parallel loaders during a rebuild
//!    and occasional drift probes in steady state).
//! 2. Runs the `load_*` queries that feed `SnapshotBuilder`.
//! 3. Exposes `DriftSignals` — the tuple the refresher polls every `REFRESH_INTERVAL_SECS`.

pub mod loaders;
pub mod pool;

pub use self::{
	loaders::{
		DriftSignals, RawCatalog, RawCategory, RawDisplayAmount, RawPrice, RawPricelist, RawProduct, RawVisibilityItem,
	},
	pool::Pool,
};
