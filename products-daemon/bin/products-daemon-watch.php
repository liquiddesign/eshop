<?php

declare(strict_types=1);

/**
 * Watchdog pro abel-products-daemon. Spouští se z cronu (doporučeno `* * * * *`), co minutu:
 *
 * 1. Pošle `ping` na socket daemonu.
 * 2. Pokud daemon odpoví během timeoutu, exit 0 — vše běží.
 * 3. Pokud ne (daemon spadl, socket chybí, hang), spawne daemon přes `proc_open` s detachnutým STDIO
 *    a zapíše PID do temp souboru. Další minutu cron ověří zda naběhl.
 *
 * Usage (v abel crontab nebo DDEV hostitelském cronu):
 *     * * * * * php /var/www/html/vendor/liquiddesign/eshop/products-daemon/bin/products-daemon-watch.php \
 *         --socket /tmp/abel-products-daemon.sock \
 *         --binary /var/www/html/vendor/liquiddesign/eshop/bin/products-daemon-linux-x86_64 \
 *         --env-file /var/www/html/vendor/liquiddesign/eshop/products-daemon/.env \
 *         --pid-file /tmp/abel-products-daemon.pid \
 *         --log /tmp/abel-products-daemon.log
 *
 * Návratové kódy:
 *     0 — daemon OK (už běžel nebo byl úspěšně spuštěn)
 *     1 — chyba (binárka chybí, spawn selhal, po retry se daemon neozval)
 */

$options = \getopt('', ['socket:', 'binary:', 'env-file:', 'pid-file:', 'log:', 'timeout:']);

$socketPath = (string) ($options['socket'] ?? '/tmp/abel-products-daemon.sock');
$binaryPath = (string) ($options['binary'] ?? '');
$envFile = (string) ($options['env-file'] ?? '');
$pidFile = (string) ($options['pid-file'] ?? '/tmp/abel-products-daemon.pid');
$logFile = (string) ($options['log'] ?? '/tmp/abel-products-daemon.log');
$timeoutSec = (float) ($options['timeout'] ?? 1.0);

if ($binaryPath === '' || !\is_executable($binaryPath)) {
	\fwrite(\STDERR, "[daemon-watch] binárka neexistuje nebo není spustitelná: '{$binaryPath}'\n");
	exit(1);
}

// --- 1. Ping daemon ------------------------------------------------------------------------
if (pingDaemon($socketPath, $timeoutSec)) {
	// Daemon běží; refresh pidfile pro monitorování třetí stranou.
	if (\file_exists($pidFile)) {
		$pid = (int) \file_get_contents($pidFile);

		if ($pid > 0 && isProcessAlive($pid)) {
			exit(0);
		}
	}

	exit(0);
}

// --- 2. Daemon nereaguje, spawn ------------------------------------------------------------
// Pokud starý PID existuje a proces žije, ale socket je mrtvý, pošleme SIGTERM a počkáme 2 s.
if (\file_exists($pidFile)) {
	$oldPid = (int) \file_get_contents($pidFile);

	if ($oldPid > 0 && isProcessAlive($oldPid)) {
		\fwrite(\STDERR, "[daemon-watch] socket mrtvý, proces {$oldPid} stále žije — posílám SIGTERM\n");
		sendSignal($oldPid, 'TERM');

		$deadline = \microtime(true) + 2.0;

		while (\microtime(true) < $deadline && isProcessAlive($oldPid)) {
			\usleep(100_000);
		}

		if (isProcessAlive($oldPid)) {
			\fwrite(\STDERR, "[daemon-watch] proces {$oldPid} neodpovídá na SIGTERM, SIGKILL\n");
			sendSignal($oldPid, 'KILL');
		}
	}

	@\unlink($pidFile);
}

$cmd = \escapeshellarg($binaryPath)
	. ($envFile !== '' ? ' --env-file ' . \escapeshellarg($envFile) : '')
	. ' --socket ' . \escapeshellarg($socketPath)
	. ' --log-file ' . \escapeshellarg($logFile);

// Detach: daemon sám píše tracing do --log-file. Stderr (panic/segfault mimo tracing) směřujeme do
// stejného souboru pro post-mortem — Linux append-mode zaručuje atomický write pro krátké zprávy.
// setsid odtrhne proces od cron session, nohup zabrání SIGHUP.
$fullCmd = "nohup setsid {$cmd} </dev/null >/dev/null 2>> " . \escapeshellarg($logFile) . ' & echo $!';
$spawnPid = (int) \shell_exec($fullCmd);

if ($spawnPid <= 0) {
	\fwrite(\STDERR, "[daemon-watch] spawn selhal (shell_exec vrátil '{$spawnPid}')\n");
	exit(1);
}

\file_put_contents($pidFile, (string) $spawnPid);

// --- 3. Čekáme na ready signal -------------------------------------------------------------
// Snapshot build proti plnému katalogu trvá cca 5–15 s; počkáme až 30 s než daemon začne odpovídat.
$deadline = \microtime(true) + 30.0;

while (\microtime(true) < $deadline) {
	if (pingDaemon($socketPath, $timeoutSec)) {
		\fwrite(\STDOUT, "[daemon-watch] spuštěn, PID {$spawnPid}\n");
		exit(0);
	}

	// Proces ještě může buildovat snapshot — zkontrolujeme, že aspoň žije.
	if (!isProcessAlive($spawnPid)) {
		\fwrite(\STDERR, "[daemon-watch] proces {$spawnPid} předčasně ukončen; viz {$logFile}\n");
		exit(1);
	}

	\usleep(500_000);
}

\fwrite(\STDERR, "[daemon-watch] daemon se neozval do 30 s; PID {$spawnPid} možná stále staví snapshot\n");
// Neselžeme — další minutu cron ověří. Daemon proces žije, nechce zabíjet.
exit(0);

// --- Helpers -----------------------------------------------------------------------------

/**
 * Pošle length-prefixed ping frame na daemon socket a čeká na odpověď.
 * Frame protokol: [u32 BE length][JSON bytes].
 */
function pingDaemon(string $socketPath, float $timeoutSec): bool
{
	if (!\file_exists($socketPath)) {
		return false;
	}

	$errno = 0;
	$errstr = '';
	$client = @\stream_socket_client('unix://' . $socketPath, $errno, $errstr, $timeoutSec);

	if ($client === false) {
		return false;
	}

	\stream_set_timeout($client, (int) $timeoutSec, (int) (($timeoutSec - (int) $timeoutSec) * 1_000_000));

	$payload = \json_encode(['method' => 'ping'], \JSON_UNESCAPED_SLASHES);

	if ($payload === false) {
		\fclose($client);

		return false;
	}

	$frame = \pack('N', \strlen($payload)) . $payload;
	$written = @\fwrite($client, $frame);

	if ($written === false || $written !== \strlen($frame)) {
		\fclose($client);

		return false;
	}

	// Přečteme 4 B length prefix + payload.
	$headerBytes = @\fread($client, 4);

	if ($headerBytes === false || \strlen($headerBytes) < 4) {
		\fclose($client);

		return false;
	}

	$unpacked = \unpack('Nlen', $headerBytes);

	if (!\is_array($unpacked) || !isset($unpacked['len'])) {
		\fclose($client);

		return false;
	}

	$len = (int) $unpacked['len'];
	$body = $len > 0 ? (string) @\fread($client, $len) : '';
	\fclose($client);

	return \str_contains($body, '"ok":true');
}

/**
 * Non-destructive check — preferuje posix_kill signal 0, jinak shell `kill -0` (funguje bez posix extension).
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
 * Pošle signál procesu. Preferuje posix_kill, jinak shell `kill -s SIGNAL $pid`.
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
