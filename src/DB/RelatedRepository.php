<?php

declare(strict_types=1);

namespace Eshop\DB;

use Common\DB\IGeneralRepository;
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

	private ProductRepository $productRepository;

	public function __construct(
		DIConnection $connection,
		SchemaManager $schemaManager,
		RelatedTypeRepository $relatedTypeRepository,
		ProductRepository $productRepository
	) {
		parent::__construct($connection, $schemaManager);

		$this->relatedTypeRepository = $relatedTypeRepository;
		$this->productRepository = $productRepository;
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

	public function exportCsv(Writer $writer, ICollection $items): void
	{
		$writer->setDelimiter(';');

		$writer->insertOne([
			'related',
			'master/slave',
			'productCode',
			'amount',
			'discountPct',
		]);

		$relatedKeys = \array_values($items->toArrayOf('uuid'));

		foreach ($this->relatedMasterRepository->many()
					 ->join(['product' => 'eshop_product'], 'this.fk_product = product.uuid')
					 ->where('fk_related', $relatedKeys) as $master) {
			$writer->insertOne([
				$master->related->code,
				'm',
				$master->product->getFullCode(),
				$master->amount,
				'',
			]);
		}

		foreach ($this->relatedSlaveRepository->many()
					 ->join(['product' => 'eshop_product'], 'this.fk_product = product.uuid')
					 ->where('fk_related', $relatedKeys) as $slave) {
			$writer->insertOne([
				$slave->related->code,
				's',
				$slave->product->getFullCode(),
				$slave->amount,
				$slave->discountPct,
			]);
		}
	}

	public function importCsv(string $content, RelatedType $relatedType, bool $overwrite = false): void
	{
		$reader = $this->getReaderFromString($content);

		$iterator = $reader->getRecords([
			'related',
			'master/slave',
			'productCode',
			'amount',
			'discountPct',
		]);

		foreach ($iterator as $value) {
			$fullCode = \explode('.', $value['productCode']);
			$products = $this->productRepository->many()->where('this.code', $fullCode[0]);

			if (isset($fullCode[1])) {
				$products->where('this.subcode', $fullCode[1]);
			}

			if (!$product = $products->first()) {
				continue;
			}

			$related = $this->many()->where('code', $value['related'])->first();

			if ($related && $overwrite) {
				$value['master/slave'] === 'm' ?
					$this->relatedMasterRepository->many()
						->where('fk_related', $related->getPK())
						->where('fk_product', $product->getPK())
						->delete() :
					$this->relatedSlaveRepository->many()
						->where('fk_related', $related->getPK())
						->where('fk_product', $product->getPK())
						->delete();
			}

			if (($related && !$overwrite) || !$related) {
				do {
					$code = Random::generate(32);
					$related = $this->one(['code' => $code]);
				} while ($related);

				$related = $this->createOne(['type' => $relatedType, 'code' => $code]);
			}

			$value['master/slave'] === 'm' ? $this->relatedMasterRepository->syncOne([
				'related' => $related,
				'product' => $product,
				'amount' => \intval($value['amount']),
			]) : $this->relatedSlaveRepository->syncOne([
				'related' => $related,
				'product' => $product,
				'amount' => \intval($value['amount']),
				'discountPct' => \floatval(\str_replace(',', '.', $value['discountPct'])),
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
}
