<?php

declare(strict_types=1);

namespace Eshop\DB;

use Carbon\Carbon;
use Nette\Application\ApplicationException;
use Nette\IOException;
use Nette\Utils\FileSystem;
use Nette\Utils\Helpers;
use Nette\Utils\Random;
use Tracy\Logger;

/**
 * @extends \StORM\Repository<\Eshop\DB\ImportResult>
 */
class ImportResultRepository extends \StORM\Repository
{
	private ImportResult $importResult;
	
	private string $logFilePath;
	
	private string $logDirectory;
	
	public function createLog(Supplier $supplier, string $directory, string $type = 'import'): ImportResult
	{
		$id = (new Carbon())->format('Y-m-d-g-i-s') . '-' . $supplier->code . '-' . Random::generate(4);
		$this->importResult = $this->createOne([
			'supplier' => $supplier,
			'id' => $id,
			'type' => $type,
		]);
		
		$this->logDirectory = $directory;
		$this->logFilePath = $directory . '/' . $id . '.log';

		$typeMsg = match ($type) {
			'import' => 'Import started',
			'importAmount' => 'Import amount started',
			default => 'Entry started',
		};

		$this->log($typeMsg);

		return $this->importResult;
	}
	
	public function log($message): void
	{
		$line = Logger::formatLogLine($message . ' (cur: ' . \round(\memory_get_usage(true) / 1024 / 1024, 2) . ' MB, max: ' . \round(\memory_get_peak_usage(true) / 1024 / 1024, 2) . ' MB)');
		
		try {
			Filesystem::write($this->getLogFilePath(), $line . \PHP_EOL, \FILE_APPEND | \LOCK_EX);
			FileSystem::makeWritable($this->getLogFilePath());
		} catch (IOException) {
			throw new \RuntimeException('Unable to write to log file ' . $this->getLogFilePath() . '. Is directory writable?');
		}
	}
	
	public function markAsError(string $message): void
	{
		if (!isset($this->importResult)) {
			throw new ApplicationException('Result is not set. Call createLog first.');
		}
		
		$this->importResult->update([
			'status' => 'error',
			'errorMessage' => $message,
			'finishedTs' => (string) (new Carbon()),
		]);
		
		$this->log('Import fatal error: ' . $message);
	}
	
	public function markAsReceived($data, ?string $extension = null, string $suffix = ''): void
	{
		if (!isset($this->importResult)) {
			throw new ApplicationException('Result is not set. Call createLog first.');
		}
		
		FileSystem::createDir($this->logDirectory . '/data');
		
		if ($data instanceof \SimpleXMLElement) {
			$extension = 'xml';
			$importFile = $this->importResult->id . $suffix . ".$extension";
			$data->asXML($this->logDirectory . '/data/' . $importFile);
		} elseif ($extension === null) {
			$extension = \pathinfo($data, \PATHINFO_EXTENSION);
			$importFile = $this->importResult->id . ".$extension";
			FileSystem::copy($data, $this->logDirectory . '/data/' . $importFile);
		} else {
			$importFile = $this->importResult->id . $suffix . ".$extension";
			\file_put_contents($this->logDirectory . '/data/' . $importFile, $data);
		}
		
		$this->importResult->update([
			'status' => 'received',
			'receivedTs' => (string) (new Carbon()),
			'importSize' => Helpers::falseToNull(\filesize($this->logDirectory . '/data/' . $importFile)),
			'importFile' => $importFile,
		]);
		
		$this->log('Import data received: ' . $importFile . ' (' . Helpers::falseToNull(\filesize($this->logDirectory . '/data/' . $importFile)) . ')');
	}
	
	public function markAsImported($provider): void
	{
		if (!isset($this->importResult)) {
			throw new ApplicationException('Result is not set. Call createLog first.');
		}
		
		$this->importResult->update([
			'status' => 'ok',
			'finishedTs' => (string) (new Carbon()),
			'insertedCount' => $provider->insertedCount,
			'updatedCount' => $provider->updatedCount,
			'skippedCount' => $provider->skippedCount,
			'imageDownloadCount' => $provider->imageDownloadCount,
			'imageErrorCount' => $provider->imageErrorCount,
		]);
		
		$this->log("inserted: $provider->insertedCount, updated: $provider->updatedCount, skipped: $provider->skippedCount, 
		images: $provider->imageDownloadCount, images errors: $provider->imageErrorCount ");
		$this->log('Import finished ok');
	}
	
	public function markAsEntered(int $insertedCount, int $updatedCount, int $lockedCount, int $imagesCount): void
	{
		unset($lockedCount);

		if (!isset($this->importResult)) {
			throw new ApplicationException('Result is not set. Call createLog first.');
		}
		
		$this->importResult->update([
			'status' => 'ok',
			'finishedTs' => (string) (new Carbon()),
			'insertedCount' => $insertedCount,
			'updatedCount' => $updatedCount,
			'imageDownloadCount' => $imagesCount,
		]);
		
		$this->log('Catalog entry finished ok');
	}
	
	public function isLogStarted(): bool
	{
		return isset($this->importResult);
	}
	
	private function getLogFilePath(): string
	{
		if (!isset($this->logFilePath)) {
			throw new ApplicationException('Log file is not set. Call createLog first.');
		}
		
		return $this->logFilePath;
	}
}
