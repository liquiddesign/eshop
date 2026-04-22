//! Per-connection request handler.
//!
//! Reads length-prefixed JSON frames, dispatches on `RequestEnvelope`, writes back
//! `ResponseEnvelope`. One tokio task per accepted connection.

use std::sync::Arc;

use arc_swap::ArcSwap;
use tokio::{io::AsyncWriteExt, net::UnixStream};
use tracing::{debug, warn};

use crate::{
	error::{DaemonError, ProtocolError, RequestError},
	protocol::{
		framing::{read_frame, write_frame},
		ErrorKind, ErrorResponse, OkResponse, RequestEnvelope, ResponseBody, ResponseEnvelope,
	},
	query,
	snapshot::CatalogSnapshot,
	PROTOCOL_VERSION,
};

pub async fn serve_connection(stream: UnixStream, catalog: Arc<ArcSwap<CatalogSnapshot>>) -> Result<(), DaemonError> {
	let (mut reader, mut writer) = stream.into_split();

	loop {
		let frame = match read_frame(&mut reader).await {
			Ok(b) => b,
			Err(ProtocolError::Framing(msg)) if msg.contains("unexpected EOF") || msg.contains("read header") => {
				// Client hung up — normal connection close.
				debug!("client closed connection");
				return Ok(());
			}
			Err(e) => return Err(e.into()),
		};

		let envelope: RequestEnvelope = match serde_json::from_slice(&frame) {
			Ok(v) => v,
			Err(e) => {
				write_error(&mut writer, ErrorKind::MalformedRequest, format!("json parse: {e}")).await?;
				continue;
			}
		};

		let response = match dispatch(&envelope, &catalog).await {
			Ok(body) => ResponseEnvelope::Ok(Box::new(OkResponse {
				protocol_version: PROTOCOL_VERSION,
				body,
			})),
			Err(DaemonError::Request(req_err)) if req_err.is_fallback() => {
				// Graceful fallback — not an error, PHP handles the request itself.
				ResponseEnvelope::Ok(Box::new(OkResponse {
					protocol_version: PROTOCOL_VERSION,
					body: ResponseBody::FallbackRequired {
						reason: req_err.to_string(),
					},
				}))
			}
			Err(err) => {
				warn!(?err, "request failed");
				err_to_envelope(err)
			}
		};

		let bytes = serde_json::to_vec(&response).map_err(ProtocolError::from)?;
		write_frame(&mut writer, &bytes).await.map_err(DaemonError::from)?;
	}
}

async fn dispatch(
	envelope: &RequestEnvelope,
	catalog: &Arc<ArcSwap<CatalogSnapshot>>,
) -> Result<ResponseBody, DaemonError> {
	match envelope {
		RequestEnvelope::Ping => Ok(ResponseBody::Pong { ok: true }),
		RequestEnvelope::GetProducts(req) => {
			let snap = catalog.load_full();
			let resp = query::run(&snap, req)?;
			Ok(ResponseBody::Products(Box::new(resp)))
		}
		RequestEnvelope::GetCategoryCount(req) => {
			let snap = catalog.load_full();
			let count = query::count(&snap, req)?;
			Ok(ResponseBody::CategoryCount { count })
		}
	}
}

fn err_to_envelope(err: DaemonError) -> ResponseEnvelope {
	let (kind, message) = match &err {
		DaemonError::Request(RequestError::SnapshotNotReady) => (ErrorKind::SnapshotNotReady, err.to_string()),
		DaemonError::Request(RequestError::UnknownVisibilityList(_)) => {
			(ErrorKind::UnknownVisibilityList, err.to_string())
		}
		DaemonError::Request(RequestError::UnknownPricelist(_)) => (ErrorKind::UnknownPricelist, err.to_string()),
		DaemonError::Request(RequestError::UnknownCategory(_)) => (ErrorKind::UnknownCategory, err.to_string()),
		DaemonError::Request(RequestError::UnknownAttribute(_)) => (ErrorKind::UnknownAttribute, err.to_string()),
		DaemonError::Protocol(ProtocolError::VersionMismatch { .. }) => (ErrorKind::ProtocolVersion, err.to_string()),
		_ => (ErrorKind::Internal, err.to_string()),
	};
	ResponseEnvelope::Error(ErrorResponse {
		protocol_version: PROTOCOL_VERSION,
		error: kind,
		message,
	})
}

async fn write_error<W: AsyncWriteExt + Unpin>(
	writer: &mut W,
	kind: ErrorKind,
	msg: String,
) -> Result<(), DaemonError> {
	let env = ResponseEnvelope::Error(ErrorResponse {
		protocol_version: PROTOCOL_VERSION,
		error: kind,
		message: msg,
	});
	let bytes = serde_json::to_vec(&env).map_err(ProtocolError::from)?;
	write_frame(writer, &bytes).await.map_err(DaemonError::from)
}
