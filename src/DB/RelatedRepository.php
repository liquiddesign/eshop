<?php

declare(strict_types=1);

namespace Eshop\DB;

use Base\ShopsConfig;
use Common\DB\IGeneralRepository;
use Common\NumbersHelper;
use League\Csv\Reader;
use League\Csv\Writer;
use Nette\Utils\Strings;
use Onnov\DetectEncoding\EncodingDetector;
use StORM\Collection;
use StORM\DIConnection;
use StORM\SchemaManager;
use Tracy\Debugger;

/**
 * @extends \StORM\Repository<\Eshop\DB\Related>
 */
class RelatedRepository extends \StORM\Repository implements IGeneralRepository
{
	private RelatedTypeRepository $relatedTypeRepository;

	public function __construct(
		DIConnection $connection,
		SchemaManager $schemaManager,
		RelatedTypeRepository $relatedTypeRepository,
		protected readonly ShopsConfig $shopsConfig,
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

	/**
	 * @param bool $includeHidden
	 * @param list<string>|string|null $shops
	 * @return \StORM\Collection<\Eshop\DB\Related>
	 */
	public function getCollection(bool $includeHidden = false, array|string|null $shops = null): Collection
	{
		$collection = $this->many();

		if (!$includeHidden) {
			$collection->where('this.hidden', false);
		}

		if ($shops === null) {
			$shop = $this->shopsConfig->getSelectedShop();

			if ($shop) {
				$collection->where('this.shops LIKE :shops OR this.shops IS NULL', ['shops' => '%' . $shop->getPK() . '%']);
			}
		} elseif (\is_array($shops)) {
			$collection->where('this.shops LIKE :shops OR this.shops IS NULL', ['shops' => '%' . \implode(',', $shops) . '%']);
		} else {
			$collection->where('this.shops LIKE :shops OR this.shops IS NULL', ['shops' => '%' . $shops . '%']);
		}

		return $collection->orderBy(['this.priority']);
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
			'shops',
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
				$related->shops,
			]);
		}
	}

	/**
	 * @param string $content
	 * @return array{importedCount: int, notFoundRelationTypes: array<string>, notFoundProducts: array<string>}
	 * @throws \StORM\Exception\NotFoundException
	 */
	public function importCsv(string $content): array
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
			'shops',
		]);

		$relatedTypesByCode = $this->relatedTypeRepository->many()->setIndex('code')->toArray();

		$productsByCode = $this->getConnection()->findRepository(Product::class)->many()
			->setSelect(['this.uuid'])
			->setIndex('this.code')
			->toArrayOf('uuid');

		$productsByFullCode = $this->getConnection()->findRepository(Product::class)->many()
			->setSelect(['this.uuid'])
			->setIndex("CONCAT(this.code,'.',this.subCode)")
			->toArrayOf('uuid');

		$productsByEan = $this->getConnection()->findRepository(Product::class)->many()
			->setSelect(['this.uuid'])
			->setIndex('this.ean')
			->toArrayOf('uuid');

		$availableShops = \array_keys($this->shopsConfig->getAvailableShops());

		$notFoundRelationTypes = [];
		$notFoundProducts = [];

		$imported = 0;

		foreach ($iterator as $value) {
			$relatedType = $relatedTypesByCode[$value['type']] ?? null;

			if (!$relatedType) {
				$notFoundRelationTypes[] = $value['type'];

				continue;
			}

			$master = Strings::trim($value['master']);
			$masterPK = $productsByEan[$master] ?? $productsByFullCode[$master] ?? $productsByCode[$master] ?? null;

			if (!$masterPK) {
				$notFoundProducts[] = $master;

				continue;
			}

			$slave = Strings::trim($value['slave']);
			$slavePK = $productsByEan[$slave] ?? $productsByFullCode[$slave] ?? $productsByCode[$slave] ?? null;

			if (!$slavePK) {
				$notFoundProducts[] = $slave;

				continue;
			}

			$shopsArray = \explode(',', Strings::lower(Strings::trim($value['shops'])));
			\sort($shopsArray);
			$rowShops = \array_intersect($shopsArray, $availableShops);

			$data = [
				'uuid' => DIConnection::generateUuid('relation', "{$relatedType->getPK()}$masterPK$slavePK"),
				'type' => $relatedType->getPK(),
				'master' => $masterPK,
				'slave' => $slavePK,
				'amount' => (int) ($value['amount'] ?: 1),
				'discountPct' => isset($value['discountPct']) ? NumbersHelper::strToFloat($value['discountPct']) : null,
				'masterPct' => isset($value['masterPct']) ? NumbersHelper::strToFloat($value['masterPct']) : null,
				'priority' => (int) $value['priority'],
				'hidden' => (bool) $value['hidden'],
				'systemic' => false,
				'shops' => $rowShops ? \implode(',', $rowShops) : null,
			];

			try {
				$this->syncOne($data, ignore: false);
			} catch (\Exception $e) {
				Debugger::barDump($value);
				Debugger::barDump($data);

				throw $e;
			}

			$imported ++;
		}

		return [
			'importedCount' => $imported,
			'notFoundRelationTypes' => $notFoundRelationTypes,
			'notFoundProducts' => $notFoundProducts,
		];
	}

	/** @todo použít univerzální funkci z backend */
	private function getReaderFromString(string $content): Reader
	{
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
