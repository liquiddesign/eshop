<?php

declare(strict_types=1);

namespace Eshop\DB;

use Common\DB\IGeneralRepository;
use StORM\Collection;

/**
 * @extends \StORM\Repository<\Eshop\DB\Parameter>
 */
class ParameterRepository extends \StORM\Repository implements IGeneralRepository
{
	public function getListForSelect():array
	{
		$data = $this->many()->toArray();
		$array = [];
		foreach ($data as $key => $value)
		{
			$array[$key] = $value->name ?: $value->getPK();
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
			$collection->where('this.hidden', false);
		}

		return $collection->orderBy(['priority', "name$suffix"]);
	}
	
	public function getCounts(Collection $collection): array
	{
		$collection = $this->many()
			->join(['parametervalue' => 'eshop_parametervalue'], 'parametervalue.fk_parameter=this.uuid')
			->join(['product' => $collection], 'product.uuid=parametervalue.fk_product', $collection->getVars())
			->setSelect(['count' => 'COUNT(product.uuid)'])
			->setIndex('this.uuid')
			->setGroupBy(['this.uuid']);
		
		$collection->setFetchClass(\stdClass::class);
		
		return $collection->toArrayOf('count');
	}
}
