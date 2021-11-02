<?php

declare(strict_types=1);

namespace Eshop\DB;

use Common\DB\IGeneralRepository;
use StORM\Collection;
use StORM\DIConnection;
use StORM\SchemaManager;

/**
 * @extends \StORM\Repository<\Eshop\DB\Attribute>
 */
class AttributeRepository extends \StORM\Repository implements IGeneralRepository
{
	private AttributeValueRepository $attributeValueRepository;

	public function __construct(
		DIConnection $connection,
		SchemaManager $schemaManager,
		AttributeValueRepository $attributeValueRepository
	) {
		parent::__construct($connection, $schemaManager);

		$this->attributeValueRepository = $attributeValueRepository;
	}

	/**
	 * @param bool $includeHidden
	 * @return string[]
	 */
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

	public function getAttributesByCategory(string $categoryPath, bool $includeHidden = false): Collection
	{
		return $this->getCollection($includeHidden)
			->join(['nxn' => 'eshop_attribute_nxn_eshop_category'], 'this.uuid = nxn.fk_attribute')
			->join(['category' => 'eshop_category'], 'category.uuid = nxn.fk_category')
			->where(":path LIKE CONCAT(category.path,'%')", ['path' => $categoryPath]);
	}

	/**
	 * @param string $query
	 * @param int|null $page
	 * @param int $onPage
	 * @return array<string, array<int|string, array<string, mixed>|bool>>
	 */
	public function getAttributesForAdminAjax(string $query, ?int $page = null, int $onPage = 5): array
	{
		$mutationSuffix = $this->getConnection()->getMutationSuffix();

		$attributes = $this->getCollection(true)->where("name$mutationSuffix LIKE :q", ['q' => "%$query%"])
			->setPage($page ?? 1, $onPage)
			->toArrayOf('name');

		$payload = [];
		$payload['results'] = [];

		foreach ($attributes as $pk => $name) {
			$payload['results'][] = [
				'id' => $pk,
				'text' => $name,
			];
		}

		$payload['pagination'] = ['more' => \count($attributes) === $onPage];

		return $payload;
	}

	/**
	 * @deprecated User getAttributesByCategory instead
	 */
	public function getAttributesByCategories($categories, bool $includeHidden = false): Collection
	{
		/** @var \Eshop\DB\CategoryRepository $categoryRepository */
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

	/**
	 * @throws \StORM\Exception\NotFoundException
	 */
	public function getAttributeValues($attribute, bool $includeHidden = false): Collection
	{
		$emptyCollection = $this->attributeValueRepository->many()->where('1 = 0');

		if (!$attribute instanceof Attribute) {
			if (!$attribute = $this->one($attribute)) {
				return $emptyCollection;
			}
		}

		$mutationSuffix = $this->attributeValueRepository->getConnection()->getMutationSuffix();

		return $this->attributeValueRepository->getCollection($includeHidden)
			->where('fk_attribute', $attribute->getPK())
			->select(['internalLabel' => 'IFNULL(internalName, label' . $mutationSuffix . ')']);
	}

	/**
	 * @return array<string, string>
	 */
	public function getWizardAttributes(): array
	{
		$mutationSuffix = $this->getConnection()->getMutationSuffix();

		return $this->getCollection()
			->where('this.showWizard', true)
			->select(['realLabel' => "COALESCE(this.wizardLabel$mutationSuffix, this.name$mutationSuffix)"])
			->toArrayOf('realLabel');
	}

	/**
	 * @param int $step
	 * @return array<string, array<string, \Eshop\DB\AttributeValue>>
	 */
	public function getWizardAttributesValues(int $step): array
	{
		$suffix = $this->getConnection()->getMutationSuffix();
		$items = [];

		/** @var \Eshop\DB\AttributeValue[] $attributeValues */
		$attributeValues = $this->attributeValueRepository->getCollection()
			->join(['attribute' => 'eshop_attribute'], 'this.fk_attribute = attribute.uuid')
			->where('attribute.showWizard', true)
			->where('this.showWizard', true)
			->where('FIND_IN_SET(:s, attribute.wizardStep)', ['s' => $step])
			->setOrderBy(['attribute.priority', 'this.priority', "this.label$suffix"])
			->toArray();

		foreach ($attributeValues as $attributeValue) {
			$items[$attributeValue->getValue('attribute')][$attributeValue->getPK()] = $attributeValue;
		}

		return $items;
	}
}
