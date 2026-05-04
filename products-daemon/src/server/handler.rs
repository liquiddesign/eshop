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

		// Snapshot loaded jednou — cache lookup a query běží proti **stejnému** snapshot,
		// takže cache hit nemůže vrátit response z generation, kterou jsme nevyhodnotili.
		let snap = catalog.load_full();

		// Phase 4 response cache: eligible jen na "skutečné práce" requesty se stable response.
		// Debug requesty skip — chceme svěží timings měření, ne cached.
		// Ping/getStats/requestRebuild skip — meta-calls jsou cheap a stats by stárlo.
		let cache_eligible = matches!(
			&envelope,
			RequestEnvelope::GetProducts(r) if !r.debug
		) || matches!(
			&envelope,
			RequestEnvelope::GetCategoryCount(_)
				| RequestEnvelope::GetAllCategoryCounts(_)
				| RequestEnvelope::GetSellableProductPKs
		);
		let cache_key: Option<[u8; 32]> = if cache_eligible {
			Some(*blake3::hash(&frame).as_bytes())
		} else {
			None
		};

		if let Some(key) = cache_key.as_ref() {
			let hit = snap.response_cache.lock().get(key).cloned();
			if let Some(bytes) = hit {
				// Cache hit — write přímo cached bytes, bez dispatch / re-serialize.
				match envelope {
					RequestEnvelope::Ping | RequestEnvelope::GetStats | RequestEnvelope::RequestRebuild => {
						unreachable!("meta-calls are not cache_eligible");
					}
					RequestEnvelope::GetProducts(_)
					| RequestEnvelope::GetCategoryCount(_)
					| RequestEnvelope::GetAllCategoryCounts(_)
					| RequestEnvelope::GetSellableProductPKs => {
						metrics.record_work_request(parse_started.elapsed());
					}
				}
				write_frame(&mut writer, &bytes).await.map_err(DaemonError::from)?;
				continue;
			}
		}

		let started = Instant::now();
		let response = match dispatch(&envelope, &snap, &metrics, &wakeup, timings.as_mut()).await {
			Ok((body, unknown_pricelist_pks)) => ResponseEnvelope::Ok(Box::new(OkResponse {
				protocol_version: PROTOCOL_VERSION,
				body,
				timings: None, // doplníme níž po serializaci
				unknown_pricelist_pks,
			})),
			Err(DaemonError::Request(req_err)) if req_err.is_fallback() => {
				// Graceful fallback — not an error, PHP handles the request itself.
				ResponseEnvelope::Ok(Box::new(OkResponse {
					protocol_version: PROTOCOL_VERSION,
					body: ResponseBody::FallbackRequired {
						reason: req_err.to_string(),
					},
					timings: None,
					unknown_pricelist_pks: Vec::new(),
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
		//
		// `response_ok` capture musí proběhnout **před** consume v `let final_bytes` arm-u,
		// protože `ResponseEnvelope` neimplementuje `Copy`.
		let response_ok = matches!(&response, ResponseEnvelope::Ok(_));
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

		// Cache insert pro eligible miss-y. Klonujeme bytes do `Arc` aby další lookup vracel
		// rovnou shared reference bez další alokace.
		//
		// **Skip Error envelopes:** transient errors (SnapshotNotReady při warmup, unknown VL/PL)
		// se nemají zastrašit — PHP fallbackne na `LiveProductsProvider` a další request se má
		// dotknout snapshotu znovu. `FallbackRequired` (explicitní deterministická hláška) je
		// safe-to-cache; jeho příčina je shape requestu, ne stav daemonu.
		if let (Some(key), true) = (cache_key, response_ok) {
			snap.response_cache
				.lock()
				.put(key, Arc::new(final_bytes.clone()));
		}

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
	snap: &Arc<CatalogSnapshot>,
	metrics: &Arc<DaemonMetrics>,
	wakeup: &Arc<Notify>,
	timings: Option<&mut Timings>,
) -> Result<(ResponseBody, Vec<String>), DaemonError> {
	match envelope {
		RequestEnvelope::Ping => Ok((ResponseBody::Pong { ok: true }, Vec::new())),
		RequestEnvelope::GetProducts(req) => {
			let (resp, unknown) = query::run(snap, req, timings)?;
			Ok((ResponseBody::Products(Box::new(resp)), unknown))
		}
		RequestEnvelope::GetCategoryCount(req) => {
			let (count, unknown) = query::count(snap, req)?;
			Ok((ResponseBody::CategoryCount { count }, unknown))
		}
		RequestEnvelope::GetAllCategoryCounts(req) => {
			let (counts, unknown) = query::all_category_counts(snap, req)?;
			Ok((ResponseBody::AllCategoryCounts { counts }, unknown))
		}
		RequestEnvelope::GetSellableProductPKs => {
			let pks = query::sellable_product_pks(snap);
			Ok((ResponseBody::SellableProductPKs { pks }, Vec::new()))
		}
		RequestEnvelope::GetStats => Ok((
			ResponseBody::Stats(Box::new(build_stats_response(metrics, snap))),
			Vec::new(),
		)),
		RequestEnvelope::RequestRebuild => {
			// Fire-and-forget: notify_one() is idempotent — opakované volání před receiverovým
			// wakeupem konsoliduje na jeden permit, takže storm volání nemůže způsobit storm
			// rebuildů. External trigger v refresheru bypassuje `min_rebuild_interval` floor
			// — paralelní requesty během běžícího rebuildu se kolabsují na jeden následný rebuild.
			wakeup.notify_one();
			Ok((ResponseBody::RebuildAccepted { queued: true }, Vec::new()))
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

	let history = metrics.snapshot_history_copy();
	let snapshot_timestamps_unix = history
		.iter()
		.map(|(t, _)| t.duration_since(UNIX_EPOCH).map(|d| d.as_secs()).unwrap_or(0))
		.collect();
	let snapshot_durations_ms = history
		.iter()
		.map(|(_, d)| u64::try_from(d.as_millis()).unwrap_or(u64::MAX))
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
		snapshot_durations_ms,
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
