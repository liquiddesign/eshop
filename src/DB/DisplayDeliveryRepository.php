<?php

declare(strict_types=1);

namespace Eshop\DB;

use Common\DB\IGeneralRepository;
use StORM\Collection;
use StORM\DIConnection;
use StORM\SchemaManager;

/**
 * @extends \StORM\Repository<\Eshop\DB\DisplayDelivery>
 */
class DisplayDeliveryRepository extends \StORM\Repository implements IGeneralRepository
{
	private ProductRepository $productRepository;
	
	public function __construct(DIConnection $connection, SchemaManager $schemaManager, ProductRepository $productRepository)
	{
		parent::__construct($connection, $schemaManager);
		
		$this->productRepository = $productRepository;
	}
	
	/**
	 * @return string[]
	 */
	public function getArrayForSelect(bool $includeHidden = true): array
	{
		return $this->getCollection($includeHidden)->toArrayOf('label');
	}
	
	public function getCollection(bool $includeHidden = false): Collection
	{
		unset($includeHidden);
		
		$mutationSuffix = $this->getConnection()->getMutationSuffix();
		$collection = $this->many();
		
		return $collection->orderBy(['this.priority', "this.label$mutationSuffix",]);
	}
	
	/**
	 * @param array<string, mixed> $filters
	 * @return array<string, string>
	 */
	public function getCounts(array $filters): array
	{
		return $this->productRepository->getCountGroupedBy('this.fk_displayDelivery', $filters);
	}
}
