<?php

namespace Eshop;

use Base\ShopsConfig;
use Eshop\DB\AttributeValueRepository;
use Eshop\DB\CategoryRepository;
use Eshop\DB\CategoryTypeRepository;
use Eshop\DB\DisplayAmountRepository;
use Eshop\DB\PricelistRepository;
use Eshop\DB\PriceRepository;
use Eshop\DB\ProductRepository;
use Eshop\DB\VisibilityListItemRepository;
use Eshop\DB\VisibilityListRepository;
use Nette\DI\Container;
use Nette\Utils\Strings;
use StORM\Connection;
use Web\DB\SettingRepository;

class ProductsProvider
{
	public const CATEGORY_COLUMNS_COUNT = 20;

	public function __construct(
		protected readonly ProductRepository $productRepository,
		protected readonly CategoryRepository $categoryRepository,
		protected readonly PriceRepository $priceRepository,
		protected readonly PricelistRepository $pricelistRepository,
		protected readonly Container $container,
		protected readonly Connection $connection,
		protected readonly ShopsConfig $shopsConfig,
		protected readonly CategoryTypeRepository $categoryTypeRepository,
		protected readonly SettingRepository $settingRepository,
		protected readonly VisibilityListItemRepository $visibilityListItemRepository,
		protected readonly AttributeValueRepository $attributeValueRepository,
		protected readonly DisplayAmountRepository $displayAmountRepository,
		protected readonly VisibilityListRepository $visibilityListRepository,
	) {
	}

	public function warmUpCacheTable(): void
	{
		$link = $this->connection->getLink();

		$link->exec('
DROP TABLE IF EXISTS `eshop_products_cache`;
CREATE TABLE `eshop_products_cache` (
  product VARCHAR(32) PRIMARY KEY,
  producer VARCHAR(32),
  displayAmount VARCHAR(32),
  displayAmount_isSold TINYINT(1),
  attributeValues TEXT,
  INDEX idx_producer (producer),
  INDEX idx_displayAmount (displayAmount),
  INDEX idx_displayAmount_isSold (displayAmount_isSold)
);');

		$link->exec('ALTER TABLE `eshop_products_cache` ROW_FORMAT=DYNAMIC;');

		for ($i = 0; $i < self::CATEGORY_COLUMNS_COUNT; $i++) {
			$link->exec("ALTER TABLE `eshop_products_cache` ADD COLUMN category_$i VARCHAR(32), ADD INDEX idx_category_$i (category_$i);");
		}

		foreach ($this->visibilityListRepository->many() as $visibilityList) {
			$link->exec("ALTER TABLE `eshop_products_cache` ADD COLUMN visibilityList_{$visibilityList->getPK()} VARCHAR(32) DEFAULT('{$visibilityList->getPK()}');");
			$link->exec("ALTER TABLE `eshop_products_cache` ADD INDEX idx_visibilityList_{$visibilityList->getPK()} (visibilityList_{$visibilityList->getPK()});");

			$link->exec("ALTER TABLE `eshop_products_cache` ADD COLUMN visibilityList_{$visibilityList->getPK()}_hidden TINYINT(1);");
			$link->exec("ALTER TABLE `eshop_products_cache` ADD COLUMN visibilityList_{$visibilityList->getPK()}_hiddenInMenu TINYINT(1);");
			$link->exec("ALTER TABLE `eshop_products_cache` ADD COLUMN visibilityList_{$visibilityList->getPK()}_priority INT(11);");
			$link->exec("ALTER TABLE `eshop_products_cache` ADD COLUMN visibilityList_{$visibilityList->getPK()}_unavailable TINYINT(1);");
			$link->exec("ALTER TABLE `eshop_products_cache` ADD COLUMN visibilityList_{$visibilityList->getPK()}_recommended INT(11);");
		}

		foreach ($this->pricelistRepository->many() as $priceList) {
			$link->exec("ALTER TABLE `eshop_products_cache` ADD COLUMN priceList_{$priceList->getPK()} VARCHAR(32) DEFAULT('{$priceList->getPK()}');");

			$link->exec("ALTER TABLE `eshop_products_cache` ADD COLUMN priceList_{$priceList->getPK()}_price DOUBLE;");
			$link->exec("ALTER TABLE `eshop_products_cache` ADD COLUMN priceList_{$priceList->getPK()}_priceVat DOUBLE;");
			$link->exec("ALTER TABLE `eshop_products_cache` ADD COLUMN priceList_{$priceList->getPK()}_priceBefore DOUBLE;");
			$link->exec("ALTER TABLE `eshop_products_cache` ADD COLUMN priceList_{$priceList->getPK()}_priceVatBefore DOUBLE;");
		}

		$allPrices = $this->priceRepository->many()->toArray();
		$allVisibilityListItems = $this->visibilityListItemRepository->many()->toArray();
		$allDisplayAmounts = $this->displayAmountRepository->many()->toArray();
		$allCategories = $this->categoryRepository->many()->toArray();
		$allCategoriesByCategory = [];

		$this->connection->getLink()->exec('SET SESSION group_concat_max_len=4294967295');

		$products = [];

		$productsCollection = $this->productRepository->many()
			->join(['price' => 'eshop_price'], 'this.uuid = price.fk_product', type: 'INNER')
			->join(['priceList' => 'eshop_pricelist'], 'price.fk_pricelist = priceList.uuid')
			->join(['discount' => 'eshop_discount'], 'priceList.fk_discount = discount.uuid')
			->join(['eshop_product_nxn_eshop_category'], 'this.uuid = eshop_product_nxn_eshop_category.fk_product')
			->join(['visibilityListItem' => 'eshop_visibilitylistitem'], 'visibilityListItem.fk_product = this.uuid', type: 'INNER')
			->join(['visibilityList' => 'eshop_visibilitylist'], 'visibilityListItem.fk_visibilityList = visibilityList.uuid')
			->join(['assign' => 'eshop_attributeassign'], 'this.uuid = assign.fk_product')
			->setSelect([
				'uuid' => 'this.uuid',
				'fkDisplayAmount' => 'this.fk_displayAmount',
				'fkProducer' => 'this.fk_producer',
				'pricesPKs' => 'GROUP_CONCAT(DISTINCT price.uuid ORDER BY priceList.priority)',
				'categoriesPKs' => 'GROUP_CONCAT(DISTINCT eshop_product_nxn_eshop_category.fk_category)',
				'visibilityListItemsPKs' => 'GROUP_CONCAT(DISTINCT visibilityListItem.uuid ORDER BY visibilityList.priority)',
				'attributeValuesPKs' => 'GROUP_CONCAT(DISTINCT assign.fk_value)',
			])
			->where('priceList.isActive', true)
			->where('(discount.validFrom IS NULL OR discount.validFrom <= DATE(now())) AND (discount.validTo IS NULL OR discount.validTo >= DATE(now()))')
			->setGroupBy(['this.uuid']);

		while ($product = $productsCollection->fetch(\stdClass::class)) {
			/** @var \stdClass $product */

			if (!$prices = $product->pricesPKs) {
				continue;
			}

			$products[$product->uuid] = [
				'product' => $product->uuid,
				'displayAmount' => $product->fkDisplayAmount,
				'displayAmount_isSold' => $product->fkDisplayAmount ? $allDisplayAmounts[$product->fkDisplayAmount]->isSold : null,
				'producer' => $product->fkProducer,
			];

			if ($categories = $product->categoriesPKs) {
				$categories = \explode(',', $categories);

				foreach ($categories as $category) {
					$categoryCategories = $allCategoriesByCategory[$category] ?? null;

					if ($categoryCategories === null) {
						$categoryCategories = $allCategoriesByCategory[$category] = \array_merge($this->getAncestorsOfCategory($category, $allCategories), [$category]);
					}

					$products[$product->uuid]['categories'] = \array_unique(\array_merge($products[$product->uuid]['categories'] ?? [], $categoryCategories));

					foreach ($products[$product->uuid]['categories'] as $productCategory) {
						$productsByCategories[$productCategory][$product->uuid] = true;
					}
				}

				$i = 0;

				foreach ($products[$product->uuid]['categories'] as $productCategory) {
					$products[$product->uuid]["category_$i"] = $productCategory;

					$i++;
				}
			}

			unset($products[$product->uuid]['categories']);

			if ($visibilityListItems = $product->visibilityListItemsPKs) {
				$visibilityListItems = \explode(',', $visibilityListItems);

				foreach ($visibilityListItems as $visibilityListItem) {
					$visibilityListItem = $allVisibilityListItems[$visibilityListItem];

					$products[$product->uuid]["visibilityList_{$visibilityListItem->getValue('visibilityList')}_hidden"] = $visibilityListItem->hidden;
					$products[$product->uuid]["visibilityList_{$visibilityListItem->getValue('visibilityList')}_hiddenInMenu"] = $visibilityListItem->hiddenInMenu;
					$products[$product->uuid]["visibilityList_{$visibilityListItem->getValue('visibilityList')}_priority"] = $visibilityListItem->priority;
					$products[$product->uuid]["visibilityList_{$visibilityListItem->getValue('visibilityList')}_unavailable"] = $visibilityListItem->unavailable;
					$products[$product->uuid]["visibilityList_{$visibilityListItem->getValue('visibilityList')}_recommended"] = $visibilityListItem->recommended;
				}
			}

			$prices = \explode(',', $prices);

			foreach ($prices as $price) {
				$price = $allPrices[$price];

				$products[$product->uuid]["priceList_{$price->getValue('pricelist')}_price"] = $price->price;
				$products[$product->uuid]["priceList_{$price->getValue('pricelist')}_priceVat"] = $price->priceVat;
				$products[$product->uuid]["priceList_{$price->getValue('pricelist')}_priceBefore"] = $price->priceBefore;
				$products[$product->uuid]["priceList_{$price->getValue('pricelist')}_priceVatBefore"] = $price->priceVatBefore;
			}

			$products[$product->uuid]['attributeValues'] = $product->attributeValuesPKs;
		}

		$this->connection->createRows('eshop_products_cache', $products, chunkSize: 1);
	}

	/**
	 * @param array<mixed> $filters
	 * @param string|null $orderByName
	 * @param 'ASC'|'DESC' $orderByDirection Works only if $orderByName is not null
	 * @param array<string, \Eshop\DB\Pricelist> $priceLists
	 * @param array<string, \Eshop\DB\VisibilityList> $visibilityLists
	 * @return array{
	 *     "productPKs": list<string>,
	 *     "attributeValuesCounts": array<string, int>,
	 *     "displayAmountsCounts": array<string, int>,
	 *     "producersCounts": array<string, int>
	 * }|false
	 * @throws \StORM\Exception\NotFoundException
	 */
	public function getProductsFromCacheTable(
		array $filters,
		string|null $orderByName = null,
		string $orderByDirection = 'ASC',
		array $priceLists = [],
		array $visibilityLists = []
	): array|false {
		unset($orderByName);
		unset($orderByDirection);

		$category = isset($filters['category']) ? $this->categoryRepository->many()->where('this.path', $filters['category'])->first(true) : null;

		$categoriesWhereString = null;

		for ($i = 0; $i < self::CATEGORY_COLUMNS_COUNT; $i++) {
			$categoriesWhereString .= "category_$i = :category OR ";
		}

		$categoriesWhereString = Strings::subString($categoriesWhereString, 0, -4);

		$productsCollection = $this->connection->rows(['this' => 'eshop_products_cache'])
			->setSelect([
				'product' => 'this.product',
				'producer' => 'this.producer',
				'attributeValues' => 'this.attributeValues',
				'displayAmount' => 'this.displayAmount',
			])
			->where($this->createCoalesceFromArray($visibilityLists, 'visibilityList_', '_hidden') . ' = 0')
			->where($this->createCoalesceFromArray($priceLists, 'priceList_', '_price') . ' > 0')
			->orderBy([
				$this->createCoalesceFromArray($visibilityLists, 'visibilityList_', '_priority') => 'ASC',
				'case COALESCE(displayAmount_isSold, 2)
					 when 0 then 0
					 when 1 then 1
					 when 2 then 2
					 else 2 end' => 'ASC',
				$this->createCoalesceFromArray($priceLists, 'priceList_', '_price') => 'ASC',
			]);

		if ($category) {
			$productsCollection->where($categoriesWhereString, ['category' => $category->getPK()]);
		}

		$productPKs = [];
		$displayAmountsCounts = [];
		$producersCounts = [];
		$attributeValuesCounts = [];

		while ($product = $productsCollection->fetch()) {
			$productPKs[] = $product->product;

			if ($product->displayAmount) {
				$displayAmountsCounts[$product->displayAmount] = ($displayAmountsCounts[$product->displayAmount] ?? 0) + 1;
			}

			if ($product->producer) {
				$producersCounts[$product->producer] = ($producersCounts[$product->producer] ?? 0) + 1;
			}

			if (!$product->attributeValues) {
				continue;
			}

			foreach (\explode(',', $product->attributeValues) as $attributeValue) {
				if (!$attributeValue) {
					continue;
				}

				$attributeValuesCounts[$attributeValue] = ($attributeValuesCounts[$attributeValue] ?? 0) + 1;
			}
		}

		return [
			'productPKs' => $productPKs,
			'attributeValuesCounts' => $attributeValuesCounts,
			'displayAmountsCounts' => $displayAmountsCounts,
			'producersCounts' => $producersCounts,
		];
	}

	/**
	 * @param string $category
	 * @param array<\Eshop\DB\Category> $allCategories
	 * @return array<string>
	 */
	protected function getAncestorsOfCategory(string $category, array $allCategories): array
	{
		$categories = [];

		while ($ancestor = $allCategories[$category]->getValue('ancestor')) {
			$categories[] = $ancestor;
			$category = $ancestor;
		}

		return $categories;
	}

	protected function createCoalesceFromArray(array $values, string|null $prefix = null, string|null $suffix = null): string
	{
		return $values ? ('COALESCE(' . \implode(',', \array_map(function ($item) use ($prefix, $suffix): string {
				return $prefix . $item . $suffix;
		}, $values)) . ')') : 'NULL';
	}
}
