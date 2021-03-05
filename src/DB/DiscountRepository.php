<?php

declare(strict_types=1);

namespace Eshop\DB;

use IGeneralRepository;
use StORM\Collection;

/**
 * @extends \StORM\Repository<\Eshop\DB\Discount>
 */
class DiscountRepository extends \StORM\Repository implements IGeneralRepository
{
	public function getArrayForSelect(bool $includeHidden = true): array
	{
		return $this->many()->orderBy(['name'])->toArrayOf('name');
	}
	
	public function isTagAssignedToDiscount(Discount $discount, Tag $tag): bool
	{
		return $this->getConnection()->rows(['eshop_discount_nxn_eshop_tag'])
				->where('fk_discount', $discount->getPK())
				->where('fk_tag', $tag->getPK())
				->count() == 1;
	}
	
	public function getCollection(bool $includeHidden = false): Collection
	{
		return $this->many()->orderBy(['validTo', 'validFrom', 'name']);
	}
	
	public function getActiveDiscounts(): Collection
	{
		return $this->many()
			->where('IF (validFrom IS NOT NULL AND validTo IS NOT NULL,
			validFrom <= NOW() AND NOW() <= validTo,
			IF(validFrom IS NOT NULL OR validTo IS NOT NULL,
			(validFrom IS NOT NULL AND validFrom <= NOW()) OR (validTo IS NOT NULL AND NOW() <= validTo),
			TRUE)
			)')->orderBy(['validTo', 'validFrom']);
	}
}
