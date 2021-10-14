<?php

declare(strict_types=1);

namespace Eshop\DB;

use Common\DB\IGeneralRepository;
use StORM\Collection;
use StORM\Repository;

/**
 * @extends \StORM\Repository<\Eshop\DB\Producer>
 */
class ProducerRepository extends Repository implements IGeneralRepository
{
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
	
	/**
	 * @return \StORM\Collection|\Eshop\DB\Producer[]
	 */
	public function getProducers(): Collection
	{
		return $this->many()->where('this.hidden', false);
	}
}
