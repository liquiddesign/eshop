<?php

declare(strict_types=1);

namespace Eshop\DB;

use Common\DB\IGeneralRepository;
use Eshop\ShopperUser;
use Nette\Caching\Cache;
use Nette\Caching\Storage;
use Nette\Caching\Storages\DevNullStorage;
use Nette\Utils\Strings;
use StORM\Collection;
use StORM\DIConnection;
use StORM\SchemaManager;
use Web\DB\SettingRepository;

/**
 * @extends \StORM\Repository<\Eshop\DB\Attribute>
 */
class AttributeRepository extends \StORM\Repository implements IGeneralRepository
{
	private Cache $cache;

	public function __construct(
		DIConnection $connection,
		SchemaManager $schemaManager,
		private readonly AttributeValueRepository $attributeValueRepository,
		private readonly ShopperUser $shopperUser,
		Storage $storage,
		private readonly SettingRepository $settingRepository
	) {
		parent::__construct($connection, $schemaManager);

		$this->cache = new Cache($storage);
	}

	/**
	 * @param bool $includeHidden
	 * @return array<string>
	 */
	public function getArrayForSelect(bool $includeHidden = true): array
	{
		$mutationSuffix = $this->getConnection()->getMutationSuffix();

		return $this->getCollection($includeHidden)
			->select(['fullName' => "IF(this.systemic = 1 OR this.systemicLock > 0, CONCAT(name$mutationSuffix, ' (', code, ', systémový)'), CONCAT(name$mutationSuffix, ' (', code, ')'))"])
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
			->where(Strings::length($query) > 0 ? Strings::substring($query, 0, -3) : '1=0');
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
			->where('attribute.hideEmptyValues = "0" OR EXISTS(SELECT * FROM eshop_attributeassign WHERE this.uuid = eshop_attributeassign.fk_value)')
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
		$collection = $this->attributeValueRepository->getCollection();
		$suffix = $this->getConnection()->getMutationSuffix();
		$index = 'wizzard_' . $step;

		return $this->cache->load($index, static function (&$dependencies) use ($step, $suffix, $collection) {
			$items = [];

			/** @var array<\Eshop\DB\AttributeValue> $attributeValues */
			$attributeValues = $collection
				->join(['attribute' => 'eshop_attribute'], 'this.fk_attribute = attribute.uuid')
				->join(['assign' => 'eshop_attributeassign'], 'this.uuid = assign.fk_value', [], 'INNER')
				->where('attribute.showWizard', true)
				->where('attribute.hidden', false)
				->where('this.showWizard', true)
				->where('FIND_IN_SET(:s, attribute.wizardStep)', ['s' => $step])
				->setOrderBy(['attribute.priority', 'this.priority', "this.label$suffix"])
				->toArray();

			foreach ($attributeValues as $attributeValue) {
				$items[$attributeValue->getValue('attribute')][$attributeValue->getPK()] = $attributeValue;
			}

			return $items;
		});
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
		$index = $cacheId ?? $this->shopperUser->getPriceCacheIndex('attributes', $filters);
		$cache = $index ? $this->cache : new Cache(new DevNullStorage());
		/** @var \Eshop\DB\ProductRepository $productRepository */
		$productRepository = $this->getConnection()->findRepository(Product::class);

		return $cache->load($index, static function (&$dependencies) use ($values, $filters, $productRepository) {
			$dependencies = [
				Cache::TAGS => ['categories', 'products', 'pricelists'],
			];

			$rows = $productRepository->many();
			$rows->setSmartJoin(false);

			$rows->setFrom(['assign' => 'eshop_attributeassign'])
				->join(['this' => 'eshop_product'], 'this.uuid=assign.fk_product')
				->setSelect(['count' => 'COUNT(assign.fk_product)'])
				->setIndex('assign.fk_value')
				->setGroupBy(['assign.fk_value']);

			if ($values) {
				$rows->where('fk_value', $values);
			}

			$productRepository->joinVisibilityListItemToProductCollection($rows);
			$productRepository->joinPrimaryCategoryToProductCollection($rows);

			$productRepository->setProductsConditions($rows, false);

			$productRepository->filter($rows, $filters);

			return $rows->fetchColumns('count');
		});
	}

	public function getAttributeBySettingName(string $settingName): ?Attribute
	{
		$setting = $this->settingRepository->getValueByName($settingName);

		return $setting ? $this->one($setting) : null;
	}
}
