//! Per-connection request handler.
//!
//! Reads length-prefixed JSON frames, dispatches on `RequestEnvelope`, writes back
//! `ResponseEnvelope`. One tokio task per accepted connection.

use std::{
	sync::Arc,
	time::{Instant, SystemTime, UNIX_EPOCH},
};

use arc_swap::ArcSwap;
use tokio::{io::AsyncWriteExt, net::UnixStream, sync::Notify};
use tracing::{debug, warn};

use crate::{
	error::{DaemonError, ProtocolError, RequestError},
	metrics::{current_rss_mb, DaemonMetrics},
	protocol::{
		framing::{read_frame, write_frame},
		ErrorKind, ErrorResponse, OkResponse, RequestEnvelope, ResponseBody, ResponseEnvelope, StatsResponse,
		TimingsBreakdown,
	},
	query::{self, Timings},
	snapshot::CatalogSnapshot,
	PROTOCOL_VERSION,
};

pub async fn serve_connection(
	stream: UnixStream,
	catalog: Arc<ArcSwap<CatalogSnapshot>>,
	metrics: Arc<DaemonMetrics>,
	wakeup: Arc<Notify>,
) -> Result<(), DaemonError> {
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

		let parse_started = Instant::now();
		let envelope: RequestEnvelope = match serde_json::from_slice(&frame) {
			Ok(v) => v,
			Err(e) => {
				write_error(&mut writer, ErrorKind::MalformedRequest, format!("json parse: {e}")).await?;
				continue;
			}
		};
		let parse_ms = elapsed_ms(parse_started);

		// Timings opt-in jen pro `getProducts`, kde PHP zapíná `debug: true`. Pro ostatní
		// volání zůstane `None` (žádný overhead z měření, žádné `timings` field v JSON).
		let mut timings: Option<Timings> = match &envelope {
			RequestEnvelope::GetProducts(req) if req.debug => Some(Timings {
				parse_ms,
				..Timings::default()
			}),
			_ => None,
		};

		let started = Instant::now();
		let response = match dispatch(&envelope, &catalog, &metrics, &wakeup, timings.as_mut()).await {
			Ok(body) => ResponseEnvelope::Ok(Box::new(OkResponse {
				protocol_version: PROTOCOL_VERSION,
				body,
				timings: None, // doplníme níž po serializaci
			})),
			Err(DaemonError::Request(req_err)) if req_err.is_fallback() => {
				// Graceful fallback — not an error, PHP handles the request itself.
				ResponseEnvelope::Ok(Box::new(OkResponse {
					protocol_version: PROTOCOL_VERSION,
					body: ResponseBody::FallbackRequired {
						reason: req_err.to_string(),
					},
					timings: None,
				}))
			}
			Err(err) => {
				warn!(?err, "request failed");
				err_to_envelope(err)
			}
		};

		// Rozlišujeme meta-calls (Ping, GetStats, RequestRebuild) od work-calls (queries).
		// Meta-calls se zapíšou jen do total_requests, ne do avg/max latence — polling z Tracy
		// baru, health-check ping nebo wakeup signál by jinak zkresloval business SLO.
		// Work-calls (včetně fallback_required odpovědí) se počítají všude: je to daemon work
		// time, nezávisle na tom, co s výsledkem udělá PHP. RequestRebuild je meta protože
		// nedotazuje snapshot — jen vyšle Notify, prakticky nulová latence.
		match envelope {
			RequestEnvelope::Ping | RequestEnvelope::GetStats | RequestEnvelope::RequestRebuild => {
				metrics.record_meta_request();
			},
			RequestEnvelope::GetProducts(_)
			| RequestEnvelope::GetCategoryCount(_)
			| RequestEnvelope::GetAllCategoryCounts(_)
			| RequestEnvelope::GetSellableProductPKs => metrics.record_work_request(started.elapsed()),
		}

		// Měření serializace zaplaceně i bez `debug` flagu (cheap), ale ukládáme jen pokud
		// timings akumulátor existuje. Když ano, vložíme breakdown do `OkResponse.timings`
		// a serializujeme znovu (overhead ~5–10 ms na 90 KB response je akceptovatelný —
		// `debug: true` se posílá jen z Tracy panelu, ne na produkční hot path).
		let serialize_started = Instant::now();
		let bytes = serde_json::to_vec(&response).map_err(ProtocolError::from)?;
		let serialize_ms = elapsed_ms(serialize_started);

		let final_bytes = if let (Some(mut t), ResponseEnvelope::Ok(ok)) = (timings, response) {
			t.serialize_ms = serialize_ms;
			let mut ok = *ok;
			ok.timings = Some(timings_to_breakdown(t));
			let env = ResponseEnvelope::Ok(Box::new(ok));
			serde_json::to_vec(&env).map_err(ProtocolError::from)?
		} else {
			bytes
		};

		write_frame(&mut writer, &final_bytes).await.map_err(DaemonError::from)?;
	}
}

#[inline]
fn elapsed_ms(start: Instant) -> f64 {
	let nanos = start.elapsed().as_nanos();
	#[allow(clippy::cast_precision_loss)]
	{
		nanos as f64 / 1_000_000.0
	}
}

#[inline]
fn timings_to_breakdown(t: Timings) -> TimingsBreakdown {
	t.into_breakdown()
}

async fn dispatch(
	envelope: &RequestEnvelope,
	catalog: &Arc<ArcSwap<CatalogSnapshot>>,
	metrics: &Arc<DaemonMetrics>,
	wakeup: &Arc<Notify>,
	timings: Option<&mut Timings>,
) -> Result<ResponseBody, DaemonError> {
	match envelope {
		RequestEnvelope::Ping => Ok(ResponseBody::Pong { ok: true }),
		RequestEnvelope::GetProducts(req) => {
			let snap = catalog.load_full();
			let resp = query::run(&snap, req, timings)?;
			Ok(ResponseBody::Products(Box::new(resp)))
		}
		RequestEnvelope::GetCategoryCount(req) => {
			let snap = catalog.load_full();
			let count = query::count(&snap, req)?;
			Ok(ResponseBody::CategoryCount { count })
		}
		RequestEnvelope::GetAllCategoryCounts(req) => {
			let snap = catalog.load_full();
			let counts = query::all_category_counts(&snap, req)?;
			Ok(ResponseBody::AllCategoryCounts { counts })
		}
		RequestEnvelope::GetSellableProductPKs => {
			let snap = catalog.load_full();
			let pks = query::sellable_product_pks(&snap);
			Ok(ResponseBody::SellableProductPKs { pks })
		}
		RequestEnvelope::GetStats => {
			let snap = catalog.load_full();
			Ok(ResponseBody::Stats(Box::new(build_stats_response(metrics, &snap))))
		}
		RequestEnvelope::RequestRebuild => {
			// Fire-and-forget: notify_one() is idempotent — opakované volání před receiverovým
			// wakeupem konsoliduje na jeden permit, takže storm volání nemůže způsobit storm
			// rebuildů. Floor v refresheru (`min_rebuild_interval`) je druhá vrstva ochrany.
			wakeup.notify_one();
			Ok(ResponseBody::RebuildAccepted { queued: true })
		}
	}
}

fn build_stats_response(metrics: &DaemonMetrics, snap: &CatalogSnapshot) -> StatsResponse {
	let started_at = metrics.started_at();
	let started_at_unix = started_at.duration_since(UNIX_EPOCH).map(|d| d.as_secs()).unwrap_or(0);
	let uptime_secs = SystemTime::now()
		.duration_since(started_at)
		.map(|d| d.as_secs())
		.unwrap_or(0);

	let total_requests = metrics.total_requests();
	let work_requests = metrics.work_requests();
	let work_micros = metrics.work_request_micros();
	let max_micros = metrics.max_work_request_micros();

	let avg_request_ms = if work_requests > 0 {
		Some((work_micros as f64 / work_requests as f64) / 1000.0)
	} else {
		None
	};

	let snapshot_timestamps_unix = metrics
		.snapshot_timestamps_copy()
		.into_iter()
		.map(|t| t.duration_since(UNIX_EPOCH).map(|d| d.as_secs()).unwrap_or(0))
		.collect();

	StatsResponse {
		started_at_unix,
		uptime_secs,
		rss_mb: current_rss_mb(),
		total_requests,
		work_requests,
		avg_request_ms,
		max_request_ms: max_micros as f64 / 1000.0,
		snapshot_timestamps_unix,
		product_count: snap.product_count() as u64,
		price_count: snap.price_count() as u64,
		snapshot_memory_estimate_mb: snap.memory_estimate_mb(),
		schema_version: snap.schema_version.to_string(),
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
