<?php

declare(strict_types=1);

namespace Eshop\DB;

use Common\DB\IGeneralRepository;
use StORM\Collection;

/**
 * @extends \StORM\Repository<\Eshop\DB\Attribute>
 */
class AttributeRepository extends \StORM\Repository implements IGeneralRepository
{
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

		return $collection->orderBy(['this.priority', "this.name$suffix",]);
	}

	public function getAttributesByCategories($categories, bool $includeHidden = false)
	{
		/** @var CategoryRepository $categoryRepository */
		$categoryRepository = $this->getConnection()->findRepository(Category::class);

		$categories = \is_array($categories) ? $categories : [$categories];

		$query = '';

		foreach ($categories as $category) {
			if (!$category instanceof Category) {
				if (!$category = $categoryRepository->one($category)) {
					continue;
				}
			}

			$query .= "categories.path LIKE \"$category->path%\" OR ";
		}

		$query = \substr($query, 0, -3);

		return $this->getCollection($includeHidden)
			->join(['nxn' => 'eshop_attribute_nxn_eshop_category'], 'this.uuid = nxn.fk_attribute')
			->join(['category' => 'eshop_category'], 'category.uuid = nxn.fk_category')
			->where($query);
	}

	public function getAttributeValues($attribute, bool $includeHidden = false): Collection
	{
		/** @var AttributeValueRepository $attributeValueRepository */
		$attributeValueRepository = $this->getConnection()->findRepository(AttributeValue::class);

		$emptyCollection = $attributeValueRepository->many()->where('1 = 0');

		if (!$attribute instanceof Attribute) {
			if (!$attribute = $this->one($attribute)) {
				return $emptyCollection;
			}
		}

		return $attributeValueRepository->getCollection($includeHidden)->where('fk_attribute', $attribute->getPK());
	}

	public function getCounts(Collection $collection): array
	{
		$collection = $this->getCollection()
			->join(['attributeValue' => 'eshop_attributevalue'], 'attributeValue.fk_attribute = this.uuid')
			->join(['attributeAssign' => 'eshop_attributeassign'], 'attributeAssign.fk_value = attributeValue.uuid')
			->join(['product' => $collection], 'product.uuid=attributeAssign.fk_product', $collection->getVars())
			->setSelect(['count' => 'COUNT(product.uuid)'])
			->setIndex('attributeValue.uuid')
			->setGroupBy(['attributeValue.uuid']);

		$collection->setFetchClass(\stdClass::class);

		return $collection->toArrayOf('count');
	}
}
