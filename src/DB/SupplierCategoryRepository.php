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
			/** @var \Eshop\DB\SupplierAttribute $supplierAttribute */
			foreach ($this->getConnection()->findRepository(SupplierAttribute::class)->many()
				->join(['assign' => 'eshop_supplierattributecategoryassign'], 'assign.fk_supplierAttribute=this.uuid')
				->where('assign.fk_supplierCategory', $supplierCategory)
				->where('this.fk_attribute IS NOT NULL')
				->where('this.active', true) as $supplierAttribute) {
				$supplierAttribute->update(['active' => false]);

				$this->connection->syncRow('eshop_attribute_nxn_eshop_category', [
					'fk_category' => $supplierCategory->getValue('category'),
					'fk_attribute' => $supplierAttribute->getValue('attribute'),
				]);
			}
		}
	}

	/**
	 * @param bool|null $mapped
	 * @return array<string>
	 */
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
