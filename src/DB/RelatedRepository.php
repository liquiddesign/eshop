<?php

declare(strict_types=1);

namespace Eshop\DB;

use Common\DB\IGeneralRepository;
use Common\NumbersHelper;
use League\Csv\Reader;
use League\Csv\Writer;
use Nette\Utils\FileSystem;
use Nette\Utils\Random;
use Onnov\DetectEncoding\EncodingDetector;
use StORM\Collection;
use StORM\DIConnection;
use StORM\ICollection;
use StORM\SchemaManager;

/**
 * @extends \StORM\Repository<\Eshop\DB\Related>
 */
class RelatedRepository extends \StORM\Repository implements IGeneralRepository
{
	private RelatedTypeRepository $relatedTypeRepository;

	public function __construct(
		DIConnection $connection,
		SchemaManager $schemaManager,
		RelatedTypeRepository $relatedTypeRepository
	) {
		parent::__construct($connection, $schemaManager);

		$this->relatedTypeRepository = $relatedTypeRepository;
	}

	/**
	 * @inheritDoc
	 */
	public function getArrayForSelect(bool $includeHidden = true): array
	{
		return $this->getCollection($includeHidden)->toArrayOf('CONCAT(master.name,"-",slave.name)');
	}

	public function getCollection(bool $includeHidden = false): Collection
	{
		$collection = $this->many();

		if (!$includeHidden) {
			$collection->where('hidden', false);
		}

		return $collection->orderBy(['priority']);
	}

	/**
	 * @param \League\Csv\Writer $writer
	 * @param \Eshop\DB\Related[] $items
	 * @throws \League\Csv\CannotInsertRecord
	 * @throws \League\Csv\InvalidArgument
	 */
	public function exportCsv(Writer $writer, array $items): void
	{
		$writer->setDelimiter(';');

		$writer->insertOne([
			'type',
			'master',
			'slave',
			'amount',
			'discountPct',
			'masterPct',
			'priority',
			'hidden',
		]);

		foreach ($items as $related) {
			$writer->insertOne([
				$related->type->code,
				$related->master->getFullCode(),
				$related->slave->getFullCode(),
				$related->amount,
				$related->discountPct,
				$related->masterPct,
				$related->priority,
				$related->hidden ? '1' : '0',
			]);
		}
	}

	public function importCsv(string $content): void
	{
		$reader = $this->getReaderFromString($content);

		$iterator = $reader->getRecords([
			'type',
			'master',
			'slave',
			'amount',
			'discountPct',
			'masterPct',
			'priority',
			'hidden',
		]);

		foreach ($iterator as $value) {
			$relatedType = $this->relatedTypeRepository->many()->where('code', $value['type'])->first();

			if (!$relatedType) {
				continue;
			}

			$fullCode = \explode('.', $value['master']);
			$products = $this->getConnection()->findRepository(Product::class)->many()->where('this.code', $fullCode[0]);

			if (isset($fullCode[1])) {
				$products->where('this.subcode', $fullCode[1]);
			}

			if (!$master = $products->first()) {
				continue;
			}

			$fullCode = \explode('.', $value['slave']);
			$products = $this->getConnection()->findRepository(Product::class)->many()->where('this.code', $fullCode[0]);

			if (isset($fullCode[1])) {
				$products->where('this.subcode', $fullCode[1]);
			}

			if (!$slave = $products->first()) {
				continue;
			}

			$this->syncOne([
				'type' => $relatedType->getPK(),
				'master' => $master->getPK(),
				'slave' => $slave->getPK(),
				'amount' => (int) $value['amount'],
				'discountPct' => NumbersHelper::strToFloat($value['discountPct']),
				'masterPct' => NumbersHelper::strToFloat($value['masterPct']),
				'priority' => (int) $value['priority'],
				'hidden' => (bool) $value['hidden'],
			]);
		}
	}

	/** @TODO přesunout pro jednotné použití všude */
	private function getReaderFromString(string $content): Reader
	{
		if (!\ini_get("auto_detect_line_endings")) {
			\ini_set("auto_detect_line_endings", '1');
		}

		$detector = new EncodingDetector();

		$detector->disableEncoding([
			EncodingDetector::ISO_8859_5,
			EncodingDetector::KOI8_R,
		]);

		$encoding = $detector->getEncoding($content);

		if ($encoding !== 'utf-8') {
			$content = \iconv('windows-1250', 'utf-8', $content);
		}

		$reader = Reader::createFromString($content);
		unset($content);

		$reader->setDelimiter(';');
		$reader->setHeaderOffset(0);

		return $reader;
	}

	/** @TODO přesunout pro jednotné použití všude */
	/** @codingStandardsIgnoreStart  */
	private function getReader(string $filePath): Reader
	{
		if (!\ini_get("auto_detect_line_endings")) {
			\ini_set("auto_detect_line_endings", '1');
		}

		$csvData = FileSystem::read($filePath);

		$detector = new EncodingDetector();

		$detector->disableEncoding([
			EncodingDetector::ISO_8859_5,
			EncodingDetector::KOI8_R,
		]);

		$encoding = $detector->getEncoding($csvData);

		if ($encoding !== 'utf-8') {
			$csvData = \iconv('windows-1250', 'utf-8', $csvData);
			$reader = Reader::createFromString($csvData);
			unset($csvData);
		} else {
			unset($csvData);
			$reader = Reader::createFromPath($filePath);
		}

		$reader->setDelimiter(';');
		$reader->setHeaderOffset(0);

		return $reader;
	}

	/** @codingStandardsIgnoreEnd  */
}
