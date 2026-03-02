<?php

declare(strict_types=1);

namespace Eshop\Services\ProductsCache;

use Eshop\DB\Customer;
use Nette\Utils\Strings;
use StORM\DIConnection;
use Tracy\Debugger;
use Tracy\ILogger;

class GoProductsCacheDiffUpdateService extends ProductsCacheDiffUpdateService
{
	private const GO_BINARY_PATH = __DIR__ . '/../../../bin/cache-warmup';

	/**
	 * @param array<string|\Eshop\DB\Customer> $customers
	 * @param array<string|int> $customerGroups
	 * @param array<string|int> $merchants
	 */
	protected function diffUpdateVisibilityPriceTable(
		string $pricesCacheTableName,
		array $customers = [],
		array $customerGroups = [],
		array $merchants = [],
	): void {
		if (!$this->isGoBinaryAvailable()) {
			Debugger::log('Go binary not available, falling back to PHP', $this->logName);

			parent::diffUpdateVisibilityPriceTable($pricesCacheTableName, $customers, $customerGroups, $merchants);

			return;
		}

		try {
			$args = $this->buildGoArgs($customers, $customerGroups, $merchants);

			Debugger::log(\sprintf(
				'Go cache-warmup starting... | binary=%s | customers=%d customerGroups=%d merchants=%d dedup=%s workers=2',
				self::GO_BINARY_PATH,
				\count($customers),
				\count($customerGroups),
				\count($merchants),
				$this->isCacheDeduplicationEnabled() ? 'true' : 'false',
			), $this->logName);

			$startTime = \microtime(true);
			$result = $this->executeGoBinary($args);
			$elapsed = \round(\microtime(true) - $startTime, 1);

			Debugger::log(\sprintf('Go cache-warmup finished in %ss | result: %s', $elapsed, \json_encode($result)), $this->logName);
		} catch (\Throwable $e) {
			Debugger::log('Go cache-warmup failed, falling back to PHP: ' . $e->getMessage(), ILogger::EXCEPTION);

			parent::diffUpdateVisibilityPriceTable($pricesCacheTableName, $customers, $customerGroups, $merchants);
		}
	}

	private function isGoBinaryAvailable(): bool
	{
		return \is_file(self::GO_BINARY_PATH) && \is_executable(self::GO_BINARY_PATH);
	}

	/**
	 * @param array<string|\Eshop\DB\Customer> $customers
	 * @param array<string|int> $customerGroups
	 * @param array<string|int> $merchants
	 * @return array<string>
	 */
	private function buildGoArgs(array $customers, array $customerGroups, array $merchants): array
	{
		$prodDSN = $this->buildDSN($this->connection);
		$cacheDSN = $this->buildDSN($this->getConnection());

		$args = [
			'--prod-dsn', $prodDSN,
			'--cache-dsn', $cacheDSN,
		];

		if ($this->isCacheDeduplicationEnabled()) {
			$args[] = '--dedup';
		}

		// Shops
		$shops = $this->shopsConfig->getAvailableShops();

		if ($shops) {
			$shopUuids = [];

			foreach ($shops as $shop) {
				$shopUuids[] = $shop->getPK();
			}

			$args[] = '--shops';
			$args[] = \implode(',', $shopUuids);
		}

		// Default unregistered groups
		$unregisteredGroups = $this->settingsService->getAllDefaultUnregisteredGroups();

		if ($unregisteredGroups) {
			$args[] = '--default-unregistered-groups';
			$args[] = \implode(',', $unregisteredGroups);
		}

		// Resolve Customer objects to PKs
		$customerPKs = [];

		foreach ($customers as $customer) {
			$customerPKs[] = $customer instanceof Customer ? $customer->getPK() : (string) $customer;
		}

		if ($customerPKs) {
			$args[] = '--customers';
			$args[] = \implode(',', $customerPKs);
		}

		if ($customerGroups) {
			$args[] = '--customer-groups';
			$args[] = \implode(',', $customerGroups);
		}

		if ($merchants) {
			$args[] = '--merchants';
			$args[] = \implode(',', $merchants);
		}

		$args[] = '--workers';
		$args[] = '4';
		$args[] = '--verbose';

		return $args;
	}

	/**
	 * Build MySQL DSN string for Go's go-sql-driver/mysql format.
	 * Format: user:pass@tcp(host:port)/dbname
	 */
	private function buildDSN(DIConnection $connection): string
	{
		$user = $connection->getUser();
		$password = $this->getPrivateProperty($connection, 'password');
		$dsn = $this->getPrivateProperty($connection, 'dsn');

		// Parse PDO DSN: "mysql:dbname=xxx;host=yyy"
		$parts = [];
		$dsnBody = \explode(':', $dsn, 2)[1] ?? '';
		\parse_str(\str_replace(';', '&', $dsnBody), $parts);

		$host = \is_string($parts['host'] ?? null) ? $parts['host'] : 'localhost';
		$dbname = \is_string($parts['dbname'] ?? null) ? $parts['dbname'] : '';
		$port = \is_string($parts['port'] ?? null) ? $parts['port'] : '3306';

		return \sprintf('%s:%s@tcp(%s:%s)/%s?allowAllFiles=true', $user, $password, $host, $port, $dbname);
	}

	/**
	 * @param array<string> $args
	 * @return array<string, mixed>
	 */
	private function executeGoBinary(array $args): array
	{
		$cmd = self::GO_BINARY_PATH;

		$descriptorSpec = [
			0 => ['pipe', 'r'],
			1 => ['pipe', 'w'],
			2 => ['pipe', 'w'],
		];

		$escapedArgs = [];

		foreach ($args as $arg) {
			$escapedArgs[] = \escapeshellarg($arg);
		}

		$fullCmd = \escapeshellarg($cmd) . ' ' . \implode(' ', $escapedArgs);

		$process = \proc_open($fullCmd, $descriptorSpec, $pipes);

		if (!\is_resource($process)) {
			throw new \RuntimeException('Failed to start Go binary');
		}

		// Close stdin
		\fclose($pipes[0]);

		// Stream stderr line-by-line in real-time
		\stream_set_blocking($pipes[2], false);

		$stderrLines = [];
		$stdout = '';
		$stderrEof = false;
		$stdoutEof = false;

		while (!$stderrEof || !$stdoutEof) {
			$read = [];

			if (!$stdoutEof) {
				$read[] = $pipes[1];
			}

			if (!$stderrEof) {
				$read[] = $pipes[2];
			}

			$write = null;
			$except = null;

			if (\stream_select($read, $write, $except, 1) === false) {
				break;
			}

			foreach ($read as $stream) {
				if ($stream === $pipes[1]) {
					$chunk = \fread($pipes[1], 65536);

					if ($chunk === false || $chunk === '') {
						if (\feof($pipes[1])) {
							$stdoutEof = true;
						}
					} else {
						$stdout .= $chunk;
					}
				}

				if ($stream !== $pipes[2]) {
					continue;
				}

				$line = \fgets($pipes[2]);

				if ($line === false) {
					if (\feof($pipes[2])) {
						$stderrEof = true;
					}
				} else {
					$line = \rtrim($line, "\n\r");

					if ($line !== '') {
						Debugger::log('[Go] ' . $line, $this->logName);
						$stderrLines[] = $line;
					}
				}
			}
		}

		\fclose($pipes[1]);
		\fclose($pipes[2]);

		$exitCode = \proc_close($process);

		if ($exitCode !== 0) {
			$stderrStr = \implode("\n", $stderrLines);

			throw new \RuntimeException(\sprintf(
				'Go binary exited with code %d. Stderr: %s',
				$exitCode,
				Strings::substring($stderrStr, 0, 1000),
			));
		}

		if ($stdout === '') {
			throw new \RuntimeException('Go binary produced no output');
		}

		$result = \json_decode($stdout, true, 512, \JSON_THROW_ON_ERROR);

		if (!\is_array($result)) {
			throw new \RuntimeException('Go binary output is not a JSON object');
		}

		return $result;
	}

	private function getPrivateProperty(object $object, string $property): string
	{
		$reflection = new \ReflectionClass($object);

		// Walk up class hierarchy to find the property
		while ($reflection !== false) {
			if ($reflection->hasProperty($property)) {
				$prop = $reflection->getProperty($property);

				return (string) $prop->getValue($object);
			}

			$reflection = $reflection->getParentClass();
		}

		throw new \RuntimeException(\sprintf('Property %s not found on %s', $property, $object::class));
	}
}
