//! Binary entrypoint. `anyhow` is allowed here per ch. 4.
//!
//! Runtime wiring:
//! 1. Load `.env` next to the binary.
//! 2. Parse CLI args (`--once` runs a single snapshot build for benchmarking).
//! 3. Initialize tracing.
//! 4. Build the MariaDB pool.
//! 5. Build the initial catalog snapshot; fail-fast if it can't be built.
//! 6. Start the socket listener + refresher concurrently.
//! 7. Wait for SIGTERM/SIGINT and drain gracefully.

use std::{path::PathBuf, sync::Arc};

use anyhow::{Context, Result};
use arc_swap::ArcSwap;
use clap::Parser;
use tokio::signal;
use tracing::{info, warn};
use tracing_subscriber::EnvFilter;

use abel_products_daemon::{db::Pool, refresher::Refresher, server::Server, CatalogSnapshot, Config};

// jemalloc: lower peak RSS during snapshot hot-swap, faster short-lived alloc on query path.
#[cfg(not(target_env = "msvc"))]
#[global_allocator]
static GLOBAL: tikv_jemallocator::Jemalloc = tikv_jemallocator::Jemalloc;

#[derive(Debug, Parser)]
#[command(version, about = "Abel products catalog daemon")]
struct Cli {
	/// Override socket path from the environment (useful for dev/tests).
	#[arg(long, env = "SOCKET_PATH")]
	socket: Option<PathBuf>,

	/// Build the snapshot once and exit. Used by `cargo bench` to warm caches without a full server lifecycle.
	#[arg(long)]
	once: bool,

	/// Skip DB connections; load a fixtures snapshot instead. Dev only.
	#[arg(long)]
	benchmark_mode: bool,

	/// Path to the .env file. Defaults to `./.env` next to the binary.
	#[arg(long)]
	env_file: Option<PathBuf>,

	/// Redirect `tracing` output to the given file (appends). Default: stdout.
	/// Parent directory is created if missing. ANSI colors are disabled when logging to a file.
	#[arg(long, env = "LOG_FILE")]
	log_file: Option<PathBuf>,
}

fn main() -> Result<()> {
	let cli = Cli::parse();

	load_env(cli.env_file.as_deref())?;

	let mut config = Config::from_env().context("loading daemon config")?;
	if let Some(sock) = cli.socket {
		config.socket_path = sock;
	}

	init_tracing(&config.log_level, cli.log_file.as_deref())?;

	let runtime = tokio::runtime::Builder::new_multi_thread()
		.enable_all()
		.thread_name("abel-daemon")
		.build()
		.context("building tokio runtime")?;

	runtime.block_on(async_main(config, cli.once, cli.benchmark_mode))
}

async fn async_main(config: Config, once: bool, benchmark_mode: bool) -> Result<()> {
	info!(socket = %config.socket_path.display(), "starting abel-products-daemon");

	let pool = if benchmark_mode {
		None
	} else {
		Some(Pool::connect(&config.database).await.context("opening mysql pool")?)
	};

	let initial = match (&pool, benchmark_mode) {
		(_, true) => CatalogSnapshot::fixtures_for_benchmarks(),
		(Some(p), false) => CatalogSnapshot::load_from_pool(p)
			.await
			.context("building initial snapshot")?,
		(None, false) => anyhow::bail!("no pool available and benchmark_mode is false"),
	};

	info!(
		products = initial.product_count(),
		prices = initial.price_count(),
		rss_estimate_mb = initial.memory_estimate_mb(),
		"initial snapshot ready"
	);

	let catalog = Arc::new(ArcSwap::from(Arc::new(initial)));

	if once {
		info!("--once: initial snapshot built, exiting");
		return Ok(());
	}

	let server = Server::bind(&config.socket_path, Arc::clone(&catalog)).await?;
	let refresher = pool
		.as_ref()
		.map(|p| Refresher::new(p.clone(), Arc::clone(&catalog), config.refresh_interval));

	// Drive server + refresher together. Shutdown on SIGTERM/SIGINT.
	let shutdown = shutdown_signal();

	tokio::select! {
		result = server.run() => {
			warn!(?result, "server loop exited unexpectedly");
			result.context("server loop")?;
		}
		_ = async {
			if let Some(r) = refresher {
				r.run().await;
			} else {
				std::future::pending::<()>().await;
			}
		} => {
			warn!("refresher exited unexpectedly");
		}
		() = shutdown => {
			info!("shutdown signal received");
		}
	}

	info!("abel-products-daemon stopped");
	Ok(())
}

fn load_env(explicit: Option<&std::path::Path>) -> Result<()> {
	if let Some(path) = explicit {
		dotenvy::from_path(path).with_context(|| format!("loading env file {}", path.display()))?;
	} else {
		// Best-effort: .env next to the binary, then fallback to CWD.
		let _ = dotenvy::dotenv();
	}
	Ok(())
}

fn init_tracing(level: &str, log_file: Option<&std::path::Path>) -> Result<()> {
	let filter = EnvFilter::try_new(level).unwrap_or_else(|_| EnvFilter::new("info"));

	if let Some(path) = log_file {
		if let Some(parent) = path.parent() {
			if !parent.as_os_str().is_empty() {
				std::fs::create_dir_all(parent).with_context(|| format!("creating log dir {}", parent.display()))?;
			}
		}
		let file = std::fs::OpenOptions::new()
			.create(true)
			.append(true)
			.open(path)
			.with_context(|| format!("opening log file {}", path.display()))?;
		let writer = std::sync::Mutex::new(file);
		tracing_subscriber::fmt()
			.with_env_filter(filter)
			.with_target(true)
			.with_ansi(false)
			.with_writer(writer)
			.try_init()
			.map_err(|e| anyhow::anyhow!("installing tracing subscriber: {e}"))?;
	} else {
		tracing_subscriber::fmt()
			.with_env_filter(filter)
			.with_target(true)
			.compact()
			.try_init()
			.map_err(|e| anyhow::anyhow!("installing tracing subscriber: {e}"))?;
	}
	Ok(())
}

/// Wait for SIGTERM or SIGINT. If signal installation fails (should not happen on a healthy
/// kernel), logs the error and falls back to Ctrl-C only.
async fn shutdown_signal() {
	#[cfg(unix)]
	{
		use signal::unix::{signal, SignalKind};
		match (signal(SignalKind::terminate()), signal(SignalKind::interrupt())) {
			(Ok(mut term), Ok(mut int)) => {
				tokio::select! {
					_ = term.recv() => {}
					_ = int.recv() => {}
				}
			}
			(term, int) => {
				if let Err(err) = term {
					tracing::warn!(?err, "SIGTERM handler install failed — falling back to SIGINT only");
				}
				if let Err(err) = int {
					tracing::warn!(?err, "SIGINT handler install failed — falling back to ctrl_c()");
				}
				let _ = signal::ctrl_c().await;
			}
		}
	}
	#[cfg(not(unix))]
	{
		let _ = signal::ctrl_c().await;
	}
}
