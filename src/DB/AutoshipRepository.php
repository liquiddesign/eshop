<?php

declare(strict_types=1);

namespace Eshop\DB;

/**
 * @extends \StORM\Repository<\Eshop\DB\Autoship>
 */
class AutoshipRepository extends \StORM\Repository
{
	public function getListForSelect():array
	{
		$data = $this->many()->toArray();
		$array = [];
		foreach ($data as $key => $value)
		{
			$array[$key] = "$value->uuid";
		}
		return $array;
	}
}
