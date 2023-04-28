<?php

declare(strict_types=1);

namespace Eshop\DB;

use Common\DB\IGeneralRepository;
use StORM\Collection;
use StORM\ICollection;

/**
 * @extends \StORM\Repository<\Eshop\DB\VisibilityListItem>
 */
class VisibilityListItemRepository extends \StORM\Repository implements IGeneralRepository
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
		unset($includeHidden);

		$collection = $this->many();

		return $collection->orderBy(['priority', 'name']);
	}

	public function filterCategory($path, ICollection $collection): void
	{
		if ($path === false) {
			$collection->where('categories.uuid IS NULL');

			return;
		}

		$id = $this->getConnection()->findRepository(Category::class)->many()->where('path', $path)->firstValue('uuid');

		if (!$id) {
			$collection->where('1=0');
		} else {
			$subSelect = $this->getConnection()->rows(['eshop_product_nxn_eshop_category'], ['fk_product'])
				->join(['eshop_category'], 'eshop_category.uuid=eshop_product_nxn_eshop_category.fk_category')
				->where('eshop_category.path LIKE :path', ['path' => "$path%"]);
			$collection->where('product.fk_primaryCategory = :category OR this.uuid IN (' . $subSelect->getSql() . ')', ['category' => $id] + $subSelect->getVars());
		}
	}
}
