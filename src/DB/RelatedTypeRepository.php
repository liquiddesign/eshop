<?php

declare(strict_types=1);

namespace Eshop\DB;

use Common\DB\IGeneralRepository;
use StORM\Collection;

/**
 * @extends \StORM\Repository<\Eshop\DB\RelatedType>
 */
class RelatedTypeRepository extends \StORM\Repository implements IGeneralRepository
{
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

		return $collection->orderBy(["name$suffix"]);
	}

	public function getCartTypes(bool $includeHidden = false): Collection
	{
		return $this->getCollection($includeHidden)->where('type.showCart', true);
	}

	public function getDetailTypes(bool $includeHidden = false): Collection
	{
		return $this->getCollection($includeHidden)->where('type.showDetail', true)->where('type.showAsSet', false);
	}

	public function getSearchTypes(bool $includeHidden = false): Collection
	{
		return $this->getCollection($includeHidden)->where('type.showSearch', true);
	}

	public function getSetTypes(bool $includeHidden = false): Collection
	{
		return $this->getDetailTypes($includeHidden)->where('type.showAsSet', true);
	}
}
