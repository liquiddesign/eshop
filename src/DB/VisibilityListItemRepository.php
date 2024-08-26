<?php

declare(strict_types=1);

namespace Eshop\DB;

use Common\DB\IGeneralRepository;
use Eshop\ShopperUser;
use Nette\Utils\Arrays;
use StORM\Collection;
use StORM\DIConnection;
use StORM\ICollection;
use StORM\SchemaManager;

/**
 * @extends \StORM\Repository<\Eshop\DB\VisibilityListItem>
 */
class VisibilityListItemRepository extends \StORM\Repository implements IGeneralRepository
{
	public function __construct(DIConnection $connection, SchemaManager $schemaManager, protected readonly ShopperUser $shopperUser)
	{
		parent::__construct($connection, $schemaManager);
	}

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

			$this->joinPrimaryCategoryToProductCollection($collection);

			$collection->where('productPrimaryCategory.fk_category = :category OR this.fk_product IN (' . $subSelect->getSql() . ')', ['category' => $id] + $subSelect->getVars());
		}
	}

	/**
	 * @param \StORM\ICollection<\Eshop\DB\Product> $collection
	 * @param \Eshop\DB\CategoryType|false|null $categoryType Filter by CategoryType, null - load category type, false - no filter
	 */
	public function joinPrimaryCategoryToProductCollection(ICollection $collection, CategoryType|null|false $categoryType = null): void
	{
		/** @var array<array<mixed>> $joins */
		$joins = $collection->getModifiers()['JOIN'];

		$joined1 = false;

		foreach ($joins as $join) {
			if (Arrays::contains(\array_keys($join[1]), 'productPrimaryCategory')) {
				$joined1 = true;

				break;
			}
		}

		$joined2 = false;

		foreach ($joins as $join) {
			if (Arrays::contains(\array_keys($join[1]), 'primaryCategory')) {
				$joined2 = true;

				break;
			}
		}

		if ($joined1 && $joined2) {
			return;
		}

		if ($categoryType === false) {
			return;
		}

		if ($categoryType === null) {
			$categoryType = $this->shopperUser->getMainCategoryType();
		}

		if (!$joined1) {
			$collection->join(
				['productPrimaryCategory' => '(SELECT * FROM eshop_productprimarycategory)'],
				'this.uuid=productPrimaryCategory.fk_product AND productPrimaryCategory.fk_categoryType = :productPrimaryCategory_shopCategoryType',
				['productPrimaryCategory_shopCategoryType' => $categoryType],
			);
		}

		if ($joined2) {
			return;
		}

		$collection->join(['primaryCategory' => 'eshop_category'], 'productPrimaryCategory.fk_category=primaryCategory.uuid');
	}

	public function filterProducer($value, ICollection $collection): void
	{
		$value === false ? $collection->where('product.fk_producer IS NULL') : $collection->where('product.fk_producer', $value);
	}

	public function filterRibbon($value, ICollection $collection): void
	{
		$collection->join(['ribbons' => 'eshop_product_nxn_eshop_ribbon'], 'ribbons.fk_product=this.fk_product');

		$value === false ? $collection->where('ribbons.fk_ribbon IS NULL') : $collection->where('ribbons.fk_ribbon', $value);
	}

	public function filterInternalRibbon($value, ICollection $collection): void
	{
		$collection->join(['internalRibbons' => 'eshop_product_nxn_eshop_internalribbon'], 'internalRibbons.fk_product=this.fk_product');

		$value === false ? $collection->where('internalRibbons.fk_internalribbon IS NULL') : $collection->where('internalRibbons.fk_internalribbon', $value);
	}
}
