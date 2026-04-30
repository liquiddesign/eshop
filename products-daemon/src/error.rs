//! Error types used across the daemon.
//!
//! Follows ch. 4 of the Rust best-practices handbook:
//! - `thiserror` for library errors (this module) — each variant is self-documenting.
//! - `anyhow` only in `main.rs` for startup plumbing.
//! - No `unwrap`/`expect` outside tests.

use std::io;

use thiserror::Error;

/// Top-level daemon error — returned from `main()` plumbing and from protocol handlers.
#[derive(Debug, Error)]
pub enum DaemonError {
	/// Failed to initialize the runtime (tracing, config, signal handler).
	#[error("startup failed: {0}")]
	Startup(String),

	/// Socket listener or per-connection I/O error.
	#[error("socket i/o: {0}")]
	Io(#[from] io::Error),

	/// Snapshot could not be built from the current DB state.
	#[error(transparent)]
	SnapshotBuild(#[from] SnapshotBuildError),

	/// Protocol framing / serialization error on the wire.
	#[error(transparent)]
	Protocol(#[from] ProtocolError),

	/// Business-level request failed (unknown filter, invalid pricelist, etc).
	#[error(transparent)]
	Request(#[from] RequestError),
}

/// Errors that can occur while building a `CatalogSnapshot` from MariaDB.
#[derive(Debug, Error)]
pub enum SnapshotBuildError {
	#[error("database connection failed: {0}")]
	Database(#[from] mysql_async::Error),

	#[error("row decoding: {field} — {reason}")]
	Decode { field: &'static str, reason: String },

	#[error("intern pool overflow: {pool} exceeded {limit} entries")]
	InternOverflow { pool: &'static str, limit: usize },

	#[error("missing reference: {parent}({child}) — {value} not present in {lookup_pool}")]
	MissingReference {
		parent: &'static str,
		child: &'static str,
		value: String,
		lookup_pool: &'static str,
	},
}

/// Wire-protocol errors — framing, JSON parse, version mismatch.
#[derive(Debug, Error)]
pub enum ProtocolError {
	#[error("wire framing: {0}")]
	Framing(String),

	#[error("malformed JSON: {0}")]
	Json(#[from] serde_json::Error),

	#[error("unsupported protocol version: client={client}, server={server}")]
	VersionMismatch { client: u16, server: u16 },

	#[error("frame too large: {size} bytes exceeds limit {limit}")]
	FrameTooLarge { size: usize, limit: usize },
}

/// Errors returned from request handlers — surfaced to PHP as `fallback_required` hints.
#[derive(Debug, Error)]
pub enum RequestError {
	/// The request uses an extensibility hook (custom collection-order expression,
	/// dynamic filter expression, or `uuidField` ordering) that is not implemented
	/// in the Rust daemon. PHP should handle via `LiveProductsProvider`.
	#[error("request requires PHP fallback: {reason}")]
	FallbackRequired { reason: &'static str },

	#[error("unknown visibility list pk: {0}")]
	UnknownVisibilityList(String),

	#[error("unknown pricelist pk: {0}")]
	UnknownPricelist(String),

	#[error("unknown category pk: {0}")]
	UnknownCategory(String),

	#[error("unknown attribute: {0}")]
	UnknownAttribute(String),

	#[error("catalog snapshot not ready — daemon is still warming up")]
	SnapshotNotReady,
}

impl RequestError {
	/// Return `true` when the failure is recoverable by letting PHP handle the request.
	/// The daemon emits `fallback_required: true` in the response envelope so the PHP
	/// proxy can delegate to `LiveProductsProvider`.
	#[must_use]
	pub const fn is_fallback(&self) -> bool {
		matches!(self, Self::FallbackRequired { .. })
	}
}

/// Convenience alias used by public APIs in this crate.
pub type Result<T, E = DaemonError> = std::result::Result<T, E>;
