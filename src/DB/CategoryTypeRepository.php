<?php

declare(strict_types=1);

namespace Eshop\DB;

use Common\DB\IGeneralRepository;
use StORM\Collection;

/**
 * @extends \StORM\Repository<\Eshop\DB\CategoryType>
 */
class CategoryTypeRepository extends \StORM\Repository implements IGeneralRepository
{
	/**
	 * @inheritDoc
	 */
	public function getArrayForSelect(bool $includeHidden = true): array
	{
		return $this->toArrayForSelect($this->getCollection($includeHidden));
	}

	/**
	 * @param \StORM\Collection<\Eshop\DB\CategoryType> $collection
	 * @param bool $includeHidden
	 */
	public function toArrayForSelect(Collection $collection): array
	{
		return $collection->toArrayOf('name');
	}

	public function getCollection(bool $includeHidden = false): Collection
	{
		$collection = $this->many();

		if (!$includeHidden) {
			$collection->where('hidden', false);
		}

		return $collection->orderBy(['priority', 'name']);
	}
}
