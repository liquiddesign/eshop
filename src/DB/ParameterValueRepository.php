<?php

declare(strict_types=1);

namespace Eshop\DB;

use IGeneralRepository;
use StORM\Collection;

/**
 * @extends \StORM\Repository<\Eshop\DB\ParameterGroup>
 */
class ParameterValueRepository extends \StORM\Repository implements IGeneralRepository
{
	public function getArrayForSelect(bool $includeHidden = true): array
	{
		$suffix = $this->getConnection()->getMutationSuffix();

		return $this->getCollection($includeHidden)->toArrayOf("content$suffix");
	}

	public function getCollection(bool $includeHidden = false): Collection
	{
		$suffix = $this->getConnection()->getMutationSuffix();
		$collection = $this->many();

		return $collection->orderBy(['priority', "content$suffix"]);
	}
}
