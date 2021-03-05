<?php

declare(strict_types=1);

namespace Eshop\DB;


use StORM\Collection;
use StORM\Repository;

/**
 * @extends \StORM\Repository<\Eshop\DB\Producer>
 */
class ProducerRepository extends Repository implements IGeneralRepository
{
	public function getArrayForSelect(bool $includeHidden = true): array
	{
		return $this->getCollection($includeHidden)->setOrderBy(['name'])->toArrayOf('name');
	}
	
	public function getCollection(bool $includeHidden = false): Collection
	{
		$suffix = $this->getConnection()->getMutationSuffix();
		$collection = $this->many();
		
		if (!$includeHidden) {
			$collection->where('hidden', false);
		}
		
		return $collection->orderBy(['priority', "name$suffix"]);
	}
	
	/**
	 * @return \StORM\Collection|\Eshop\DB\Producer[]
	 */
	public function getProducers(): Collection
	{
		return $this->many()->where('hidden', false);
	}
}
