//! Runtime configuration loaded from `.env` in the binary's working directory.
//!
//! The file is optional — only DB credentials (`DB_HOST`, `DB_USER`, `DB_PASSWORD`, `DB_NAME`)
//! are required and must come from the environment (typically `<binary dir>/.env`). All other
//! values have defaults; see `.env.example` for the full list and recommended values from
//! `/home/petr/abel/config/general.local.neon` (section `database`).

use std::{env, path::PathBuf, time::Duration};

use anyhow::{Context, Result};

#[derive(Debug, Clone)]
pub struct Config {
	pub database: DatabaseConfig,
	pub socket_path: PathBuf,
	/// How often drift probe runs. Cheap (9 COUNT/MAX queries, sub-100 ms).
	pub quick_check_interval: Duration,
	/// Floor between consecutive rebuilds — rate limit during import storm.
	pub min_rebuild_interval: Duration,
	/// Ceiling without any rebuild — safety net against UPDATE blind-spot in drift probe.
	pub max_fresh_interval: Duration,
	pub log_level: String,
}

#[derive(Debug, Clone)]
pub struct DatabaseConfig {
	pub host: String,
	pub port: u16,
	pub user: String,
	pub password: String,
	pub database: String,
	pub connect_timeout: Duration,
	pub pool_max: u32,
}

impl Config {
	/// Load from environment (populated by `dotenvy::dotenv()` in `main.rs`).
	///
	/// Missing required values fail fast — the daemon refuses to start with a half-configured state.
	pub fn from_env() -> Result<Self> {
		// `QUICK_CHECK_INTERVAL_SECS` je nový název, `REFRESH_INTERVAL_SECS` zůstává jako legacy
		// alias — produkce ho má už nastavený v `.env` souborech a neměli bychom forcovat migraci.
		// Přesná preference: pokud je nastaven `QUICK_CHECK_INTERVAL_SECS`, vyhrává; jinak
		// `REFRESH_INTERVAL_SECS`; jinak default 60 s.
		let quick_check_secs = match env::var("QUICK_CHECK_INTERVAL_SECS") {
			Ok(v) => v.parse::<u64>().map_err(|e| anyhow::anyhow!("QUICK_CHECK_INTERVAL_SECS invalid: {e}"))?,
			Err(_) => env_parse::<u64>("REFRESH_INTERVAL_SECS", 60)?,
		};

		Ok(Self {
			database: DatabaseConfig::from_env()?,
			socket_path: PathBuf::from(env::var("SOCKET_PATH").unwrap_or_else(|_| "/tmp/abel-products-daemon.sock".to_string())),
			quick_check_interval: Duration::from_secs(quick_check_secs),
			min_rebuild_interval: Duration::from_secs(env_parse::<u64>("MIN_REBUILD_INTERVAL_SECS", 120)?),
			max_fresh_interval: Duration::from_secs(env_parse::<u64>("MAX_FRESH_INTERVAL_SECS", 300)?),
			log_level: env::var("LOG_LEVEL").unwrap_or_else(|_| "info".to_string()),
		})
	}
}

impl DatabaseConfig {
	fn from_env() -> Result<Self> {
		Ok(Self {
			host: env_required("DB_HOST")?,
			port: env_parse::<u16>("DB_PORT", 3306)?,
			user: env_required("DB_USER")?,
			password: env_required("DB_PASSWORD")?,
			database: env_required("DB_NAME")?,
			connect_timeout: Duration::from_secs(env_parse::<u64>("DB_CONNECT_TIMEOUT_SECS", 5)?),
			pool_max: env_parse::<u32>("DB_POOL_MAX", 4)?,
		})
	}

	/// Build a `mysql_async::OptsBuilder` from the config.
	///
	/// Kept separate from the config struct so the DB layer owns driver-specific options.
	/// NOTE: mysql_async 0.34 does not expose a `tcp_connect_timeout` on OptsBuilder. Timeouts
	/// are enforced by the caller via `tokio::time::timeout` when needed. If a future version
	/// adds builder-level support, wire `self.connect_timeout` here.
	#[must_use]
	pub fn to_mysql_opts(&self) -> mysql_async::OptsBuilder {
		let _ = self.connect_timeout;
		mysql_async::OptsBuilder::default()
			.ip_or_hostname(self.host.clone())
			.tcp_port(self.port)
			.user(Some(self.user.clone()))
			.pass(Some(self.password.clone()))
			.db_name(Some(self.database.clone()))
	}
}

fn env_required(key: &'static str) -> Result<String> {
	env::var(key).with_context(|| format!("required env var `{key}` is missing"))
}

fn env_parse<T: std::str::FromStr>(key: &'static str, default: T) -> Result<T>
where
	T::Err: std::fmt::Display,
{
	match env::var(key) {
		Ok(s) => s
			.parse::<T>()
			.map_err(|e| anyhow::anyhow!("env var `{key}` invalid: {e}")),
		Err(_) => Ok(default),
	}
}
