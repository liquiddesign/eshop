<?php

namespace Eshop\DB;

/**
 * @extends \StORM\Repository<\Eshop\DB\CustomerRegion>
 */
class CustomerRegionRepository extends \StORM\Repository
{
	/**
	 * @return array<string|int, string>
	 */
	public function getArrayForSelect(): array
	{
		return $this->many()->orderBy(['priority', 'name'])->toArrayOf('name');
	}
}
