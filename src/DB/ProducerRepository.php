<?php

declare(strict_types=1);

namespace Eshop\DB;

use Common\DB\IGeneralRepository;
use Eshop\Admin\ScriptsPresenter;
use Nette\Caching\Cache;
use Nette\Caching\Storage;
use StORM\Collection;
use StORM\DIConnection;
use StORM\Repository;
use StORM\SchemaManager;

/**
 * @extends \StORM\Repository<\Eshop\DB\Producer>
 */
class ProducerRepository extends Repository implements IGeneralRepository
{
	private ProductRepository $productRepository;

	private Storage $storage;
	
	public function __construct(DIConnection $connection, SchemaManager $schemaManager, ProductRepository $productRepository, Storage $storage)
	{
		parent::__construct($connection, $schemaManager);
		
		$this->productRepository = $productRepository;
		$this->storage = $storage;
	}
	
	/**
	 * @inheritDoc
	 */
	public function getArrayForSelect(bool $includeHidden = true): array
	{
		$suffix = $this->getConnection()->getMutationSuffix();
		
		return $this->getCollection($includeHidden)->setOrderBy(["this.name$suffix"])->toArrayOf('name');
	}

	/**
	 * @param bool $includeHidden
	 * @return \StORM\Collection<\Eshop\DB\Producer>
	 */
	public function getCollection(bool $includeHidden = false): Collection
	{
		$suffix = $this->getConnection()->getMutationSuffix();
		$collection = $this->many();
		
		if (!$includeHidden) {
			$collection->where('this.hidden', false);
		}
		
		return $collection->orderBy(['this.priority', "this.name$suffix"]);
	}

	/**
	 * @return \StORM\Collection<\Eshop\DB\Producer>
	 */
	public function getProducers(): Collection
	{
		return $this->many()->where('this.hidden', false);
	}
	
	/**
	 * @param array<string, mixed> $filters
	 * @return array<string, string>
	 */
	public function getCounts(array $filters): array
	{
		return $this->productRepository->getCountGroupedBy('this.fk_producer', $filters);
	}

	public function cleanProducersCache(): void
	{
		\bdump('cleaned');
		$cache = new Cache($this->storage);

		$cache->clean([
			Cache::Tags => [
				ScriptsPresenter::PRODUCERS_CACHE_TAG,
				ScriptsPresenter::ATTRIBUTES_CACHE_TAG,
			],
		]);
	}
}
