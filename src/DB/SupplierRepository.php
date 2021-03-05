<?php

declare(strict_types=1);

namespace Eshop\DB;


use StORM\Collection;

/**
 * @extends \StORM\Repository<\Eshop\DB\Supplier>
 */
class SupplierRepository extends \StORM\Repository implements IGeneralRepository
{
	public function getArrayForSelect(bool $includeHidden = true): array
	{
		return $this->many()->orderBy(["name"])->toArrayOf('name');
	}
	
	public function getCollection(bool $includeHidden = false): Collection
	{
		$collection = $this->many();
		
		if (!$includeHidden) {
			$collection->where('hidden', false);
		}
		
		return $collection->orderBy(['priority', "name"]);
	}
	
}
