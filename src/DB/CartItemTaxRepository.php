<?php

declare(strict_types=1);

namespace Eshop\DB;

use StORM\Collection;

/**
 * @extends \StORM\Repository<\Eshop\DB\CartItemTax>
 */
class CartItemTaxRepository extends \StORM\Repository implements IGeneralRepository
{
	public function getArrayForSelect(bool $includeHidden = true): array
	{
		return $this->getCollection($includeHidden)->toArrayOf('name');
	}

	public function getCollection(bool $includeHidden = false): Collection
	{
		$suffix = $this->getConnection()->getMutationSuffix();
		$collection = $this->many();

		return $collection->orderBy(["name$suffix"]);
	}
}
