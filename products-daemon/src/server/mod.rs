//! Unix domain socket listener.
//!
//! Each accepted connection spawns a `handler::serve_connection` task. Connections are
//! long-lived — PHP may pipeline multiple requests over a single socket. The listener
//! loops until shutdown; handler errors are logged but don't kill the server.

pub mod handler;

use std::{path::Path, sync::Arc};

use arc_swap::ArcSwap;
use tokio::net::UnixListener;
use tracing::{error, info};

use crate::{error::DaemonError, metrics::DaemonMetrics, snapshot::CatalogSnapshot};

pub struct Server {
	listener: UnixListener,
	catalog: Arc<ArcSwap<CatalogSnapshot>>,
	metrics: Arc<DaemonMetrics>,
}

impl Server {
	/// Bind to `socket_path`, removing a stale socket file if one exists.
	///
	/// Post-bind the socket is chmod'd to 0666 so any local user can connect — required because
	/// PHP-FPM workers typically run under a different user/group than the daemon. Socket content
	/// is catalog metadata (public pricing), not secret, so world-accessible is acceptable.
	pub async fn bind(
		socket_path: &Path,
		catalog: Arc<ArcSwap<CatalogSnapshot>>,
		metrics: Arc<DaemonMetrics>,
	) -> Result<Self, DaemonError> {
		// Clear a stale socket left behind by a crashed previous run.
		if socket_path.exists() {
			std::fs::remove_file(socket_path).map_err(DaemonError::Io)?;
		}
		let listener = UnixListener::bind(socket_path).map_err(DaemonError::Io)?;

		use std::os::unix::fs::PermissionsExt as _;
		let perms = std::fs::Permissions::from_mode(0o666);
		std::fs::set_permissions(socket_path, perms).map_err(DaemonError::Io)?;

		info!(path = %socket_path.display(), "socket listening");
		Ok(Self {
			listener,
			catalog,
			metrics,
		})
	}

	/// Accept loop. Runs until `self` is dropped (which happens when `tokio::select!` in
	/// `main::async_main` picks the shutdown signal branch).
	pub async fn run(self) -> Result<(), DaemonError> {
		loop {
			let (stream, _addr) = match self.listener.accept().await {
				Ok(pair) => pair,
				Err(err) => {
					error!(?err, "accept failed — retrying");
					continue;
				}
			};
			let catalog = Arc::clone(&self.catalog);
			let metrics = Arc::clone(&self.metrics);
			tokio::spawn(async move {
				if let Err(err) = handler::serve_connection(stream, catalog, metrics).await {
					error!(?err, "per-connection handler ended with error");
				}
			});
		}
	}
}
