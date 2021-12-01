<?php

declare(strict_types=1);

namespace Eshop\DB;

use Common\DB\IGeneralRepository;
use StORM\Collection;

/**
 * @extends \StORM\Repository<\Eshop\DB\DisplayDelivery>
 */
class DisplayDeliveryRepository extends \StORM\Repository implements IGeneralRepository
{
	/**
	 * @return string[]
	 */
	public function getArrayForSelect(bool $includeHidden = true): array
	{
		return $this->getCollection($includeHidden)->toArrayOf('label');
	}

	public function getCollection(bool $includeHidden = false): Collection
	{
		unset($includeHidden);

		$mutationSuffix = $this->getConnection()->getMutationSuffix();
		$collection = $this->many();

		return $collection->orderBy(['this.priority', "this.label$mutationSuffix",]);
	}
}
