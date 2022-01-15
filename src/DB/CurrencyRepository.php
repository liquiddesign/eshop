<?php

declare(strict_types=1);

namespace Eshop\DB;

use Common\DB\IGeneralRepository;
use StORM\Collection;
use StORM\ICollection;

/**
 * @extends \StORM\Repository<\Eshop\DB\Currency>
 */
class CurrencyRepository extends \StORM\Repository implements IGeneralRepository
{
	/**
	 * @inheritDoc
	 */
	public function getArrayForSelect(bool $includeHidden = true): array
	{
		return $this->getCollection($includeHidden)->toArrayOf('code');
	}

	/**
	 * @param \StORM\ICollection $collection
	 * @return array<string, string>
	 */
	public function getArrayForSelectFromCollection(ICollection $collection): array
	{
		return $collection->toArrayOf('code');
	}

	public function getCollection(bool $includeHidden = false): Collection
	{
		unset($includeHidden);

		return $this->many()->orderBy(['code', 'symbol']);
	}
}
