<?php

declare(strict_types=1);

namespace Eshop\DB;

/**
 * @extends \StORM\Repository<\Eshop\DB\SupplierCategory>
 */
class SupplierCategoryRepository extends \StORM\Repository
{
	public function syncAttributeCategories(string $supplierId): void
	{
		$this->getConnection()->rows(['this' => 'eshop_category_nxn_eshop_attributecategory'])
			->join(['attributeCategory' => 'eshop_attributecategory'], 'this.fk_attributeCategory=attributeCategory.uuid')
			->where('attributeCategory.fk_supplier', $supplierId)
			->delete();
		
		foreach ($this->many()->where('this.fk_supplier', $supplierId)->where('fk_category IS NOT NULL AND fk_attributeCategory IS NOT NULL') as $supplierCategory) {
			
			$this->connection->syncRow('eshop_category_nxn_eshop_attributecategory', [
				'fk_category' => $supplierCategory->getValue('category'),
				'fk_attributeCategory' => $supplierCategory->getValue('attributeCategory'),
			]);
		}
		
		return;
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
