<?php

declare(strict_types=1);

namespace Eshop\Services\Related;

use Base\Bridges\AutoWireService;
use Eshop\DB\Related;
use Nette\DI\Container;

/**
 * Service for checking file existence in the filesystem.
 * Primarily used for verifying FTP-uploaded files for Related images.
 */
readonly class FileExistenceService implements AutoWireService
{
	private string $wwwDir;

	public function __construct(Container $container,)
	{
		$this->wwwDir = $container->getParameter('wwwDir');
	}

	/**
	 * Check if a file exists at the given path relative to userfiles.
	 */
	public function fileExists(string $relativePath): bool
	{
		$fullPath = $this->wwwDir . '/userfiles/' . $relativePath;

		return \file_exists($fullPath);
	}

	/**
	 * Check if the expected image file exists for a Related entity.
	 * Uses the temporary folder name for FTP uploads.
	 */
	public function relatedImageExists(Related $related, string $folderName = 'related_ftp_uploads'): bool
	{
		$imageName = $related->getExpectedImageName();

		if ($imageName === null) {
			return false;
		}

		return $this->fileExists($folderName . '/' . $imageName);
	}

	/**
	 * Get the full expected path for a Related image.
	 */
	public function getRelatedImagePath(Related $related, string $folderName = 'related_ftp_uploads'): ?string
	{
		$imageName = $related->getExpectedImageName();

		if ($imageName === null) {
			return null;
		}

		return $this->wwwDir . '/userfiles/' . $folderName . '/' . $imageName;
	}

	/**
	 * Check multiple Related entities for image existence.
	 * Returns array with Related PK as key and boolean as value.
	 * @param array<\Eshop\DB\Related> $relatedEntities
	 * @return array<int|string, bool>
	 */
	public function checkMultipleRelatedImages(array $relatedEntities, string $folderName = 'related_ftp_uploads'): array
	{
		$results = [];

		foreach ($relatedEntities as $related) {
			$results[$related->getPK()] = $this->relatedImageExists($related, $folderName);
		}

		return $results;
	}
}
