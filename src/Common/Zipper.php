<?php
declare(strict_types=1);

namespace Eshop\Common;

use Nette\Application\Application;
use Nette\Utils\FileSystem;
use Tracy\Debugger;
use Tracy\ILogger;

class Zipper
{
	public const CONTENT_TYPE = 'application/zip';

	private \ZipArchive $zipArchive;

	private string $fileName;

	public function __construct(string $tempDir, Application $application)
	{
		$this->zipArchive = new \ZipArchive();

		$this->fileName = $tempFilename = \tempnam($tempDir, 'zip');

		if (!$tempFilename) {
			throw new \Exception('Can´t create temp file');
		}

		if ($this->zipArchive->open($tempFilename, \ZipArchive::OVERWRITE) !== true) {
			throw new \Exception('ZIP cannot be created');
		}

		$application->onShutdown[] = function () use ($tempFilename): void {
			try {
				FileSystem::delete($tempFilename);
			} catch (\Throwable $e) {
				Debugger::log($e, ILogger::WARNING);
			}
		};
	}

	public function addFile(string $filepath, string $entryname, int $start = 0, int $length = 0): bool
	{
		return $this->zipArchive->addFile($filepath, $entryname, $start, $length);
	}

	public function close(): string
	{
		$this->zipArchive->close();

		return $this->fileName;
	}
}
