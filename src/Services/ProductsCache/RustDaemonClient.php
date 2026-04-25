<?php

declare(strict_types=1);

namespace Eshop\Services\ProductsCache;

/**
 * Low-level client for the abel-products-daemon Unix socket.
 *
 * Protocol: length-prefixed JSON frames (`[u32 BE length][JSON]`), defined in
 * `/home/petr/eshop/products-daemon/src/protocol/framing.rs`.
 *
 * Failure modes:
 * - Socket cannot be reached (`ECONNREFUSED`, file missing) → throws {@see RustDaemonUnavailableException}
 *   after an optional spawn attempt.
 * - Wire error (truncated frame, malformed JSON) → {@see RustDaemonProtocolException}.
 * - Daemon returned `fallback_required: true` → {@see RustDaemonFallbackRequiredException}.
 * - Daemon returned an explicit error envelope → {@see RustDaemonRequestException}.
 *
 * Callers should catch the common base {@see RustDaemonException} and degrade gracefully
 * (typically by delegating to `LiveProductsProvider`).
 * @internal Not a part of the public API — use {@see GeneralProductsCacheProvider} instead.
 *           Direct injection of this class bypasses the provider abstraction and breaks
 *           the 'cache' / 'live' / 'rust' provider switch.
 */
final class RustDaemonClient
{
	public const PROTOCOL_VERSION = 2;

	public const MAX_FRAME_BYTES = 16 * 1024 * 1024;

	/** Seconds to wait for socket connect + response. Below 1s — don't stall PHP requests on a sick daemon. */
	private const DEFAULT_TIMEOUT_SEC = 0.5;

	/** Seconds to wait after spawning the daemon before retrying the socket. */
	private const SPAWN_WAIT_SEC = 2;

	/** @var resource|null */
	private mixed $socket = null;

	public function __construct(
		private readonly string $socketPath,
		private readonly string|null $binaryPath = null,
		private readonly string|null $envPath = null,
		private readonly float $timeoutSec = self::DEFAULT_TIMEOUT_SEC,
		private readonly bool $spawnOnDemand = true,
	) {
	}

	public function __destruct()
	{
		$this->close();
	}

	/**
	 * Round-trip: `{"method":"ping"}` → `{"type":"pong","ok":true}`.
	 * Returns true when the daemon is alive and speaks the expected protocol version.
	 */
	public function ping(): bool
	{
		try {
			$resp = $this->request(['method' => 'ping']);

			return isset($resp['type']) && $resp['type'] === 'pong' && ($resp['ok'] ?? false) === true;
		} catch (RustDaemonException) {
			return false;
		}
	}

	/**
	 * Diagnostic runtime stats — uptime, RSS, request counters, snapshot history.
	 * Vrací `null`, když daemon neběží, neumí `getStats` (starší binárka), nebo jakákoli
	 * chyba — Tracy panel je cosmetic, rozbíjet kvůli tomu request by bylo zbytečné.
	 *
	 * Shape odpovídá `StatsResponse` v `/home/petr/eshop/products-daemon/src/protocol/mod.rs`.
	 * @return array{
	 *     startedAtUnix: int,
	 *     uptimeSecs: int,
	 *     rssMb: int|null,
	 *     totalRequests: int,
	 *     workRequests: int,
	 *     avgRequestMs: float|null,
	 *     maxRequestMs: float,
	 *     snapshotTimestampsUnix: list<int>,
	 *     productCount: int,
	 *     priceCount: int,
	 *     snapshotMemoryEstimateMb: int,
	 *     schemaVersion: string
	 * }|null
	 */
	public function getStats(): array|null
	{
		try {
			$resp = $this->request(['method' => 'getStats']);
		} catch (RustDaemonException) {
			return null;
		}

		if (($resp['type'] ?? null) !== 'stats') {
			return null;
		}

		return [
			'startedAtUnix' => (int) ($resp['startedAtUnix'] ?? 0),
			'uptimeSecs' => (int) ($resp['uptimeSecs'] ?? 0),
			'rssMb' => isset($resp['rssMb']) && \is_int($resp['rssMb']) ? $resp['rssMb'] : null,
			'totalRequests' => (int) ($resp['totalRequests'] ?? 0),
			'workRequests' => (int) ($resp['workRequests'] ?? 0),
			'avgRequestMs' => isset($resp['avgRequestMs']) && \is_numeric($resp['avgRequestMs']) ? (float) $resp['avgRequestMs'] : null,
			'maxRequestMs' => (float) ($resp['maxRequestMs'] ?? 0),
			'snapshotTimestampsUnix' => \array_values(\array_map('intval', (array) ($resp['snapshotTimestampsUnix'] ?? []))),
			'productCount' => (int) ($resp['productCount'] ?? 0),
			'priceCount' => (int) ($resp['priceCount'] ?? 0),
			'snapshotMemoryEstimateMb' => (int) ($resp['snapshotMemoryEstimateMb'] ?? 0),
			// u64 hash — držíme jako string, viz StatsResponse::schema_version v Rust protokolu.
			'schemaVersion' => (string) ($resp['schemaVersion'] ?? '0'),
		];
	}

	/**
	 * Fire-and-forget trigger pro rebuild snapshotu. Daemon kumuluje notifikace do jednoho permitu
	 * (tokio `Notify::notify_one`), takže duplicitní volání nepřebijí rate limit
	 * (`MIN_REBUILD_INTERVAL_SECS` floor v Refresheru). Vrací `true`, když daemon potvrdil přijetí.
	 *
	 * Tichý fail (vrací `false`) při `RustDaemonException` — never-down kontrakt: drift probe
	 * to chytne nakonec sama.
	 */
	public function requestRebuild(): bool
	{
		try {
			$resp = $this->request(['method' => 'requestRebuild']);
		} catch (RustDaemonException) {
			return false;
		}

		return ($resp['type'] ?? null) === 'rebuildAccepted' && ($resp['queued'] ?? false) === true;
	}

	/**
	 * @param array<mixed> $params Serializable to JSON; field names use camelCase per GetProductsRequest struct.
	 * @return array<string, mixed> Raw decoded response body (without the envelope — the envelope is validated here).
	 * @throws \Eshop\Services\ProductsCache\RustDaemonException
	 */
	public function getProducts(array $params): array
	{
		$resp = $this->request([
			'method' => 'getProducts',
			'params' => $params,
		]);

		if (($resp['type'] ?? null) === 'fallbackRequired') {
			throw new RustDaemonFallbackRequiredException((string) ($resp['reason'] ?? 'daemon requested fallback'));
		}

		if (($resp['type'] ?? null) !== 'products') {
			throw new RustDaemonProtocolException('unexpected response type: ' . \json_encode($resp['type'] ?? null));
		}

		return $resp;
	}

	/**
	 * @param array<mixed> $params
	 * @return int Category count
	 * @throws \Eshop\Services\ProductsCache\RustDaemonException
	 */
	public function getCategoryCount(array $params): int
	{
		$resp = $this->request([
			'method' => 'getCategoryCount',
			'params' => $params,
		]);

		if (($resp['type'] ?? null) === 'fallbackRequired') {
			throw new RustDaemonFallbackRequiredException((string) ($resp['reason'] ?? 'daemon requested fallback'));
		}

		if (($resp['type'] ?? null) !== 'categoryCount') {
			throw new RustDaemonProtocolException('unexpected response type: ' . \json_encode($resp['type'] ?? null));
		}

		return (int) ($resp['count'] ?? 0);
	}

	/**
	 * Batched varianta — jedním round-tripem vrátí `categoryUuid → count` pro celý snapshot.
	 * Daemon iteruje `direct_category_bitmaps` (přímé produkt↔kategorie) a propaguje do
	 * flagged ancestors/descendants (mirror `ProductsCacheGetterService.php:734`).
	 *
	 * Caller vynechá `filters.categoryUuids` aby dostal counts pro celý katalog; při předání
	 * se mask ořízne na subtree.
	 * @param array<mixed> $params
	 * @return array<string, int> UUID kategorie → count produktů
	 * @throws \Eshop\Services\ProductsCache\RustDaemonException
	 */
	public function getAllCategoryCounts(array $params): array
	{
		$resp = $this->request([
			'method' => 'getAllCategoryCounts',
			'params' => $params,
		]);

		if (($resp['type'] ?? null) === 'fallbackRequired') {
			throw new RustDaemonFallbackRequiredException((string) ($resp['reason'] ?? 'daemon requested fallback'));
		}

		if (($resp['type'] ?? null) !== 'allCategoryCounts') {
			throw new RustDaemonProtocolException('unexpected response type: ' . \json_encode($resp['type'] ?? null));
		}

		$raw = $resp['counts'] ?? [];

		if (!\is_array($raw)) {
			throw new RustDaemonProtocolException('allCategoryCounts.counts is not a map');
		}

		$out = [];

		foreach ($raw as $uuid => $count) {
			$out[(string) $uuid] = (int) $count;
		}

		return $out;
	}

	/**
	 * @return list<string> PKs produktů, kteří mají cenu alespoň v jedné kombinaci v aktuálním snapshotu.
	 * @throws \Eshop\Services\ProductsCache\RustDaemonException
	 */
	public function getSellableProductPKs(): array
	{
		$resp = $this->request([
			'method' => 'getSellableProductPKs',
		]);

		if (($resp['type'] ?? null) !== 'sellableProductPKs') {
			throw new RustDaemonProtocolException('unexpected response type: ' . \json_encode($resp['type'] ?? null));
		}

		$raw = $resp['pks'] ?? [];

		if (!\is_array($raw)) {
			throw new RustDaemonProtocolException('sellableProductPKs.pks is not an array');
		}

		$out = [];

		foreach ($raw as $pk) {
			if (\is_string($pk) && $pk !== '') {
				$out[] = $pk;
			}
		}

		return $out;
	}

	/**
	 * Send one request envelope, parse response.
	 * @param array<mixed> $envelope
	 * @return array<string, mixed>
	 * @throws \Eshop\Services\ProductsCache\RustDaemonException
	 */
	private function request(array $envelope): array
	{
		$this->ensureConnected();

		$payload = \json_encode($envelope, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
		$payloadBytes = \mb_strlen($payload, '8bit');

		if ($payloadBytes > self::MAX_FRAME_BYTES) {
			throw new RustDaemonProtocolException('request frame too large: ' . $payloadBytes . ' B');
		}

		$this->writeFrame($payload);
		$responseBytes = $this->readFrame();

		try {
			$decoded = \json_decode($responseBytes, associative: true, flags: \JSON_THROW_ON_ERROR);
		} catch (\JsonException $e) {
			throw new RustDaemonProtocolException('malformed JSON in response: ' . $e->getMessage(), previous: $e);
		}

		if (!\is_array($decoded)) {
			throw new RustDaemonProtocolException('response is not a JSON object');
		}

		if (isset($decoded['error'])) {
			throw new RustDaemonRequestException(
				(string) $decoded['error'],
				(string) ($decoded['message'] ?? ''),
			);
		}

		$version = (int) ($decoded['protocolVersion'] ?? 0);

		if ($version !== self::PROTOCOL_VERSION) {
			throw new RustDaemonProtocolException(
				\sprintf('protocol version mismatch: client=%d server=%d', self::PROTOCOL_VERSION, $version),
			);
		}

		// OkResponse flattens the body with `#[serde(flatten)]` — fields are siblings of `protocolVersion`.
		return $decoded;
	}

	private function ensureConnected(): void
	{
		if ($this->socket !== null && \is_resource($this->socket)) {
			return;
		}

		$this->socket = $this->openSocket();

		if ($this->socket !== null) {
			return;
		}

		if ($this->spawnOnDemand && $this->binaryPath !== null && \is_executable($this->binaryPath)) {
			$this->spawnDaemon();
			$this->socket = $this->openSocket();
		}

		if ($this->socket === null) {
			throw new RustDaemonUnavailableException(\sprintf(
				'cannot connect to rust daemon socket: %s (spawn_attempted=%s)',
				$this->socketPath,
				$this->spawnOnDemand ? 'yes' : 'no',
			));
		}
	}

	/** @return resource|null */
	private function openSocket(): mixed
	{
		$errno = 0;
		$errstr = '';
		// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged
		$sock = @\stream_socket_client(
			'unix://' . $this->socketPath,
			$errno,
			$errstr,
			$this->timeoutSec,
			\STREAM_CLIENT_CONNECT,
		);

		if ($sock === false) {
			return null;
		}

		\stream_set_timeout($sock, 0, (int) ($this->timeoutSec * 1000000));
		\stream_set_blocking($sock, true);

		return $sock;
	}

	private function spawnDaemon(): void
	{
		if ($this->binaryPath === null) {
			return;
		}

		$envArg = $this->envPath !== null ? ' --env-file ' . \escapeshellarg($this->envPath) : '';
		$socketArg = ' --socket ' . \escapeshellarg($this->socketPath);

		// Detached background launch via `nohup ... &`. Tracing output goes through --log-file
		// (daemon opens the file itself); stderr appended to the same file for panic/segfault
		// capture. Linux append-mode is atomic for short writes, so no race.
		$logDir = \defined('LOG_DIR') ? \LOG_DIR : \sys_get_temp_dir();
		$logFile = \rtrim($logDir, '/') . '/abel-products-daemon.log';
		$logArg = ' --log-file ' . \escapeshellarg($logFile);

		$cmd = \sprintf(
			'nohup %s%s%s%s </dev/null >/dev/null 2>> %s &',
			\escapeshellcmd($this->binaryPath),
			$envArg,
			$socketArg,
			$logArg,
			\escapeshellarg($logFile),
		);

		// `exec` so PHP doesn't inherit the child; `nohup ... &` detaches it from the PHP-FPM worker lifecycle.
		// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged
		@\exec($cmd);

		// Give the daemon a moment to bind the socket. Longer than DEFAULT_TIMEOUT_SEC on purpose —
		// this path is a cold-start recovery and shouldn't happen on every request.
		$deadline = \microtime(true) + self::SPAWN_WAIT_SEC;

		while (\microtime(true) < $deadline) {
			if (\file_exists($this->socketPath)) {
				return;
			}

			\usleep(50000);
		}
	}

	private function writeFrame(string $payload): void
	{
		$socket = $this->assertConnected();
		$payloadBytes = \mb_strlen($payload, '8bit');
		$header = \pack('N', $payloadBytes);
		$written = \fwrite($socket, $header . $payload);

		if ($written === false || $written !== 4 + $payloadBytes) {
			$this->close();

			throw new RustDaemonProtocolException('short write to daemon socket');
		}
	}

	private function readFrame(): string
	{
		$header = $this->readExact(4);
		$len = \unpack('N', $header)[1] ?? null;

		if ($len === null || !\is_int($len)) {
			$this->close();

			throw new RustDaemonProtocolException('could not parse frame header');
		}

		if ($len < 0 || $len > self::MAX_FRAME_BYTES) {
			$this->close();

			throw new RustDaemonProtocolException('frame header advertised oversize payload: ' . $len);
		}

		return $this->readExact($len);
	}

	private function readExact(int $bytes): string
	{
		$socket = $this->assertConnected();

		if ($bytes === 0) {
			return '';
		}

		$buf = '';
		$bufBytes = 0;

		while ($bufBytes < $bytes) {
			$remaining = $bytes - $bufBytes;
			$chunk = \fread($socket, \max(1, $remaining));

			if ($chunk === false || $chunk === '') {
				$meta = \stream_get_meta_data($socket);
				$this->close();

				if ($meta['timed_out']) {
					throw new RustDaemonProtocolException('read timed out');
				}

				throw new RustDaemonProtocolException('socket closed mid-frame');
			}

			$buf .= $chunk;
			$bufBytes += \mb_strlen($chunk, '8bit');
		}

		return $buf;
	}

	/**
	 * @return resource
	 */
	private function assertConnected(): mixed
	{
		if ($this->socket === null || !\is_resource($this->socket)) {
			throw new RustDaemonUnavailableException('socket not connected');
		}

		return $this->socket;
	}

	private function close(): void
	{
		if ($this->socket !== null && \is_resource($this->socket)) {
			// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged
			@\fclose($this->socket);
		}

		$this->socket = null;
	}
}
