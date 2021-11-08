<?php

declare(strict_types=1);

namespace Eshop\DB;

use Common\DB\IGeneralRepository;
use StORM\Collection;

/**
 * @extends \StORM\Repository<\Eshop\DB\ParameterAvailableValue>
 * @deprecated
 */
class ParameterAvailableValueRepository extends \StORM\Repository implements IGeneralRepository
{
	/**
	 * @inheritDoc
	 */
	public function getArrayForSelect(bool $includeHidden = true): array
	{
		return $this->getCollection($includeHidden)->toArrayOf('value');
	}

	public function getCollection(bool $includeHidden = false): Collection
	{
		unset($includeHidden);

		return $this->many();
	}
}
