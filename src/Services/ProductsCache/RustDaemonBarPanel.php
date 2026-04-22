<?php

declare(strict_types=1);

namespace Eshop\Services\ProductsCache;

use Tracy\IBarPanel;

/**
 * Tracy bar panel vizualizující per-request volání {@see RustProxyProductsProvider}:
 * kolikrát se šlo na daemon vs. fallback, s jakými důvody, a jak dlouho to trvalo.
 *
 * Data čte ze statického `RustProxyProductsProvider::$callLog`, který se plní přes
 * {@see RustProxyProductsProvider::recordCall()} v happy-path i fallback větvích.
 *
 * Registrace: {@see \Eshop\Bridges\ShopperDI::afterCompile()} vloží panel do
 * Tracy baru jen když je provider nastaven na `rust`.
 */
final class RustDaemonBarPanel implements IBarPanel
{
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
		$log = RustProxyProductsProvider::$callLog;

		if ($log === []) {
			return '<h1>Rust daemon</h1><div class="tracy-inner"><p>No calls in this request.</p></div>';
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

		return '<h1>Rust daemon calls</h1>'
			. '<div class="tracy-inner">'
			. $summary
			. '<table>'
			. '<thead><tr><th>method</th><th>status</th><th>reason</th><th>count</th><th>total ms</th><th>avg ms</th><th>max ms</th></tr></thead>'
			. '<tbody>' . $rows . '</tbody>'
			. '</table>'
			. '</div>';
	}

	/**
	 * @return array{0: int, 1: int, 2: float} [totalCalls, totalFallbacks, totalMs]
	 */
	private function computeTotals(): array
	{
		$totalCalls = 0;
		$totalFallbacks = 0;
		$totalMs = 0.0;

		foreach (RustProxyProductsProvider::$callLog as $entry) {
			$totalCalls += $entry['count'];
			$totalMs += $entry['totalMs'];

			if ($entry['status'] !== 'fallback') {
				continue;
			}

			$totalFallbacks += $entry['count'];
		}

		return [$totalCalls, $totalFallbacks, $totalMs];
	}
}
