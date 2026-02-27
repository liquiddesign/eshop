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
	private const GO_BINARY_PATH = '/var/www/html/bin/cache-warmup';

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
			$result = $this->executeGoBinary($args);

			Debugger::log('Go cache-warmup result: ' . \json_encode($result), $this->logName);
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

		// Read stdout
		$stdout = \stream_get_contents($pipes[1]);
		\fclose($pipes[1]);

		// Read stderr and log each line
		$stderr = \stream_get_contents($pipes[2]);
		\fclose($pipes[2]);

		if ($stderr !== false && $stderr !== '') {
			foreach (\explode("\n", Strings::trim($stderr)) as $line) {
				if ($line !== '') {
					Debugger::log('[Go] ' . $line, $this->logName);
				}
			}
		}

		$exitCode = \proc_close($process);

		if ($exitCode !== 0) {
			throw new \RuntimeException(\sprintf(
				'Go binary exited with code %d. Stderr: %s',
				$exitCode,
				$stderr !== false ? Strings::substring($stderr, 0, 1000) : '',
			));
		}

		if ($stdout === false || $stdout === '') {
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
