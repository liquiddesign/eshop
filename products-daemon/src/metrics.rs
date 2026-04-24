//! Runtime metriky daemonu — uptime, total requests, avg/max request latence, RSS,
//! historie snapshot buildů.
//!
//! Sbírají se lock-free přes `AtomicU64` kromě snapshot timestampů, kde je kontence
//! nulová (zápis 1× per refresh interval). Exportuje se přes `RequestEnvelope::GetStats`
//! a renderuje v PHP Tracy panelu.

use std::{
	collections::VecDeque,
	sync::{
		atomic::{AtomicU64, Ordering},
		Mutex,
	},
	time::{Duration, SystemTime},
};

/// Maximum kolik posledních snapshot timestampů držíme v RAM. Při REFRESH_INTERVAL_SECS=600
/// to pokryje ~8 hodin historie (48 timestampů), při 60s interval ~1.5 h. Bounded aby
/// dlouho běžící daemon neměl lineárně rostoucí paměť.
const MAX_SNAPSHOT_HISTORY: usize = 200;

pub struct DaemonMetrics {
	started_at: SystemTime,
	total_requests: AtomicU64,
	work_requests: AtomicU64,
	work_request_micros: AtomicU64,
	max_work_request_micros: AtomicU64,
	snapshot_timestamps: Mutex<VecDeque<SystemTime>>,
}

impl DaemonMetrics {
	#[must_use]
	pub fn new() -> Self {
		Self {
			started_at: SystemTime::now(),
			total_requests: AtomicU64::new(0),
			work_requests: AtomicU64::new(0),
			work_request_micros: AtomicU64::new(0),
			max_work_request_micros: AtomicU64::new(0),
			snapshot_timestamps: Mutex::new(VecDeque::with_capacity(MAX_SNAPSHOT_HISTORY)),
		}
	}

	/// Zaznamená jedno volání, které dělalo skutečnou práci (query proti snapshot, not
	/// polling). Započítá se do `total_requests`, `avg` i `max` latence.
	pub fn record_work_request(&self, duration: Duration) {
		let micros = u64::try_from(duration.as_micros()).unwrap_or(u64::MAX);
		self.total_requests.fetch_add(1, Ordering::Relaxed);
		self.work_requests.fetch_add(1, Ordering::Relaxed);
		self.work_request_micros.fetch_add(micros, Ordering::Relaxed);
		self.max_work_request_micros.fetch_max(micros, Ordering::Relaxed);
	}

	/// Zaznamená lightweight meta-call (ping, getStats) — započítá se jen do `total_requests`,
	/// ne do avg/max latence. Důvod: Tracy panel polling nebo health-check ping jinak
	/// zkresloval business latency metriky; ale bez započítání do total by panel vypadal
	/// "mrtvý", když traffic jede skrz PHP cache a nedorazí k daemonu.
	pub fn record_meta_request(&self) {
		self.total_requests.fetch_add(1, Ordering::Relaxed);
	}

	/// Zaznamená, že byl postaven nový catalog snapshot (initial i drift rebuild).
	/// Drží bounded historii posledních `MAX_SNAPSHOT_HISTORY` timestampů — při přetečení
	/// drop front.
	pub fn record_snapshot(&self) {
		let Ok(mut v) = self.snapshot_timestamps.lock() else {
			return;
		};
		if v.len() == MAX_SNAPSHOT_HISTORY {
			v.pop_front();
		}
		v.push_back(SystemTime::now());
	}

	#[must_use]
	pub fn started_at(&self) -> SystemTime {
		self.started_at
	}

	#[must_use]
	pub fn total_requests(&self) -> u64 {
		self.total_requests.load(Ordering::Relaxed)
	}

	#[must_use]
	pub fn work_requests(&self) -> u64 {
		self.work_requests.load(Ordering::Relaxed)
	}

	#[must_use]
	pub fn work_request_micros(&self) -> u64 {
		self.work_request_micros.load(Ordering::Relaxed)
	}

	#[must_use]
	pub fn max_work_request_micros(&self) -> u64 {
		self.max_work_request_micros.load(Ordering::Relaxed)
	}

	#[must_use]
	pub fn snapshot_timestamps_copy(&self) -> Vec<SystemTime> {
		self.snapshot_timestamps
			.lock()
			.map(|v| v.iter().copied().collect())
			.unwrap_or_default()
	}
}

impl Default for DaemonMetrics {
	fn default() -> Self {
		Self::new()
	}
}

/// Aktuální resident-set-size procesu v MB, čtené z `/proc/self/statm`. Linux-only; na jiných
/// kernelech vrací `None`. Druhý field v statm je RSS v kernel page unitech. Daemon je
/// x86_64 Linux-only build (target-cpu=x86-64-v3), takže zde hardcodujeme 4 KiB page size —
/// žádná aktuálně supported x86_64 distribuce nepoužívá jiný page size.
const PAGE_SIZE_BYTES: u64 = 4096;

#[must_use]
pub fn current_rss_mb() -> Option<u64> {
	let statm = std::fs::read_to_string("/proc/self/statm").ok()?;
	let resident_pages: u64 = statm.split_whitespace().nth(1)?.parse().ok()?;
	Some(resident_pages.saturating_mul(PAGE_SIZE_BYTES) / (1024 * 1024))
}

#[cfg(test)]
mod tests {
	use super::*;

	#[test]
	fn record_work_request_accumulates() {
		let m = DaemonMetrics::new();
		m.record_work_request(Duration::from_micros(100));
		m.record_work_request(Duration::from_micros(300));
		assert_eq!(m.total_requests(), 2);
		assert_eq!(m.work_requests(), 2);
		assert_eq!(m.work_request_micros(), 400);
		assert_eq!(m.max_work_request_micros(), 300);
	}

	#[test]
	fn record_meta_request_only_bumps_total() {
		let m = DaemonMetrics::new();
		m.record_work_request(Duration::from_micros(500));
		m.record_meta_request();
		m.record_meta_request();
		assert_eq!(m.total_requests(), 3);
		assert_eq!(m.work_requests(), 1);
		assert_eq!(m.work_request_micros(), 500);
		assert_eq!(m.max_work_request_micros(), 500);
	}

	#[test]
	fn snapshot_history_is_bounded() {
		let m = DaemonMetrics::new();
		for _ in 0..(MAX_SNAPSHOT_HISTORY + 50) {
			m.record_snapshot();
		}
		assert_eq!(m.snapshot_timestamps_copy().len(), MAX_SNAPSHOT_HISTORY);
	}

	#[test]
	fn rss_is_reasonable_on_linux() {
		if cfg!(target_os = "linux") {
			let rss = current_rss_mb().expect("proc/self/statm should exist on linux");
			assert!(rss < 100_000, "RSS suspiciously huge: {rss} MB");
		}
	}
}
