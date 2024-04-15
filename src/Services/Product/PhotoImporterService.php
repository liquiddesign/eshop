<?php

namespace Eshop\Services\Product;

use Base\Bridges\AutoWireService;
use Eshop\DB\PhotoRepository;
use League\Csv\Reader;
use Nette\Utils\Strings;
use StORM\Connection;

class PhotoImporterService implements AutoWireService
{
	public function __construct(protected readonly PhotoRepository $photoRepository, protected readonly Connection $connection)
	{
	}

	/**
	 * @param string $filePath
	 * @param string $delimiter
	 * @param array<string> $importColumns
	 * @return array<string|int>
	 */
	public function importCsv(string $filePath, string $delimiter = ';', array $importColumns = [],): array
	{
		$updatedPhotos = 0;
		$mutations = $this->photoRepository->getConnection()->getAvailableMutations();

		$reader = Reader::createFromPath($filePath, 'r');
		$reader->setDelimiter($delimiter);
		$reader->setHeaderOffset(0);

		$header = $reader->getHeader();
		$parsedHeader = [];

		foreach ($header as $headerItem) {
			if (isset($importColumns[$headerItem])) {
				$parsedHeader[$headerItem] = $headerItem;

				continue;
			}

			if ($key = \array_search($headerItem, $importColumns)) {
				$parsedHeader[$headerItem] = $key;

				continue;
			}

			foreach ($mutations as $mutation => $suffix) {
				if (\str_ends_with($headerItem, $suffix)) {
					$headerItemWithMutation = $headerItem;
					$headerItemWithoutMutation = Strings::substring($headerItemWithMutation, 0, Strings::indexOf($headerItem, $suffix));

					if (isset($importColumns[$headerItemWithoutMutation])) {
						$parsedHeader[$headerItemWithMutation] = [$headerItemWithoutMutation, $mutation];
					} elseif ($key = \array_search($headerItemWithoutMutation, $importColumns)) {
						$parsedHeader[$headerItemWithMutation] = [$key, $mutation];
					}

					continue 2;
				}
			}
		}

		$i = 1;

		foreach ($reader->getRecords() as $record) {
			$photoArray = [];

			foreach ($parsedHeader as $key => $column) {
				foreach ($mutations as $mutation => $suffix) {
					if (\str_ends_with($key, $suffix)) {
						$keyWithoutMutation = Strings::substring($column, 0, Strings::indexOf($column, $suffix));

						$photoArray[$keyWithoutMutation][$mutation] = $record[$key];

						continue 2;
					}
				}

				$photoArray[$column] = $record[$key];
			}

			if (!isset($photoArray['uuid'])) {
				throw new \Exception('Missing uuid on line ' . $i);
			}

			$i++;

			$this->photoRepository->many()->where('this.uuid', $photoArray['uuid'])->update($photoArray);
		}

		return [
			'updatedPhotos' => $updatedPhotos,
		];
	}
}
