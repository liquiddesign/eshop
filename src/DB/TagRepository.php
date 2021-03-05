<?php

declare(strict_types=1);

namespace Eshop\DB;

use IGeneralRepository;
use StORM\Collection;

/**
 * @extends \StORM\Repository<\Eshop\DB\Tag>
 */
class TagRepository extends \StORM\Repository implements IGeneralRepository
{
	public function getListForSelect():array
	{
		$data = $this->many()->toArray();
		$array = [];
		foreach ($data as $key => $value)
		{
			$array[$key] = $value->name;
		}

		return $array;
	}
	
	public function getArrayForSelect(bool $includeHidden = true): array
	{
		return $this->getCollection($includeHidden)->toArrayOf('name');
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
}
