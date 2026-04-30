<?php

declare(strict_types=1);

/**
 * Graceful restart pro abel-products-daemon. Volá se při deploy nové verze binárky.
 *
 * Pořadí:
 * 1. Najde PID běžícího daemonu z pidfile (nebo přes `pgrep` fallback).
 * 2. Pošle SIGTERM → daemon vystoupí z tokio::select! a ukončí se po dokončení probíhajících requestů.
 *    Drain perioda: max 10 s. Během této doby PHP proxy dostává connection-refused (pokud už socket
 *    přestal přijímat) nebo hung read (pokud ještě má aktivní handlery) → fallback na LiveProvider.
 * 3. Pokud daemon nevyšel do 10 s, SIGKILL jako fallback.
 * 4. Odstraní socket soubor a pidfile (stream_socket_server Rust chová si vlastní, ale jistota).
 * 5. Spawne nový daemon (delegováno na watchdog) a čeká na ping.
 *
 * Trade-off: fallback ratio vyskočí během drain okna (~5–15 s). Plně-zero-downtime by
 * vyžadoval blue-green s rename socketu — dřívější M3 iterace to nemá, aktuální PHP fallback
 * je pojistka dostatečná.
 *
 * Usage:
 *     php products-daemon-restart.php \
 *         --binary /var/www/html/vendor/liquiddesign/eshop/bin/products-daemon-linux-x86_64 \
 *         --env-file /var/www/html/vendor/liquiddesign/eshop/products-daemon/.env \
 *         --socket /tmp/abel-products-daemon.sock \
 *         --pid-file /tmp/abel-products-daemon.pid \
 *         --log /tmp/abel-products-daemon.log
 */

$options = \getopt('', ['socket:', 'binary:', 'env-file:', 'pid-file:', 'log:', 'drain-timeout:']);

$socketPath = (string) ($options['socket'] ?? '/tmp/abel-products-daemon.sock');
$binaryPath = (string) ($options['binary'] ?? '');
$envFile = (string) ($options['env-file'] ?? '');
$pidFile = (string) ($options['pid-file'] ?? '/tmp/abel-products-daemon.pid');
$logFile = (string) ($options['log'] ?? '/tmp/abel-products-daemon.log');
$drainTimeout = (int) ($options['drain-timeout'] ?? 10);

if ($binaryPath === '' || !\is_executable($binaryPath)) {
	\fwrite(\STDERR, "[daemon-restart] binárka neexistuje: '{$binaryPath}'\n");
	exit(1);
}

// --- 1. Najdi starý PID --------------------------------------------------------------------
$oldPid = 0;

if (\file_exists($pidFile)) {
	$oldPid = (int) \file_get_contents($pidFile);
}

if ($oldPid <= 0 || !isProcessAlive($oldPid)) {
	// pidfile chybí nebo ukazuje na mrtvý proces; zkusíme pgrep jako fallback.
	$pgrepOut = \shell_exec('pgrep -f abel-products-daemon 2>/dev/null');
	$candidates = $pgrepOut !== null ? \array_filter(\array_map('intval', \explode("\n", \trim($pgrepOut)))) : [];

	foreach ($candidates as $candidate) {
		if (isProcessAlive($candidate)) {
			$oldPid = $candidate;

			break;
		}
	}
}

// --- 2. SIGTERM + drain --------------------------------------------------------------------
if ($oldPid > 0) {
	\fwrite(\STDOUT, "[daemon-restart] posílám SIGTERM PID {$oldPid} (drain timeout {$drainTimeout}s)\n");
	sendSignal($oldPid, 'TERM');

	$deadline = \microtime(true) + $drainTimeout;
	$exited = false;

	while (\microtime(true) < $deadline) {
		if (!isProcessAlive($oldPid)) {
			$exited = true;

			break;
		}

		\usleep(200_000);
	}

	if (!$exited) {
		\fwrite(\STDERR, "[daemon-restart] daemon nereagoval na SIGTERM do {$drainTimeout}s, posílám SIGKILL\n");
		sendSignal($oldPid, 'KILL');
		\usleep(500_000);
	}
} else {
	\fwrite(\STDOUT, "[daemon-restart] žádný běžící daemon nenalezen, spouštím nový\n");
}

// --- 3. Cleanup socket + pidfile ----------------------------------------------------------
if (\file_exists($socketPath)) {
	@\unlink($socketPath);
}

if (\file_exists($pidFile)) {
	@\unlink($pidFile);
}

// --- 4. Spawn nové instance přes watchdog (konzistentní spawn path) -----------------------
$watchScript = __DIR__ . '/products-daemon-watch.php';

if (!\is_file($watchScript)) {
	\fwrite(\STDERR, "[daemon-restart] watchdog skript nenalezen: {$watchScript}\n");
	exit(1);
}

$cmd = 'php ' . \escapeshellarg($watchScript)
	. ' --socket ' . \escapeshellarg($socketPath)
	. ' --binary ' . \escapeshellarg($binaryPath)
	. ($envFile !== '' ? ' --env-file ' . \escapeshellarg($envFile) : '')
	. ' --pid-file ' . \escapeshellarg($pidFile)
	. ' --log ' . \escapeshellarg($logFile);

\passthru($cmd, $exitCode);

exit($exitCode);

// --- Helpers -----------------------------------------------------------------------------

/**
 * Non-destructive "is process alive" probe. Preferuje `posix_kill($pid, 0)` pokud je posix
 * extension dostupná; jinak fallback na shell `kill -0 $pid`, který funguje bez extension.
 */
function isProcessAlive(int $pid): bool
{
	if ($pid <= 0) {
		return false;
	}

	if (\function_exists('posix_kill')) {
		return \posix_kill($pid, 0);
	}

	$result = 0;
	// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged
	@\exec('kill -0 ' . \escapeshellarg((string) $pid) . ' 2>/dev/null', $_output, $result);

	return $result === 0;
}

/**
 * Pošle signál procesu. Preferuje `posix_kill`, jinak shell `kill -s SIGNAL $pid`.
 * Signal musí být textový ('TERM', 'KILL', atd.) pro kompatibilitu se shell variantou.
 */
function sendSignal(int $pid, string $signal): bool
{
	if ($pid <= 0) {
		return false;
	}

	if (\function_exists('posix_kill')) {
		$signalConst = \defined('SIG' . $signal) ? \constant('SIG' . $signal) : 15;

		return \posix_kill($pid, $signalConst);
	}

	$result = 0;
	// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged
	@\exec('kill -s ' . \escapeshellarg($signal) . ' ' . \escapeshellarg((string) $pid) . ' 2>/dev/null', $_output, $result);

	return $result === 0;
}
