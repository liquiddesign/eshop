//! # abel-products-daemon
//!
//! Long-running Rust daemon that holds Abel's catalog facts in RAM (products, prices,
//! visibility, attribute/category/producer inverted bitmaps) and answers
//! `GetProductsRequest` over a Unix domain socket.
//!
//! ## Design
//! - `Arc<ArcSwap<CatalogSnapshot>>` for lock-free reads on the hot path (ch. 9).
//! - `RoaringBitmap` inverted indexes on attribute/category/producer for O(candidates) AND ops.
//! - UUID interning (`ProductIdx=u32`, `PricelistIdx=u16`) so hot rows are 24 B `Copy`.
//! - Polling refresher every N minutes; drift detection via cheap `COUNT/MAX` queries.
//!
//! ## Fallback contract
//! When a request references an unsupported extensibility hook (e.g. `uuidField` ordering,
//! custom collection-order callbacks registered via `addCollectionOrderExpression`), the
//! handler returns `fallback_required: true` and PHP delegates to `LiveProductsProvider`.

#![forbid(unsafe_code)]
// Lint levels live in Cargo.toml's `[lints.clippy]` section so they compose with the
// workspace allow-list. Crate-level `#![warn(clippy::pedantic)]` would override those and
// defeat the manifest. See Cargo.toml header comment for rationale.
#![warn(clippy::all, clippy::perf)]

pub mod config;
pub mod db;
pub mod error;
pub mod protocol;
pub mod query;
pub mod refresher;
pub mod server;
pub mod snapshot;

pub use crate::{
	config::Config,
	error::{DaemonError, ProtocolError, RequestError, Result, SnapshotBuildError},
	snapshot::CatalogSnapshot,
};

/// Wire-protocol version advertised by this build. Bumped on breaking changes to
/// `GetProductsRequest`/`GetProductsResponse` shape. PHP clients send their supported
/// version in the handshake; the daemon rejects anything lower.
pub const PROTOCOL_VERSION: u16 = 1;
