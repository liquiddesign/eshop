<?php

namespace Eshop\Services\Product;

use Base\Bridges\AutoWireService;
use Eshop\DB\PhotoRepository;
use League\Csv\EncloseField;
use League\Csv\Writer;
use Nette\Utils\Strings;
use StORM\Collection;
use StORM\DIConnection;

class PhotoExporterService implements AutoWireService
{
	public function __construct(
		protected readonly PhotoRepository $photoRepository,
		protected readonly DIConnection $connection,
	) {
	}

	/**
	 * @param \StORM\Collection<\Eshop\DB\Photo> $photos
	 * @param \League\Csv\Writer $writer
	 * @param array<string, string> $columns
	 * @param string $delimiter
	 * @throws \League\Csv\CannotInsertRecord
	 * @throws \League\Csv\Exception
	 * @throws \League\Csv\InvalidArgument
	 */
	public function exportCsv(Collection $photos, Writer $writer, array $columns = [], string $delimiter = ';',): void
	{
		$this->connection->setDebug(false);

		$writer->setDelimiter($delimiter);
		$writer->setFlushThreshold(100);

		EncloseField::addTo($writer, "\t\22");

		$writer->insertOne(\array_merge([
			'Klíč',
			'Kód produktu',
			'Název',
		], \array_values($columns)));

		$mutations = $this->connection->getAvailableMutations();

		foreach ($photos as $photo) {
			$row = [
				$photo->getPK(),
				$photo->product->getFullCode(),
				$photo->fileName,
			];

			foreach (\array_keys($columns) as $key) {
				$columnMutation = null;

				foreach ($mutations as $mutation => $suffix) {
					if (\str_ends_with($key, $suffix)) {
						$columnMutation = $mutation;
						$key = Strings::substring($key, 0, Strings::indexOf($key, $suffix));

						break;
					}
				}

				$row[] = $photo->getValue($key, $columnMutation);
			}

			$writer->insertOne($row);
		}
	}
}
