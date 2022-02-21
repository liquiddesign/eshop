<?php

declare(strict_types=1);

namespace Eshop\DB;

use Common\DB\IGeneralRepository;
use Eshop\Shopper;
use Nette\Caching\Cache;
use Nette\Caching\Storage;
use Nette\Caching\Storages\DevNullStorage;
use StORM\Collection;
use StORM\DIConnection;
use StORM\SchemaManager;

/**
 * @extends \StORM\Repository<\Eshop\DB\Attribute>
 */
class AttributeRepository extends \StORM\Repository implements IGeneralRepository
{
	private AttributeValueRepository $attributeValueRepository;
	
	private Cache $cache;
	
	private Shopper $shopper;
	
	public function __construct(
		DIConnection $connection,
		SchemaManager $schemaManager,
		AttributeValueRepository $attributeValueRepository,
		Shopper $shopper,
		Storage $storage
	) {
		parent::__construct($connection, $schemaManager);
		
		$this->attributeValueRepository = $attributeValueRepository;
		$this->shopper = $shopper;
		$this->cache = new Cache($storage);
	}
	
	/**
	 * @param bool $includeHidden
	 * @return string[]
	 */
	public function getArrayForSelect(bool $includeHidden = true): array
	{
		$mutationSuffix = $this->getConnection()->getMutationSuffix();
		
		return $this->getCollection($includeHidden)
			->select(['fullName' => "IF(this.systemic = 1, CONCAT(name$mutationSuffix, ' (', code, ', systémový)'), CONCAT(name$mutationSuffix, ' (', code, ')'))"])
			->toArrayOf('fullName');
	}
	
	public function getCollection(bool $includeHidden = false): Collection
	{
		$mutationSuffix = $this->getConnection()->getMutationSuffix();
		$collection = $this->many();
		
		if (!$includeHidden) {
			$collection->where('this.hidden', false);
		}
		
		return $collection->orderBy(['this.priority', "this.name$mutationSuffix",]);
	}
	
	/**
	 * @param string $categoryPath
	 * @param bool $includeHidden
	 * @return \StORM\Collection<\Eshop\DB\Attribute>
	 */
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
			->select(['internalLabel' => 'CONCAT(IFNULL(internalName, label' . $mutationSuffix . '), " (", this.code, ")")']);
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
			->join(['assign' => 'eshop_attributeassign'], 'this.uuid = assign.fk_value', [], 'INNER')
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
	
	/**
	 * @param array<string, array<string, string>> $attributes
	 * @return array<string, array<string, string>>
	 */
	public function transferAttributeValuesOrRangesToValuesOnly(array $attributes): array
	{
		$targetAttributes = $this->getCollection()
			->where('this.showRange', true)
			->where('this.uuid', \array_keys($attributes))
			->setSelect(['UUID' => 'this.uuid'])
			->setIndex('UUID')
			->toArrayOf('UUID');
		
		$attributeValuesXAttributeValueRanges = $this->attributeValueRepository->getCollection()
			->select(['attValRange' => 'this.fk_attributeValueRange'])
			->where('this.fk_attributeValueRange IS NOT NULL')
			->where('this.fk_attribute', \array_keys($targetAttributes))
			->setIndex('this.uuid')
			->toArrayOf('attValRange');
		
		foreach ($attributes as $attributePK => $attributeValues) {
			if (!isset($targetAttributes[$attributePK])) {
				continue;
			}
			
			foreach ($attributeValues as $index => $attributeValuePK) {
				$filtered = \array_filter($attributeValuesXAttributeValueRanges, function ($value) use ($attributeValuePK): bool {
					return $value === $attributeValuePK;
				});
				
				if (\count($filtered) === 0) {
					continue;
				}
				
				unset($attributes[$attributePK][$index]);
				
				$attributes[$attributePK] = \array_merge($attributes[$attributePK], \array_keys($filtered));
			}
		}
		
		return $attributes;
	}
	
	/**
	 * @param array<int, string> $values
	 * @param array<string, mixed> $filters
	 * @param string|null $cacheId
	 * @return array<string, string>
	 */
	public function getCounts(array $values, array $filters, ?string $cacheId = null): array
	{
		$index = $cacheId ?? $this->shopper->getPriceCacheIndex('attributes', $filters);
		$cache = $index ? $this->cache : new Cache(new DevNullStorage());
		/** @var \Eshop\DB\ProductRepository $productRepository */
		$productRepository = $this->getConnection()->findRepository(Product::class);
		$assignRepository = $this->getConnection()->findRepository(AttributeAssign::class);
		
		return $cache->load($index, static function (&$dependencies) use ($values, $filters, $assignRepository, $productRepository) {
			$rows = $assignRepository->many();
			$rows->setFrom(['assign' => 'eshop_attributeassign'])
				->setSmartJoin(true)
				->setFetchClass(\stdClass::class)
				->setSelect(['count' => 'COUNT(assign.fk_product)'])
				->setIndex('assign.fk_value')
				->join(['this' => 'eshop_product'], 'this.uuid=assign.fk_product')
				->where('fk_value', $values)
				->setGroupBy(['assign.fk_value']);
			
			$productRepository->setProductsConditions($rows, false);
			
			$productRepository->filter($rows, $filters);
			
			return $rows->toArrayOf('count');
		});
	}
}
