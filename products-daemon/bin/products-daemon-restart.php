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

if ($oldPid <= 0 || !\posix_kill($oldPid, 0)) {
	// pidfile chybí nebo ukazuje na mrtvý proces; zkusíme pgrep jako fallback.
	$pgrepOut = \shell_exec('pgrep -f abel-products-daemon 2>/dev/null');
	$candidates = $pgrepOut !== null ? \array_filter(\array_map('intval', \explode("\n", \trim($pgrepOut)))) : [];

	foreach ($candidates as $candidate) {
		if (\posix_kill($candidate, 0)) {
			$oldPid = $candidate;

			break;
		}
	}
}

// --- 2. SIGTERM + drain --------------------------------------------------------------------
if ($oldPid > 0) {
	\fwrite(\STDOUT, "[daemon-restart] posílám SIGTERM PID {$oldPid} (drain timeout {$drainTimeout}s)\n");
	\posix_kill($oldPid, \SIGTERM);

	$deadline = \microtime(true) + $drainTimeout;
	$exited = false;

	while (\microtime(true) < $deadline) {
		if (!\posix_kill($oldPid, 0)) {
			$exited = true;

			break;
		}

		\usleep(200_000);
	}

	if (!$exited) {
		\fwrite(\STDERR, "[daemon-restart] daemon nereagoval na SIGTERM do {$drainTimeout}s, posílám SIGKILL\n");
		\posix_kill($oldPid, \SIGKILL);
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
