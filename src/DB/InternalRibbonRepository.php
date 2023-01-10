<?php

declare(strict_types=1);

namespace Eshop\DB;

use Common\DB\IGeneralRepository;
use StORM\Collection;

/**
 * @extends \StORM\Repository<\Eshop\DB\InternalRibbon>
 */
class InternalRibbonRepository extends \StORM\Repository implements IGeneralRepository
{
	/**
	 * @inheritDoc
	 */
	public function getArrayForSelect(bool $includeHidden = true, ?string $type = null): array
	{
		$collection = $this->getCollection($includeHidden);

		if ($type) {
			$collection->where('this.type', $type);
		}

		return $collection->toArrayOf('name');
	}
	
	public function getCollection(bool $includeHidden = false): Collection
	{
		unset($includeHidden);

		return $this->many()->orderBy(['name']);
	}
}
