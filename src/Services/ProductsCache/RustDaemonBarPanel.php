<?php

declare(strict_types=1);

namespace Eshop\Services\ProductsCache;

use Carbon\Carbon;
use Tracy\IBarPanel;

/**
 * Tracy bar panel vizualizující:
 *  1. Daemon-level runtime (uptime, RSS, kumulativní total/avg/max requesty, historie snapshotů),
 *     čtené on-demand z {@see RustDaemonClient::getStats()}.
 *  2. Per-request volání {@see RustProductsProvider}: kolikrát se šlo na daemon vs. fallback,
 *     s jakými důvody, a jak dlouho to trvalo. Zdrojem je statický
 *     `RustProductsProvider::$callLog`.
 *
 * Registrace: {@see \Eshop\Bridges\ShopperDI::afterCompile()} vloží panel do Tracy baru jen když
 * je provider nastaven na `rust`. Klient je optional dependency — `null` = daemon-runtime sekce
 * se skryje a zbude jen per-request log (užitečné ve fallback módu, kde klient ani nemusí být).
 *
 * Performance: `getStats()` je jeden round-trip na daemon (~0.5 ms warm) a provede se
 * **jen při renderu panelu**, ne při každém Tracy bar toggle — IBarPanel::getPanel() se volá
 * lazy. Tab zůstává lightweight (čte jen statický callLog).
 * @internal Not a part of the public API — use {@see GeneralProductsCacheProvider} instead.
 *           Direct injection of this class bypasses the provider abstraction and breaks
 *           the 'cache' / 'live' / 'rust' provider switch.
 */
final class RustDaemonBarPanel implements IBarPanel
{
	public function __construct(private readonly RustDaemonClient|null $client = null,)
	{
	}

	public function getTab(): string
	{
		[$totalCalls, $totalFallbacks, $totalMs] = $this->computeTotals();

		$icon = $totalFallbacks === 0
			? '<span style="color:#3c763d">&#x25cf;</span>'
			: '<span style="color:#a94442">&#x25cf;</span>';

		$label = $totalCalls === 0
			? 'Rust daemon: idle'
			: \sprintf(
				'Rust daemon: %d calls / %s ms%s',
				$totalCalls,
				\number_format($totalMs, 1, '.', ''),
				$totalFallbacks > 0 ? \sprintf(' / %d fallback', $totalFallbacks) : '',
			);

		return $icon . ' ' . \htmlspecialchars($label, \ENT_QUOTES, 'UTF-8');
	}

	public function getPanel(): string
	{
		$statsHtml = $this->renderDaemonStats();
		$callLogHtml = $this->renderCallLog();

		return '<h1>Rust daemon</h1>'
			. '<div class="tracy-inner">'
			. $statsHtml
			. $callLogHtml
			. '</div>';
	}

	private function renderDaemonStats(): string
	{
		if ($this->client === null) {
			return '';
		}

		$stats = $this->client->getStats();

		if ($stats === null) {
			return '<h2>Daemon runtime</h2>'
				. '<p><em>Daemon stats nedostupné — daemon nemusí běžet, nebo je použitá starší binárka bez `getStats`.</em></p>';
		}

		$rss = $stats['rssMb'] !== null ? $stats['rssMb'] . ' MB' : '—';
		$avg = $stats['avgRequestMs'] !== null ? \number_format($stats['avgRequestMs'], 3, '.', '') . ' ms' : '—';
		$max = \number_format($stats['maxRequestMs'], 3, '.', '') . ' ms';
		$uptime = self::formatUptime($stats['uptimeSecs']);
		$startedAt = $stats['startedAtUnix'] > 0
			? Carbon::createFromTimestamp($stats['startedAtUnix'])->format('Y-m-d H:i:s')
			: '—';

		$snapshotRows = $this->renderSnapshotList($stats['snapshotTimestampsUnix']);

		return '<h2>Daemon runtime</h2>'
			. '<table>'
			. '<tr><th style="text-align:left">Uptime</th><td>' . \htmlspecialchars($uptime, \ENT_QUOTES, 'UTF-8') . '</td>'
			. '<th style="text-align:left;padding-left:1em">Started</th><td>' . \htmlspecialchars($startedAt, \ENT_QUOTES, 'UTF-8') . '</td></tr>'
			. '<tr><th style="text-align:left">RSS</th><td>' . \htmlspecialchars($rss, \ENT_QUOTES, 'UTF-8') . '</td>'
			. '<th style="text-align:left;padding-left:1em">Snapshot RAM est.</th><td>' . $stats['snapshotMemoryEstimateMb'] . ' MB</td></tr>'
			. '<tr><th style="text-align:left" title="Všechny metody včetně ping/getStats polling">Requests total</th><td>' . $stats['totalRequests'] . '</td>'
			. '<th style="text-align:left;padding-left:1em"'
			. ' title="Jen skutečná práce: query, count, sellablePKs. Do avg/max latence se počítá jen tohle.">'
			. 'Work requests</th><td>' . $stats['workRequests'] . '</td></tr>'
			. '<tr><th style="text-align:left">Avg request</th><td>' . \htmlspecialchars($avg, \ENT_QUOTES, 'UTF-8') . '</td>'
			. '<th style="text-align:left;padding-left:1em">Max request</th><td>' . \htmlspecialchars($max, \ENT_QUOTES, 'UTF-8') . '</td></tr>'
			. '<tr><th style="text-align:left">Schema version</th><td colspan="3"><code>' . \htmlspecialchars($stats['schemaVersion'], \ENT_QUOTES, 'UTF-8') . '</code></td></tr>'
			. '<tr><th style="text-align:left">Products</th><td>' . $stats['productCount'] . '</td>'
			. '<th style="text-align:left;padding-left:1em">Prices</th><td>' . $stats['priceCount'] . '</td></tr>'
			. '</table>'
			. $snapshotRows;
	}

	/**
	 * @param list<int> $timestamps Unix epoch seconds, nejstarší první.
	 */
	private function renderSnapshotList(array $timestamps): string
	{
		if ($timestamps === []) {
			return '<p><em>Žádná historie snapshotů.</em></p>';
		}

		// Nejnovější nahoře pro rychlou orientaci.
		$reversed = \array_reverse($timestamps);
		$visible = \array_slice($reversed, 0, 20);
		$now = \time();
		$items = '';

		foreach ($visible as $ts) {
			$when = Carbon::createFromTimestamp($ts)->format('Y-m-d H:i:s');
			$ago = self::formatAgo($now - $ts);
			$items .= '<li><code>' . \htmlspecialchars($when, \ENT_QUOTES, 'UTF-8') . '</code> '
				. '<span style="color:#888">(' . \htmlspecialchars($ago, \ENT_QUOTES, 'UTF-8') . ')</span></li>';
		}

		return '<h3>Snapshot history (' . \count($timestamps) . ')</h3>'
			. '<ul style="max-height:240px;overflow:auto;margin:0;padding-left:1.5em">' . $items . '</ul>';
	}

	private function renderCallLog(): string
	{
		$log = RustProductsProvider::$callLog;

		if ($log === []) {
			return '<h2>Per-request calls</h2><p>No calls in this request.</p>';
		}

		\uasort($log, static fn (array $a, array $b): int => $b['totalMs'] <=> $a['totalMs']);

		[$totalCalls, $totalFallbacks, $totalMs] = $this->computeTotals();

		$rows = '';

		foreach ($log as $entry) {
			$avg = $entry['count'] > 0 ? $entry['totalMs'] / $entry['count'] : 0.0;
			$statusColor = $entry['status'] === 'daemon' ? '#3c763d' : '#a94442';
			$reasonHtml = $entry['reason'] === '' ? '<em>—</em>' : \htmlspecialchars($entry['reason'], \ENT_QUOTES, 'UTF-8');

			$rows .= \sprintf(
				'<tr>'
				. '<td>%s</td>'
				. '<td style="color:%s;font-weight:bold">%s</td>'
				. '<td style="max-width:420px;word-break:break-word">%s</td>'
				. '<td style="text-align:right">%d</td>'
				. '<td style="text-align:right">%s</td>'
				. '<td style="text-align:right">%s</td>'
				. '<td style="text-align:right">%s</td>'
				. '</tr>',
				\htmlspecialchars($entry['method'], \ENT_QUOTES, 'UTF-8'),
				$statusColor,
				\htmlspecialchars($entry['status'], \ENT_QUOTES, 'UTF-8'),
				$reasonHtml,
				$entry['count'],
				\number_format($entry['totalMs'], 2, '.', ''),
				\number_format($avg, 2, '.', ''),
				\number_format($entry['maxMs'], 2, '.', ''),
			);
		}

		$summary = \sprintf(
			'<p><strong>%d</strong> total calls, <strong style="color:%s">%d</strong> fallback, <strong>%s</strong> ms total</p>',
			$totalCalls,
			$totalFallbacks === 0 ? '#3c763d' : '#a94442',
			$totalFallbacks,
			\number_format($totalMs, 2, '.', ''),
		);

		return '<h2>Per-request calls</h2>'
			. $summary
			. '<table>'
			. '<thead><tr><th>method</th><th>status</th><th>reason</th><th>count</th><th>total ms</th><th>avg ms</th><th>max ms</th></tr></thead>'
			. '<tbody>' . $rows . '</tbody>'
			. '</table>';
	}

	/**
	 * @return array{0: int, 1: int, 2: float} [totalCalls, totalFallbacks, totalMs]
	 */
	private function computeTotals(): array
	{
		$totalCalls = 0;
		$totalFallbacks = 0;
		$totalMs = 0.0;

		foreach (RustProductsProvider::$callLog as $entry) {
			$totalCalls += $entry['count'];
			$totalMs += $entry['totalMs'];

			if ($entry['status'] !== 'fallback') {
				continue;
			}

			$totalFallbacks += $entry['count'];
		}

		return [$totalCalls, $totalFallbacks, $totalMs];
	}

	private static function formatUptime(int $secs): string
	{
		if ($secs < 60) {
			return $secs . ' s';
		}

		$days = \intdiv($secs, 86400);
		$hours = \intdiv($secs % 86400, 3600);
		$minutes = \intdiv($secs % 3600, 60);
		$rest = $secs % 60;

		if ($days > 0) {
			return \sprintf('%dd %02dh %02dm', $days, $hours, $minutes);
		}

		if ($hours > 0) {
			return \sprintf('%dh %02dm %02ds', $hours, $minutes, $rest);
		}

		return \sprintf('%dm %02ds', $minutes, $rest);
	}

	private static function formatAgo(int $secs): string
	{
		if ($secs < 0) {
			// Daemon clock drift proti PHP clockům — vzácné, ale ošetřit.
			return 'just now';
		}

		return self::formatUptime($secs) . ' ago';
	}
}
