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
			$collection->where('hidden', false);
		}

		return $collection->orderBy(['priority', "name$suffix",]);
	}

	public function getAttributes($category): Collection
	{
		/** @var AttributeCategoryRepository $attributeCategoryRepository */
		$attributeCategoryRepository = $this->getConnection()->findRepository(AttributeCategory::class);

		$emptyCollection = $attributeCategoryRepository->many()->where('1 = 0');

		if (!$category instanceof AttributeCategory) {
			if (!$category = $attributeCategoryRepository->one($category)) {
				return $emptyCollection;
			}
		}

		return $this->getCollection()->where('fk_category', $category->getPK());
	}

	public function getAttributeValues($attribute): Collection
	{
		/** @var AttributeValueRepository $attributeValueRepository */
		$attributeValueRepository = $this->getConnection()->findRepository(AttributeValue::class);

		$emptyCollection = $attributeValueRepository->many()->where('1 = 0');

		if (!$attribute instanceof Attribute) {
			if (!$attribute = $this->one($attribute)) {
				return $emptyCollection;
			}
		}

		return $attributeValueRepository->getCollection()->where('fk_attribute', $attribute->getPK());
	}

	public function getCounts(Collection $collection): array
	{
		$collection = $this->getCollection()
			->join(['attributeValue' => 'eshop_attributevalue'], 'attributeValue.fk_attribute = this.uuid')
			->join(['attributeRelation' => 'eshop_attributerelation'], 'attributeRelation.fk_value = attributeValue.uuid')
			->join(['product' => $collection], 'product.uuid=attributeRelation.fk_product', $collection->getVars())
			->setSelect(['count' => 'COUNT(product.uuid)'])
			->setIndex('this.uuid')
			->setGroupBy(['this.uuid']);

		$collection->setFetchClass(\stdClass::class);

		return $collection->toArrayOf('count');
	}
}
