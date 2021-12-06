<?php

declare(strict_types=1);

namespace Eshop\DB;

use Common\DB\IGeneralRepository;
use Common\NumbersHelper;
use League\Csv\Reader;
use League\Csv\Writer;
use Onnov\DetectEncoding\EncodingDetector;
use StORM\Collection;
use StORM\DIConnection;
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
	 * @param \StORM\Collection $items
	 * @throws \League\Csv\CannotInsertRecord
	 * @throws \League\Csv\InvalidArgument
	 */
	public function exportCsv(Writer $writer, Collection $items): void
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

		/** @var \Eshop\DB\Related $related */
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
			$products = $this->getConnection()->findRepository(Product::class)->many()->where('this.code = :product OR this.ean = :product', ['product' => $fullCode[0]]);

			if (isset($fullCode[1])) {
				$products->where('this.subcode', $fullCode[1]);
			}

			if (!$master = $products->first()) {
				continue;
			}

			$fullCode = \explode('.', $value['slave']);
			$products = $this->getConnection()->findRepository(Product::class)->many()->where('this.code = :product OR this.ean = :product', ['product' => $fullCode[0]]);

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
				'discountPct' => isset($value['discountPct']) ? NumbersHelper::strToFloat($value['discountPct']) : null,
				'masterPct' => isset($value['masterPct']) ? NumbersHelper::strToFloat($value['masterPct']) : null,
				'priority' => (int) $value['priority'],
				'hidden' => (bool) $value['hidden'],
				'systemic' => false,
			]);
		}
	}

	/** @todo použít univerzální funkci z backend */
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
}
