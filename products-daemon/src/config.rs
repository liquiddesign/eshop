//! Runtime configuration loaded from `.env` in the binary's working directory.
//!
//! The file is expected to live at `<binary dir>/.env`; the user fills values from
//! `/home/petr/abel/config/general.local.neon` (section `database`). See `.env.example`.

use std::{env, path::PathBuf, time::Duration};

use anyhow::{Context, Result};

#[derive(Debug, Clone)]
pub struct Config {
	pub database: DatabaseConfig,
	pub socket_path: PathBuf,
	pub refresh_interval: Duration,
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
		Ok(Self {
			database: DatabaseConfig::from_env()?,
			socket_path: env_required("SOCKET_PATH").map(PathBuf::from)?,
			refresh_interval: Duration::from_secs(env_parse::<u64>("REFRESH_INTERVAL_SECS", 600)?),
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
