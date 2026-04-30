//! Connection-pool wrapper.
//!
//! `mysql_async::Pool` is already cheap to clone (it's `Arc` internally). We wrap it to:
//! - own the `OptsBuilder` assembly in one place
//! - expose `Pool::connect()` as an async constructor (vs. the driver's `Pool::new()`)
//! - provide a single `load_catalog_raw()` facade the snapshot builder calls
//! - keep the `DatabaseConfig` → driver translation inside this module so upstream layers
//!   don't see `mysql_async` types

use std::sync::Arc;

use mysql_async::{Conn, Opts};

use crate::{
	config::DatabaseConfig,
	db::loaders::{self, DriftSignals, RawCatalog},
	error::SnapshotBuildError,
};

#[derive(Clone)]
pub struct Pool {
	inner: Arc<mysql_async::Pool>,
}

impl Pool {
	pub async fn connect(config: &DatabaseConfig) -> Result<Self, SnapshotBuildError> {
		let pool_max = usize::try_from(config.pool_max).unwrap_or(4).max(1);
		let constraints = mysql_async::PoolConstraints::new(1, pool_max).ok_or_else(|| SnapshotBuildError::Decode {
			field: "db.pool_max",
			reason: format!("invalid pool size: min=1 max={pool_max}"),
		})?;
		let mut opts = config.to_mysql_opts();
		opts = opts.pool_opts(mysql_async::PoolOpts::default().with_constraints(constraints));
		let pool = mysql_async::Pool::new(Opts::from(opts));
		// Eagerly open one connection to surface misconfiguration at startup.
		let _: Conn = pool.get_conn().await?;
		Ok(Self { inner: Arc::new(pool) })
	}

	pub async fn conn(&self) -> Result<Conn, SnapshotBuildError> {
		Ok(self.inner.get_conn().await?)
	}

	/// Full catalog fetch. Delegates to `loaders::load_catalog_raw`, which runs all the
	/// SELECTs concurrently via `tokio::try_join!`.
	pub async fn load_catalog_raw(&self) -> Result<RawCatalog, SnapshotBuildError> {
		loaders::load_catalog_raw(self).await
	}

	/// Cheap-query drift probe — runs six `COUNT/MAX` queries and returns a signal tuple.
	pub async fn load_drift(&self) -> Result<DriftSignals, SnapshotBuildError> {
		loaders::load_drift(self).await
	}
}
