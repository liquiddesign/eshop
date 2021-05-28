<?php

declare(strict_types=1);

namespace Eshop\DB;

/**
 * @extends \StORM\Repository<\Eshop\DB\SupplierCategory>
 */
class SupplierCategoryRepository extends \StORM\Repository
{
	public function syncAttributeCategoryAssigns(Supplier $supplier): void
	{
		foreach ($this->many()->where('this.fk_supplier', $supplier)->where('fk_category IS NOT NULL') as $supplierCategory) {
			foreach ($this->getConnection()->findRepository(SupplierAttributeCategoryAssign::class)->many()->where('fk_supplierCategory', $supplierCategory) as $nxn) {
				$this->connection->syncRow('eshop_attribute_nxn_eshop_category', [
					'fk_category' => $supplierCategory->getValue('category'),
					'fk_attribute' => $nxn->getValue('attribute'),
				]);
			}
		}
	}
	
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
