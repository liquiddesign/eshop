<?php

declare(strict_types=1);

namespace Eshop\DB;

use Common\DB\IGeneralRepository;
use Nette\Utils\Arrays;
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

			$query .= "categories.path = \"$category->path\" OR ";
		}

		return $this->getCollection($includeHidden)
			->join(['nxn' => 'eshop_attribute_nxn_eshop_category'], 'this.uuid = nxn.fk_attribute')
			->join(['category' => 'eshop_category'], 'category.uuid = nxn.fk_category')
			->where(\strlen($query) > 0 ? \substr($query, 0, -3) : '1=0');
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

	public function getCounts(Collection $collection, array $categories, array $selectedValues = []): array
	{
		if (\count($categories) == 0) {
			return [];
		}

		foreach ($selectedValues as $attributeKey => $attributeValues) {
			$query = '';

			/** @var Attribute $attribute */
			$attribute = $this->one($attributeKey);

			if (\count($attributeValues) == 0) {
				continue;
			}

			$subSelect = $this->getConnection()->rows(['eshop_attributevalue'])
				->join(['eshop_attributeassign'], 'eshop_attributeassign.fk_value = eshop_attributevalue.uuid')
				->join(['eshop_attribute'], 'eshop_attribute.uuid = eshop_attributevalue.fk_attribute')
				->where('eshop_attributeassign.fk_product=this.uuid');

			$query .= "(eshop_attributevalue.fk_attribute = \"$attributeKey\" AND (";

			foreach ($attributeValues as $attributeValue) {
				$query .= "eshop_attributevalue.uuid = \"$attributeValue\" $attribute->filterType ";
			}

			$query = \substr($query, 0, $attribute->filterType == 'and' ? -4 : -3) . '))';

			$subSelect->where($query);

			$collection->where('EXISTS (' . $subSelect->getSql() . ')');
		}

		$collection->join(['eshop_product_nxn_eshop_category'], 'eshop_product_nxn_eshop_category.fk_product=this.uuid');
		$collection->join(['categories' => 'eshop_category'], 'categories.uuid=eshop_product_nxn_eshop_category.fk_category');
		$collection->where('categories.path LIKE :pLike', ['pLike' => Arrays::last($categories)->path . '%']);

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
