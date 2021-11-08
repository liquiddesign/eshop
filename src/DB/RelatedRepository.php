<?php

declare(strict_types=1);

namespace Eshop\DB;

use Common\DB\IGeneralRepository;
use League\Csv\Reader;
use League\Csv\Writer;
use StORM\Collection;
use StORM\DIConnection;
use StORM\SchemaManager;

/**
 * @extends \StORM\Repository<\Eshop\DB\Related>
 */
class RelatedRepository extends \StORM\Repository implements IGeneralRepository
{
	private RelatedTypeRepository $relatedTypeRepository;

	public function __construct(DIConnection $connection, SchemaManager $schemaManager, RelatedTypeRepository $relatedTypeRepository)
	{
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

	public function exportCsv(Writer $writer, $items = null): void
	{
		$writer->setDelimiter(';');

		$writer->insertOne([
			'type',
			'master',
			'slave',
		]);

		foreach ($items ?? $this->many() as $related) {
			$writer->insertOne([
				$related->type->code,
				$related->master->getFullCode(),
				$related->slave->getFullCode(),
			]);
		}
	}

	public function importCsv(Reader $reader): void
	{
		if (!\ini_get("auto_detect_line_endings")) {
			\ini_set("auto_detect_line_endings", '1');
		}

		$reader->setDelimiter(';');
		$reader->setHeaderOffset(0);

		$iterator = $reader->getRecords([
			'type',
			'master',
			'slave',
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
			]);
		}
	}
}
