<?php

declare(strict_types=1);

namespace Eshop\DB;

use Common\DB\IGeneralRepository;
use StORM\Collection;

/**
 * @extends \StORM\Repository<\Eshop\DB\Parameter>
 * @deprecated
 */
class ParameterRepository extends \StORM\Repository implements IGeneralRepository
{
	/**
	 * @return string[]
	 * @deprecated
	 */
	public function getListForSelect(): array
	{
		$data = $this->many()->toArray();
		$array = [];

		foreach ($data as $key => $value) {
			$array[$key] = $value->name ?: $value->getPK();
		}

		return $array;
	}

	/**
	 * @inheritDoc
	 */
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

	/**
	 * @param \StORM\Collection<\Eshop\DB\Product> $collection
	 * @return string[]
	 * @deprecated
	 */
	public function getCounts(Collection $collection): array
	{
		return $this->many()
			->join(['parameteravailablevalue' => 'eshop_parameteravailablevalue'], 'parameteravailablevalue.fk_parameter = this.uuid')
			->join(['parametervalue' => 'eshop_parametervalue'], 'parametervalue.fk_value = parameteravailablevalue.uuid')
			->join(['product' => $collection], 'product.uuid=parametervalue.fk_product', $collection->getVars())
			->setSelect(['count' => 'COUNT(product.uuid)'])
			->setIndex('this.uuid')
			->setGroupBy(['this.uuid'])
			->fetchColumns('count');
	}
}
