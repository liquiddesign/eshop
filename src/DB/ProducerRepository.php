<?php

declare(strict_types=1);

namespace Eshop\DB;

use Common\DB\IGeneralRepository;
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
	
	public function __construct(DIConnection $connection, SchemaManager $schemaManager, ProductRepository $productRepository)
	{
		parent::__construct($connection, $schemaManager);
		
		$this->productRepository = $productRepository;
	}
	
	/**
	 * @inheritDoc
	 */
	public function getArrayForSelect(bool $includeHidden = true): array
	{
		$suffix = $this->getConnection()->getMutationSuffix();
		
		return $this->getCollection($includeHidden)->setOrderBy(["this.name$suffix"])->toArrayOf('name');
	}
	
	public function getCollection(bool $includeHidden = false): Collection
	{
		$suffix = $this->getConnection()->getMutationSuffix();
		$collection = $this->many();
		
		if (!$includeHidden) {
			$collection->where('this.hidden', false);
		}
		
		return $collection->orderBy(['this.priority', "this.name$suffix"]);
	}
	
	public function getProducers(): Collection
	{
		return $this->many()->where('this.hidden', false);
	}
	
	/**
	 * @param array<string, \Eshop\DB\Pricelist> $pricelists
	 * @param array<string, mixed> $filters
	 * @return array<string, string>
	 */
	public function getCounts(array $pricelists, array $filters): array
	{
		return $this->productRepository->getCountGroupedBy('this.fk_producer', $pricelists, $filters);
	}
}
