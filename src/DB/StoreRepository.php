<?php

declare(strict_types=1);

namespace Eshop\DB;

use Common\DB\IGeneralRepository;
use StORM\Collection;

/**
 * @extends \StORM\Repository<\Eshop\DB\Store>
 */
class StoreRepository extends \StORM\Repository implements IGeneralRepository
{
	/**
	 * @inheritDoc
	 */
	public function getArrayForSelect(bool $includeHidden = true): array
	{
		$mutationSuffix = $this->getConnection()->getMutationSuffix();

		return $this->getCollection($includeHidden)
			->select(['fullName' => "IF(this.systemicLock > 0, CONCAT(name$mutationSuffix, ' (', code, ', systémový)'), CONCAT(name$mutationSuffix, ' (', code, ')'))"])
			->toArrayOf('fullName');
	}

	public function getCollection(bool $includeHidden = false): Collection
	{
		unset($includeHidden);

		$suffix = $this->getConnection()->getMutationSuffix();
		$collection = $this->many();

		return $collection->orderBy(['code', "name$suffix"]);
	}
}
