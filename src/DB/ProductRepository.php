<?php

declare(strict_types=1);

namespace Eshop\DB;

use Common\DB\IGeneralRepository;
use Eshop\Shopper;
use StORM\Collection;
use StORM\DIConnection;
use StORM\Entity;
use StORM\ICollection;
use StORM\Repository;
use StORM\SchemaManager;

/**
 * @extends \StORM\Repository<\Eshop\DB\Product>
 */
class ProductRepository extends Repository implements IGeneralRepository
{
	private Shopper $shopper;
	
	private ParameterRepository $parameterRepository;
	
	private SetRepository $setRepository;
	
	public function __construct(Shopper $shopper, DIConnection $connection, SchemaManager $schemaManager, ParameterRepository $parameterRepository, SetRepository $setRepository)
	{
		parent::__construct($connection, $schemaManager);
		
		$this->shopper = $shopper;
		$this->parameterRepository = $parameterRepository;
		$this->setRepository = $setRepository;
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
		$collection = $this->many(null, true, false);
		
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
			
			$subSelect = $this->getConnection()->rows(['eshop_parametervalue'], ["GROUP_CONCAT(CONCAT_WS('$sep', eshop_parametervalue.uuid, content$suffix, metavalue, fk_parameter))"])
				->join(['eshop_parameter'], 'eshop_parameter.uuid = eshop_parametervalue.fk_parameter')
				->where('eshop_parameter.isPreview=1')
				->where('eshop_parametervalue.fk_product=this.uuid');
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
	public function getDefaultSelect(?string $mutation = null): array
	{
		$selects = parent::getDefaultSelect();
		unset($selects['fk_watcher']);
		
		return $selects;
	}
	
	public function filterCategory($value, ICollection $collection)
	{
		$collection->join(['eshop_product_nxn_eshop_category'], 'eshop_product_nxn_eshop_category.fk_product=this.uuid');
		$collection->join(['categories' => 'eshop_category'], 'categories.uuid=eshop_product_nxn_eshop_category.fk_category');
		$collection->where('categories.path LIKE :category', ['category' => "$value%"]);
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
		$collection->where('ribbons.fk_ribbon', $value);
	}
	
	public function filterPricelist($value, ICollection $collection)
	{
		$collection->join(['prices' => 'eshop_price'], 'prices.fk_product=this.uuid');
		$collection->where('prices.fk_pricelist', $value);
	}
	
	public function filterProducer($value, ICollection $collection)
	{
		$collection->where('this.fk_producer', $value);
	}
	
	public function filterRecommended($value, ICollection $collection)
	{
		$collection->where('this.recommended', $value);
	}
	
	public function filterRelated($values, ICollection $collection)
	{
		$collection->whereNot('this.uuid', $values['uuid'])->where('this.fk_primaryCategory = :category', ['category' => $values['category']]);
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
	
	public function filterParameters($groups, ICollection $collection)
	{
		$suffix = $collection->getConnection()->getMutationSuffix();
		
		/** @var \Eshop\DB\Parameter[] $parameters */
		$parameters = $this->parameterRepository->getCollection()->toArray();
		
		if ($groups) {
			$query = '';
			
			foreach ($groups as $key => $group) {
				foreach ($group as $pKey => $parameter) {
					if ($parameters[$pKey]->type == 'list') {
						$parameter = \is_array($parameter) ? $parameter : [$parameter];
						
						if (\count($parameter) == 0) {
							continue;
						}
						// list
						$implodedValues = "'" . \implode("','", $parameter) . "'";
						$query .= "(parametervalue.fk_parameter = '$pKey' AND parametervalue.metaValue IN ($implodedValues))";
						$query .= ' OR ';
					} elseif ($parameters[$pKey]->type == 'bool') {
						if ($parameter === '1') {
							$query .= "(parametervalue.fk_parameter = '$pKey' AND parametervalue.metaValue = '1')";
							$query .= ' OR ';
						}
					} else {
						// text
						$query .= "(parametervalue.fk_parameter = '$pKey' AND parametervalue.content$suffix = '$parameter')";
						$query .= ' OR ';
					}
				}
			}
			
			if (\strlen($query) > 0) {
				$query = \substr($query, 0, -3);
				
				$collection
					->join(['parametervalue' => 'eshop_parametervalue'], 'this.uuid = parametervalue.fk_product')
					->where($query);
				
			}
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
			->join(['value' => 'eshop_parametervalue'], 'this.uuid = value.fk_parameter')
			->select(['content' => "value.content$suffix"])
			->select(['metaValue' => "value.metaValue"])
			->where('fk_product', $product->getPK());
		
		foreach ($parameters as $parameter) {
			$groupedParameters[$parameter->group->getPK()]['group'] = $parameter->group;
			$groupedParameters[$parameter->group->getPK()]['parameters'][] = $parameter;
		}
		
		return $groupedParameters;
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
	 * @param \Eshop\DB\Customer|\Eshop\DB\Merchant $user
	 * @return \StORM\Collection|\StORM\GenericCollection|array
	 */
	public function getBoughtPrintersByUser($user)
	{
		if (!$user instanceof Customer && !$user instanceof Merchant) {
			return [];
		}
		
		return $this->many()
			->join(['cartitem' => 'eshop_cartitem'], 'this.uuid = cartitem.fk_product')
			->join(['relation' => 'eshop_related'], 'cartitem.fk_product = relation.fk_slave')
			->join(['cart' => 'eshop_cart'], 'cartitem.fk_cart = cart.uuid')
			->join(['purchase' => 'eshop_purchase'], 'cart.fk_purchase = purchase.uuid')
			->join(['orderTable' => 'eshop_order'], 'orderTable.fk_purchase = purchase.uuid')
			->where('orderTable.fk_customer', $user->getPK())
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
	
	public function getUpsellsForProducts($products): array
	{
		$upsells = [];
		
		foreach ($products as $product) {
			if ($product instanceof CartItem) {
				$product = $product->product;
			}
			
			/** @var \Eshop\DB\Product $product */
			if (!$product = $this->getProducts()->where('this.uuid', $product instanceof Product ? $product->getPK() : $product)->first()) {
				continue;
			}
			
			/** @var \Eshop\DB\Product[] $products */
			$products = $this->getProducts()
				->join(['upsell' => 'eshop_product_nxn_eshop_product'], 'this.uuid = upsell.fk_upsell')
				->where('upsell.fk_root', $product->getPK())
				->toArray();
			
			$finalArray = [];
			
			foreach ($product->upsells as $upsell) {
				if (\array_key_exists($upsell->getPK(), $products) && $products[$upsell->getPK()]->getPriceVat()) {
					$finalArray[$upsell->getPK()] = $products[$upsell->getPK()];
				} else {
					if ($product->dependedValue) {
						$upsell->price = $product->getPrice() * ($product->dependedValue / 100);
						$upsell->priceVat = $product->getPriceVat() * ($product->dependedValue / 100);
						$finalArray[$upsell->getPK()] = $upsell;
					}
				}
			}
			
			$upsells[$product->getPK()] = $finalArray;
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
}
