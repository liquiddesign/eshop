<?php

declare(strict_types=1);

namespace Eshop\DB;

use Common\DB\IGeneralRepository;
use Eshop\Shopper;
use League\Csv\EncloseField;
use League\Csv\Writer;
use Pages\Pages;
use Security\DB\Account;
use StORM\Collection;
use StORM\DIConnection;
use StORM\Entity;
use StORM\ICollection;
use StORM\Repository;
use StORM\SchemaManager;
use Web\DB\PageRepository;

/**
 * @extends \StORM\Repository<\Eshop\DB\Product>
 */
class ProductRepository extends Repository implements IGeneralRepository
{
	private Shopper $shopper;

	private AttributeRepository $attributeRepository;

	private SetRepository $setRepository;

	private Pages $pages;

	private PageRepository $pageRepository;

	private PriceRepository $priceRepository;

	public function __construct(Shopper $shopper, DIConnection $connection, SchemaManager $schemaManager, AttributeRepository $attributeRepository, SetRepository $setRepository, Pages $pages, PageRepository $pageRepository, PriceRepository $priceRepository)
	{
		parent::__construct($connection, $schemaManager);

		$this->shopper = $shopper;
		$this->attributeRepository = $attributeRepository;
		$this->setRepository = $setRepository;
		$this->pages = $pages;
		$this->pageRepository = $pageRepository;
		$this->priceRepository = $priceRepository;
	}

	static public function generateUuid(?string $ean, ?string $fullCode)
	{
		$namespace = 'product';

		if ($ean) {
			return DIConnection::generateUuid($namespace, $ean);
		} elseif ($fullCode) {
			return DIConnection::generateUuid($namespace, $fullCode);
		}

		throw new \InvalidArgumentException('There is no unique parameter');
	}

	public function getProduct(string $productUuid): Product
	{
		return $this->getProducts()->where('this.uuid', $productUuid)->first(true);
	}

	/**
	 * @param Pricelist[]|null $pricelists
	 * @param Customer|null $customer
	 * @param bool $selects
	 * @return Collection
	 */
	public function getProducts(?array $pricelists = null, ?Customer $customer = null, bool $selects = true): Collection
	{
		$currency = $this->shopper->getCurrency();
		$convertRatio = null;

		if ($currency->isConversionEnabled()) {
			$convertRatio = $currency->convertRatio;
		}

		$pricelists = $pricelists ? $pricelists : \array_values($this->shopper->getPricelists($currency->isConversionEnabled() ? $currency->convertCurrency : null)->toArray());
		$customer = $customer ?? $this->shopper->getCustomer();
		$discountLevelPct = $customer ? $customer->discountLevelPct : 0;
		$vatRates = $this->shopper->getVatRates();
		$prec = $currency->calculationPrecision;

		$generalPricelistIds = $convertionPricelistIds = [];

		foreach ($pricelists as $pricelist) {
			if ($pricelist->allowDiscountLevel) {
				$generalPricelistIds[] = $pricelist->getPK();
			}

			if ($pricelist->getValue('currency') !== $currency->getPK() && $convertRatio) {
				$convertionPricelistIds[] = $pricelist->getPK();
			}
		}

		if (!$pricelists) {
			bdump('no active pricelist');
			return $this->many()->where('1=0');
		}

		$suffix = $this->getConnection()->getMutationSuffix();
		$sep = '|';
		$priorityLpad = '3';
		$priceLpad = (string)($prec + 9);
		$priceSelects = $priceWhere = [];
		$collection = $this->many()->setSmartJoin(false);

		foreach ($pricelists as $id => $pricelist) {
			if ($selects) {
				$price = $this->sqlHandlePrice("prices$id", 'price', $discountLevelPct, $generalPricelistIds, $prec, $convertRatio);
				$priceVat = $this->sqlHandlePrice("prices$id", 'priceVat', $discountLevelPct, $generalPricelistIds, $prec, $convertRatio);
				$priceBefore = $this->sqlHandlePrice("prices$id", 'priceBefore', 0, [], $prec, $convertRatio);
				$priceVatBefore = $this->sqlHandlePrice("prices$id", 'priceVatBefore', 0, [], $prec, $convertRatio);
				$priceSelects[] = "IF(prices$id.price IS NULL,'X',CONCAT_WS('$sep',LPAD(" . $pricelist->priority . ",$priorityLpad,'0'),LPAD(CAST($price AS DECIMAL($priceLpad,$prec)), $priceLpad, '0'),$priceVat,IFNULL($priceBefore,0),IFNULL($priceVatBefore,0),prices$id.fk_pricelist))";
			}

			$collection->join(["prices$id" => 'eshop_price'], "prices$id.fk_product=this.uuid AND prices$id.fk_pricelist = '" . $pricelist->getPK() . "'");
			$priceWhere[] = "prices$id.price IS NOT NULL";
		}

		if ($selects) {
			$expression = \count($pricelists) > 1 ? 'LEAST(' . \implode(',', $priceSelects) . ')' : $priceSelects[0];
			$collection->select(['price' => $this->sqlExplode($expression, $sep, 2)]);
			$collection->select(['priceVat' => $this->sqlExplode($expression, $sep, 3)]);
			$collection->select(['priceBefore' => $this->sqlExplode($expression, $sep, 4)]);
			$collection->select(['priceVatBefore' => $this->sqlExplode($expression, $sep, 5)]);
			$collection->select(['pricelist' => $this->sqlExplode($expression, $sep, 6)]);
			$collection->select(['currencyCode' => "'" . $currency->code . "'"]);

			$collection->select(['vatPct' => "IF(vatRate = 'standard'," . ($vatRates['standard'] ?? 0) . ",IF(vatRate = 'reduced-high'," . ($vatRates['reduced-high'] ?? 0) . ",IF(vatRate = 'reduced-low'," . ($vatRates['reduced-low'] ?? 0) . ",0)))"]);

			$subSelect = $this->getConnection()->rows(['eshop_attributevalue'], ["GROUP_CONCAT(CONCAT_WS('$sep', eshop_attributevalue.uuid, fk_attribute))"])
				->join(['eshop_attributeassign'], 'eshop_attributeassign.fk_value = eshop_attributevalue.uuid')
				->join(['eshop_attribute'], 'eshop_attribute.uuid = eshop_attributevalue.fk_attribute')
				->where('eshop_attribute.showProduct=1')
				->where('eshop_attributeassign.fk_product=this.uuid');
			$collection->select(['parameters' => $subSelect]);

			$subSelect = $this->getConnection()->rows(['eshop_ribbon'], ['GROUP_CONCAT(uuid)'])
				->join(['nxn' => 'eshop_product_nxn_eshop_ribbon'], 'eshop_ribbon.uuid = nxn.fk_ribbon')
				->where('nxn.fk_product=this.uuid');
			$collection->select(['ribbonsIds' => $subSelect]);

			if ($customer) {
				$subSelect = $this->getConnection()->rows(['eshop_watcher'], ['uuid'])
					->where('eshop_watcher.fk_customer= :test')
					->where('eshop_watcher.fk_product=this.uuid');
				$collection->select(['fk_watcher' => $subSelect], ['test' => $customer->getPK()]);
			}
		}

		$collection->where(\implode(' OR ', $priceWhere));
		$collection->where("this.draft$suffix = 0 AND this.fk_alternative IS NULL");

		return $collection;
	}

	/**
	 * Get default SELECT modifier array for new collection
	 * @return string[]
	 */
	public function getDefaultSelect(?string $mutation = null, ?array $fallbackColumns = null): array
	{
		$selects = parent::getDefaultSelect($mutation, $fallbackColumns);
		unset($selects['fk_watcher']);

		return $selects;
	}

	public function filterCategory($value, ICollection $collection)
	{
		$collection->join(['eshop_product_nxn_eshop_category'], 'eshop_product_nxn_eshop_category.fk_product=this.uuid');
		$collection->join(['categories' => 'eshop_category'], 'categories.uuid=eshop_product_nxn_eshop_category.fk_category');

		$value === false ? $collection->where('categories.uuid IS NULL') : $collection->where('categories.path LIKE :category', ['category' => "$value%"]);
	}

	public function filterPriceFrom($value, ICollection $collection)
	{
		$collection->where('price >= :priceFrom', ['priceFrom' => (float)$value]);
	}

	public function filterPriceTo($value, ICollection $collection)
	{
		$collection->where('price <= :priceTo', ['priceTo' => (float)$value]);
	}

	public function filterTag($value, ICollection $collection)
	{
		$collection->join(['tags' => 'eshop_product_nxn_eshop_tag'], 'tags.fk_product=this.uuid');
		$collection->where('tags.fk_tag', $value);
	}

	public function filterRibbon($value, ICollection $collection)
	{
		$collection->join(['ribbons' => 'eshop_product_nxn_eshop_ribbon'], 'ribbons.fk_product=this.uuid');

		$value === false ? $collection->where('ribbons.fk_ribbon IS NULL') : $collection->where('ribbons.fk_ribbon', $value);
	}

	public function filterInternalRibbon($value, ICollection $collection)
	{
		$collection->join(['internalRibbons' => 'eshop_product_nxn_eshop_internalribbon'], 'internalRibbons.fk_product=this.uuid');

		$value === false ? $collection->where('internalRibbons.fk_internalribbon IS NULL') : $collection->where('internalRibbons.fk_internalribbon', $value);
	}

	public function filterPricelist($value, ICollection $collection)
	{
		$collection->join(['prices' => 'eshop_price'], 'prices.fk_product=this.uuid');

		$value === false ? $collection->where('prices.fk_pricelist IS NULL') : $collection->where('prices.fk_pricelist', $value);
	}

	public function filterProducer($value, ICollection $collection)
	{
		$value === false ? $collection->where('this.fk_producer IS NULL') : $collection->where('this.fk_producer', $value);
	}

	public function filterRecommended($value, ICollection $collection)
	{
		$collection->where('this.recommended', $value);
	}

	public function filterRelated($values, ICollection $collection)
	{
		$collection->whereNot('this.uuid', $values['uuid'])->where('this.fk_primaryCategory = :category', ['category' => $values['category']]);
	}

	public function filterAvailability($values, ICollection $collection)
	{
		$collection->where('this.fk_displayAmount', $values);
	}

	public function filterDelivery($values, ICollection $collection)
	{
		$collection->where('this.fk_displayDelivery', $values);
	}

	public function filterQ($value, ICollection $collection): ICollection
	{
		$langSuffix = $this->getConnection()->getMutationSuffix();

		$collection->select(
			[
				'rel0' => "MATCH(this.name$langSuffix) AGAINST (:q1)",
				'rel1' => "MATCH(this.name$langSuffix, this.perex$langSuffix, this.content$langSuffix) AGAINST (:q1)",
			],
			['q1' => $value],
		);

		$orConditions = [
			"IF(this.subCode, CONCAT(this.code,'.',this.subCode), this.code) LIKE :qlike",
			'this.externalCode LIKE :qlike',
			"this.name$langSuffix LIKE :qlike COLLATE utf8_general_ci",
			"this.name$langSuffix LIKE :qlikeq COLLATE utf8_general_ci",
			"MATCH(this.name$langSuffix) AGAINST (:q)",
			"MATCH(this.name$langSuffix, this.perex$langSuffix, this.content$langSuffix) AGAINST(:q)",
		];

		$collection->where(\implode(' OR ', $orConditions), ['q' => $value, 'qlike' => $value . '%', 'qlikeq' => '%' . $value . '%']);

		return $collection->orderBy([
			"this.name$langSuffix LIKE :qlike" => 'DESC',
			"this.name$langSuffix LIKE :qlikeq" => 'DESC',
			"rel0" => 'DESC',
			"this.code LIKE :qlike" => 'DESC',
		]);
	}

	public function filterCrossSellFilter($value, ICollection $collection)
	{
		[$path, $currentProduct] = $value;

		$collection->where('this.uuid != :currentProduct', ['currentProduct' => "$currentProduct"]);

		$sql = '';

		foreach (\str_split($path, 4) as $path) {
			$sql .= " categories.path LIKE '%$path' OR";
		}

		$collection->where(\substr($sql, 0, -2));
	}

	public function filterInStock($value, ICollection $collection)
	{
		if ($value) {
			$collection
				->join(['displayAmount' => 'eshop_displayamount'], 'displayAmount.uuid=this.fk_displayAmount')
				->where('fk_displayAmount IS NULL OR displayAmount.isSold = 0');
		}
	}

	public function filterDisplayAmount($value, ICollection $collection)
	{
		if ($value) {
			$collection->where('this.fk_displayAmount', $value);
		}
	}

	public function filterQuery($value, ICollection $collection)
	{
		$collection->filter(['q' => $value]);
	}

	public function filterRelatedSlave($value, ICollection $collection)
	{
		$collection->join(['related' => 'eshop_related'], 'this.uuid = related.fk_slave');
		$collection->where('related.fk_type', $value[0]);
		$collection->where('related.fk_master', $value[1]);
	}

	public function filterToners($value, ICollection $collection)
	{
		$collection->join(['related' => 'eshop_related'], 'this.uuid = related.fk_master');
		$collection->where('related.fk_slave', $value);
		$collection->where('related.fk_type = "tonerForPrinter"');
	}

	public function filterCompatiblePrinters($value, ICollection $collection)
	{
		$collection->join(['related' => 'eshop_related'], 'this.uuid = related.fk_slave');
		$collection->where('related.fk_master', $value);
		$collection->where('related.fk_type = "tonerForPrinter"');
	}

	public function filterSimilarProducts($value, ICollection $collection)
	{
		$collection->join(['relation' => 'eshop_related'], 'this.uuid=relation.fk_master OR this.uuid=relation.fk_slave')
			->join(['type' => 'eshop_relatedtype'], 'relation.fk_type=type.uuid')
			->where('type.similar', true)
			->where('this.uuid != :currentRelationProduct', ['currentRelationProduct' => $value]);
	}

	public function filterAttributeValue($value, ICollection $collection)
	{
		$collection
			->join(['attributeAssign' => 'eshop_attributeassign'], 'this.uuid = attributeAssign.fk_product')
			->where('attributeAssign.fk_value', $value);
	}

	public function filterParameters($groups, ICollection $collection)
	{
//		$suffix = $collection->getConnection()->getMutationSuffix();
//
//		/** @var \Eshop\DB\Parameter[] $parameters */
//		$parameters = $this->parameterRepository->getCollection()->toArray();
//
//		if ($groups) {
//			$query = '';
//
//			foreach ($groups as $key => $group) {
//				foreach ($group as $pKey => $parameter) {
//					if ($parameters[$pKey]->type == 'list') {
//						// list
//						$parameter = \is_array($parameter) ? $parameter : [$parameter];
//
//						if (\count($parameter) == 0) {
//							continue;
//						}
//
//						$operator = \strtoupper($parameters[$pKey]->filterType);
//
////						if ($parameters[$pKey]->filterType == 'and') {
////							$implodedValues = "'" . \implode("','", $parameter) . "'";
////							$query .= "(parameteravailablevalue.fk_parameter = '$pKey' AND parametervalue.metaValue IN ($implodedValues))";
////							$query .= ' OR ';
////						} elseif ($parameters[$pKey]->filterType == 'or') {
////
////						}
//
//						$query .= "(parameteravailablevalue.fk_parameter = '$pKey' AND (";
//
//						foreach ($parameter as $parameterItem) {
//							$query .= "parameteravailablevalue.allowedKey = '$parameterItem'";
//							$query .= " $operator ";
//						}
//
//						$query = \substr($query, 0, -4);
//						$query .= ')) OR ';
//					} elseif ($parameters[$pKey]->type == 'bool') {
//						if ($parameter === '1') {
//							$query .= "(parameteravailablevalue.fk_parameter = '$pKey' AND parameteravailablevalue.allowedKey = '1')";
//							$query .= ' OR ';
//						}
//					} else {
//						// text
////						$query .= "(parameteravailablevalue.fk_parameter = '$pKey' AND parametervalue.content$suffix = '$parameter')";
////						$query .= ' OR ';
//					}
//				}
//			}
//
//			if (\strlen($query) > 0) {
//				$query = \substr($query, 0, -3);
//
//				$collection
//					->join(['parametervalue' => 'eshop_parametervalue'], 'this.uuid = parametervalue.fk_product')
//					->join(['parameteravailablevalue' => 'eshop_parameteravailablevalue'], 'parameteravailablevalue.uuid = parametervalue.fk_value')
//					->where($query);
//
//			}
//		}
	}

	public function filterAttributes($attributes, ICollection $collection)
	{
		foreach ($attributes as $attributeKey => $attributeValues) {
			$query = '';

			/** @var Attribute $attribute */
			$attribute = $this->attributeRepository->one($attributeKey);

			if (\count($attributeValues) == 0) {
				continue;
			}

			if ($attribute->showRange) {
				$attributeValues = $this->getConnection()->rows(['eshop_attributevalue'])
					->where('eshop_attributevalue.fk_attributevaluerange', $attributeValues)
					->where('eshop_attributevalue.fk_attribute', $attribute->getPK())
					->toArrayOf('uuid');
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
	}

	private function sqlExplode(string $expression, string $delimiter, int $position): string
	{
		return "REPLACE(SUBSTRING(SUBSTRING_INDEX($expression, '$delimiter', $position),
       LENGTH(SUBSTRING_INDEX($expression, '$delimiter', " . ($position - 1) . ")) + 1), '$delimiter', '')";
	}

	private function sqlHandlePrice(string $alias, string $priceExp, ?int $levelDiscountPct, array $generalPricelistIds, int $prec, ?float $rate): string
	{
		$expression = $rate === null ? "$alias.$priceExp" : "ROUND($alias.$priceExp * $rate,$prec)";

		if ($levelDiscountPct && $generalPricelistIds) {
			$pricelists = \implode(',', \array_map(function ($value) {
				return "'$value'";
			}, $generalPricelistIds));
			$expression = "IF($alias.fk_pricelist IN ($pricelists), ROUND($expression * ((100 - IF(this.discountLevelPct < $levelDiscountPct,this.discountLevelPct,$levelDiscountPct)) / 100),$prec), $expression)";
		}

		return $expression;
	}

	public function getArrayForSelect(bool $includeHidden = true): array
	{
		$suffix = $this->getConnection()->getMutationSuffix();

		return $this->many()->orderBy(["name$suffix"])->toArrayOf('name');
	}

	public function getProductByCodeOrEAN(string $expression): ?Product
	{
		return $this->many()->where('code = :q OR CONCAT(code,".",subCode) = :q OR ean = :q', ['q' => $expression])->first();
	}

	public function getCollection(bool $includeHidden = false): Collection
	{
		$suffix = $this->getConnection()->getMutationSuffix();
		$collection = $this->many();

		if (!$includeHidden) {
			$collection->where('hidden', false);
		}

		return $collection->orderBy(['priority', "name$suffix"]);
	}

	public function getDisplayAmount(int $amount): ?Entity
	{
		return $this->getConnection()->findRepository(DisplayAmount::class)->many()->where('amountFrom <= :amount AND amountTo >= :amount', ['amount' => $amount])->orderBy(['priority'])->first();
	}

	/**
	 * @param \Eshop\DB\Product|string $product
	 * @return \StORM\Collection|null
	 * @throws \StORM\Exception\NotFoundException
	 */
	public function getSimilarProductsByProduct($product): ?Collection
	{
		if (!$product instanceof Product) {
			if (!$product = $this->one($product)) {
				return null;
			}
		}

		/** @var \Eshop\DB\RelatedRepository $relatedRepo */
		$relatedRepo = $this->getConnection()->findRepository(Related::class);

		return $relatedRepo->getCollection()
			->join(['type' => 'eshop_relatedtype'], 'this.fk_type=type.uuid')
			->where('fk_master = :q OR fk_slave = :q', ['q' => $product->getPK()])
			->where('type.similar', true)
			->where('this.uuid != :currentRelationProduct', ['currentRelationProduct' => $product->getPK()]);
	}

	public function getGroupedProductParameters($product): array
	{
		if (!$product instanceof Product) {
			if (!$product = $this->one($product)) {
				return [];
			}
		}

		/** @var \Eshop\DB\ParameterGroupRepository $groupRepo */
		$groupRepo = $this->getConnection()->findRepository(ParameterGroup::class);
		/** @var \Eshop\DB\ParameterRepository $paramRepo */
		$paramRepo = $this->getConnection()->findRepository(Parameter::class);
		/** @var \Eshop\DB\ParameterValueRepository $paramValueRepo */
		$paramValueRepo = $this->getConnection()->findRepository(ParameterValue::class);

		$suffix = $this->getConnection()->getMutationSuffix();

		$groupedParameters = [];

		/** @var \Eshop\DB\Parameter[] $parameters */
		$parameters = $paramRepo->many()
			->join(['availableValue' => 'eshop_parameteravailablevalue'], 'availableValue.fk_parameter = this.uuid')
			->join(['value' => 'eshop_parametervalue'], 'availableValue.uuid = this.fk_value')
			->where('fk_product', $product->getPK());

		foreach ($parameters as $parameter) {
			$groupedParameters[$parameter->group->getPK()]['group'] = $parameter->group;
			$groupedParameters[$parameter->group->getPK()]['parameters'][] = $parameter;
		}

		return $groupedParameters;
	}

	public function getActiveProductAttributes($product, bool $showAll = false): array
	{
		if (!$product instanceof Product) {
			if (!$product = $this->one($product)) {
				return [];
			}
		}

		/** @var \Eshop\DB\CategoryRepository $categoryRepo */
		$categoryRepo = $this->getConnection()->findRepository(Category::class);

		/** @var \Eshop\DB\AttributeRepository $attributeRepository */
		$attributeRepository = $this->getConnection()->findRepository(Attribute::class);

		/** @var \Eshop\DB\AttributeValueRepository $attributeValueRepository */
		$attributeValueRepository = $this->getConnection()->findRepository(AttributeValue::class);

		$productCategory = $product->getPrimaryCategory();

		if (!$productCategory) {
			return [];
		}

		$categories = $categoryRepo->getBranch($productCategory);

		$attributes = $attributeRepository->getAttributesByCategories(\array_values($categories), $showAll)->toArray();
		$attributesList = [];

		foreach ($attributes as $attributeKey => $attribute) {
			$attributeArray = ['attribute' => $attribute];

			$collection = $attributeValueRepository->many()
				->join(['assign' => 'eshop_attributeassign'], 'this.uuid = assign.fk_value')
				->join(['attribute' => 'eshop_attribute'], 'attribute.uuid = this.fk_attribute')
				->where('this.fk_attribute', $attributeKey)
				->where('assign.fk_product', $product->getPK());

			if (!$showAll) {
				$collection->where('attribute.showProduct', true);
			}

			$attributeArray['values'] = $collection->toArray();

			if (\count($attributeArray['values']) == 0) {
				continue;
			}

			foreach ($attributeArray['values'] as $attributeValueKey => $attributeValue) {
				$attributeArray['values'][$attributeValueKey]->page = $this->pageRepository->getPageByTypeAndParams('product_list', null, ['attributeValue' => $attributeValue->getPK()]);
			}

			$attributesList[$attributeKey] = $attributeArray;
		}

		return $attributesList;
	}

	public function isProductInCategory($product, $category): bool
	{
		/** @var \Eshop\DB\CategoryRepository $categoryRepo */
		$categoryRepo = $this->getConnection()->findRepository(Category::class);

		if (!$product instanceof Product) {
			if (!$product = $this->one($product)) {
				return false;
			}
		}

		if (!$category instanceof Category) {
			if (!$category = $categoryRepo->one($category)) {
				return false;
			}
		}

		if (!$primaryCategory = $product->getPrimaryCategory()) {
			return false;
		}

		return $primaryCategory ? $categoryRepo->getRootCategoryOfCategory($primaryCategory) == $category->getPK() : false;
	}

	public function getSlaveProductsByRelationAndMaster($relation, $product): ?ICollection
	{
		if (!$product instanceof Product) {
			if (!$product = $this->one($product)) {
				return null;
			}
		}

		return $this->many()->join(['related' => 'eshop_related'], 'this.uuid = related.fk_slave')
			->where('related.fk_master', $product->getPK())
			->where('related.fk_type', $relation);
	}

	public function getSlaveProductsCountByRelationAndMaster($relation, $product): int
	{
		$result = $this->getSlaveProductsByRelationAndMaster($relation, $product);

		return $result ? $result->enum() : 0;
	}

	/**
	 * @param Account|string $account
	 * @return \StORM\Collection|\StORM\GenericCollection|array
	 * @throws \StORM\Exception\NotFoundException
	 */
	public function getBoughtPrintersByUser($account)
	{
		if (!$account instanceof Account) {
			if (!$account = $this->getConnection()->findRepository(Account::class)->one($account)) {
				return [];
			}
		}

		return $this->many()
			->join(['cartitem' => 'eshop_cartitem'], 'this.uuid = cartitem.fk_product')
			->join(['relation' => 'eshop_related'], 'cartitem.fk_product = relation.fk_slave')
			->join(['cart' => 'eshop_cart'], 'cartitem.fk_cart = cart.uuid')
			->join(['purchase' => 'eshop_purchase'], 'cart.fk_purchase = purchase.uuid')
			->join(['orderTable' => 'eshop_order'], 'orderTable.fk_purchase = purchase.uuid')
			->where('purchase.fk_account', $account->getPK())
			->where('relation.fk_type', 'tonerForPrinter')
			->where('orderTable.completedTs IS NOT NULL')
			->orderBy(['orderTable.completedTs' => 'DESC']);
	}

	/**
	 * @param \Eshop\DB\Product|string|null $product
	 * @return \Eshop\DB\Product|null
	 * @throws \StORM\Exception\NotFoundException
	 */
	public function get($product)
	{
		if (!$product) {
			return null;
		}

		if (!$product instanceof Product) {
			if (!$product = $this->one($product)) {
				return null;
			}
		}

		return $product;
	}

	public function getUpsellsForCartItem(CartItem $cartItem): array
	{
		if (!$cartItem->product) {
			return [];
		}

		$upsells = [];

		foreach ($cartItem->product->upsells->orderBy(['priority']) as $upsell) {
			/** @var \Eshop\DB\Product $upsell */
			if (!$upsellWithPrice = $this->getProducts()->where('this.uuid', $upsell->getPK())->first()) {
				if ($cartItem->product->dependedValue) {
					$upsell->shortName = $upsell->name;
					$upsell->name = $cartItem->productName . ' - ' . $upsell->name;
					$upsell->price = $cartItem->getPriceSum() * ($cartItem->product->dependedValue / 100);
					$upsell->priceVat = $cartItem->getPriceVatSum() * ($cartItem->product->dependedValue / 100);
					$upsell->currencyCode = $this->shopper->getCurrency()->code;
					$upsells[$upsell->getPK()] = $upsell;
				}
			} else {
				if ($upsellWithPrice->getPriceVat()) {
					$upsellWithPrice->shortName = $upsellWithPrice->name;
					$upsellWithPrice->name = $cartItem->productName . ' - ' . $upsellWithPrice->name;
					$upsellWithPrice->price = $cartItem->amount * $upsellWithPrice->getPrice();
					$upsellWithPrice->priceVat = $cartItem->amount * $upsellWithPrice->getPriceVat();
					$upsells[$upsellWithPrice->getPK()] = $upsellWithPrice;
				}
			}
		}

		return $upsells;
	}

	/**
	 * @param CartItem[] $cartItems
	 * @return array[]
	 */
	public function getUpsellsForCartItems(array $cartItems): array
	{
		$upsells = [];

		foreach ($cartItems as $cartItem) {
			$upsells[$cartItem->getPK()] = $this->getUpsellsForCartItem($cartItem);
		}

		return $upsells;
	}

	/**
	 * @param $set
	 * @return \Eshop\DB\Set[]
	 * @throws \StORM\Exception\NotFoundException
	 */
	public function getSetProducts($set): array
	{
		if (!$set instanceof Product) {
			if (!$set = $this->one($set)) {
				return [];
			}
		}

		return $this->setRepository->many()->join(['product' => 'eshop_product'], 'product.uuid=this.fk_set')->orderBy(['priority'])->toArray();
	}

	public function csvExport(ICollection $products, Writer $writer, array $columns = [], array $attributes = [], string $delimiter = ';', ?array $header = null): void
	{
		$writer->setDelimiter($delimiter);

		EncloseField::addTo($writer, "\t\22");

		if ($header) {
			$writer->insertOne($header);
		}

		$mutationSuffix = $this->getConnection()->getMutationSuffix();

		$products->setGroupBy(['this.uuid'])
			->join(['price' => 'eshop_price'], 'this.uuid = price.fk_product')
			->select([
				'priceMin' => 'MIN(price.price)',
				'priceMax' => 'MAX(price.price)'
			])
			->join(['assign' => 'eshop_attributeassign'], 'this.uuid = assign.fk_product')
			->join(['attributeValue' => 'eshop_attributevalue'], 'assign.fk_value = attributeValue.uuid')
			->join(['producer' => 'eshop_producer'], 'producer.uuid = this.fk_producer')
			->select([
				'attributes' => "GROUP_CONCAT(DISTINCT CONCAT(attributeValue.fk_attribute, ':', CONCAT(COALESCE(attributeValue.label$mutationSuffix), '#', attributeValue.code)))",
				'producerCodeName' => "CONCAT(COALESCE(producer.name$mutationSuffix, ''), '#', COALESCE(producer.code, ''))",
			]);

		/** @var Product $product */
		while ($product = $products->fetch()) {
			$row = [];

			$productAttributes = [];

			if ($product->attributes) {
				$tmp = \explode(',', $product->attributes);

				foreach ($tmp as $value) {
					$tmpExplode = \explode(':', $value);

					if (\count($tmpExplode) != 2) {
						continue;
					}

					$attributeValue = \explode('#', $tmpExplode[1]);

					if (\count($attributeValue) != 2) {
						continue;
					}

					$productAttributes[$tmpExplode[0]][$attributeValue[1]] = $attributeValue[0];
				}
			}

			foreach ($columns as $columnKey => $columnValue) {
				if ($columnKey === 'perex') {
					$row[] = $product->getValue($columnKey) ? \strip_tags($product->getValue($columnKey)) : null;
				} elseif ($columnKey == 'producer') {
					$row[] = $product->producerCodeName;
				} else {
					$row[] = $product->getValue($columnKey) === false ? '0' : $product->getValue($columnKey);
				}
			}

			foreach ($attributes as $attributePK => $attributeName) {
				if (!isset($productAttributes[$attributePK]) || !$product->attributes || !\is_array($productAttributes[$attributePK])) {
					$row[] = null;

					continue;
				}

				$tmp = '';

				foreach ($productAttributes[$attributePK] as $attributeValueCode => $attributeValueLabel) {
					$tmp .= "$attributeValueLabel#$attributeValueCode:";
				}

				$row[] = \strlen($tmp) > 0 ? \substr($tmp, 0, -1) : null;
			}

			$writer->insertOne($row);
		}
	}
}
