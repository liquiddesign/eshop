<?php

declare(strict_types=1);

namespace Eshop\DB;

/**
 * @extends \StORM\Repository<\Eshop\DB\SupplierCategory>
 */
class SupplierCategoryRepository extends \StORM\Repository
{
	public function getArrayForSelect(?bool $mapped = null): array
	{
		$collection = $this->many();
		
		if ($mapped === true) {
			$collection->where('fk_category IS NOT NULL');
		}
		
		if ($mapped === false) {
			$collection->where('fk_category IS NULL');
		}
		
		return $collection->orderBy(['categoryNameL1', 'categoryNameL2', 'categoryNameL3', 'categoryNameL4'])->toArrayOf('%s', [function ($category) {
			return $category->supplier->name . ' - ' . $category->getNameTree();
		}]);
	}
}
