<?php

declare(strict_types=1);

namespace Eshop\DB;

use Common\DB\IGeneralRepository;
use Eshop\Controls\ProductFilter;
use Eshop\Shopper;
use InvalidArgumentException;
use League\Csv\EncloseField;
use League\Csv\Writer;
use Nette\Application\LinkGenerator;
use Nette\Caching\Cache;
use Nette\Caching\Storage;
use Nette\Caching\Storages\DevNullStorage;
use Nette\Http\Request;
use Nette\Utils\Arrays;
use Nette\Utils\DateTime;
use Nette\Utils\Strings;
use StORM\Collection;
use StORM\DIConnection;
use StORM\Entity;
use StORM\Expression;
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

	private PageRepository $pageRepository;

	private DeliveryDiscountRepository $deliveryDiscountRepository;

	private LoyaltyProgramDiscountLevelRepository $loyaltyProgramDiscountLevelRepository;

	private OrderRepository $orderRepository;

	private RelatedRepository $relatedRepository;

	private LinkGenerator $linkGenerator;

	private Request $request;

	private Cache $cache;

	public function __construct(
		Shopper $shopper,
		DIConnection $connection,
		SchemaManager $schemaManager,
		AttributeRepository $attributeRepository,
		SetRepository $setRepository,
		PageRepository $pageRepository,
		DeliveryDiscountRepository $deliveryDiscountRepository,
		LoyaltyProgramDiscountLevelRepository $loyaltyProgramDiscountLevelRepository,
		OrderRepository $orderRepository,
		RelatedRepository $relatedRepository,
		LinkGenerator $linkGenerator,
		Request $request,
		Storage $storage
	) {
		parent::__construct($connection, $schemaManager);

		$this->shopper = $shopper;
		$this->attributeRepository = $attributeRepository;
		$this->setRepository = $setRepository;
		$this->pageRepository = $pageRepository;
		$this->deliveryDiscountRepository = $deliveryDiscountRepository;
		$this->loyaltyProgramDiscountLevelRepository = $loyaltyProgramDiscountLevelRepository;
		$this->orderRepository = $orderRepository;
		$this->relatedRepository = $relatedRepository;
		$this->linkGenerator = $linkGenerator;
		$this->request = $request;
		$this->cache = new Cache($storage);
	}

	/**
	 * @param string $productUuid
	 * @return mixed|object|\StORM\Entity|null|\Eshop\DB\Product
	 * @throws \StORM\Exception\NotFoundException
	 */
	public function getProduct(string $productUuid)
	{
		return $this->getProducts()->where('this.uuid', $productUuid)->first(true);
	}

	/**
	 * @param \Eshop\DB\Pricelist[]|null $pricelists
	 * @param \Eshop\DB\Customer|null $customer
	 * @param bool $selects
	 */
	public function getProducts(?array $pricelists = null, ?Customer $customer = null, bool $selects = true): Collection
	{
		$currency = $this->shopper->getCurrency();
		$convertRatio = null;

		if ($currency->isConversionEnabled()) {
			$convertRatio = $currency->convertRatio;
		}

		/** @var \Eshop\DB\Pricelist[] $pricelists */
		$pricelists = $pricelists ?: \array_values($this->shopper->getPricelists()->toArray());
		$customer ??= $this->shopper->getCustomer();
		$discountLevelPct = $customer ? $this->getBestDiscountLevel($customer) : 0;
		$vatRates = $this->shopper->getVatRates();
		$prec = $currency->calculationPrecision;

		$generalPricelistIds = $convertionPricelistIds = [];

		/** @var \Eshop\DB\Pricelist $pricelist */
		foreach ($pricelists as $pricelist) {
			if ($pricelist->allowDiscountLevel) {
				$generalPricelistIds[] = $pricelist->getPK();
			}

			if ($pricelist->getValue('currency') === $currency->getPK() || !$convertRatio) {
				continue;
			}
		}

		if (!$pricelists) {
			\bdump('no active pricelist');

			return $this->many()->where('1=0');
		}

		$suffix = $this->getConnection()->getMutationSuffix();
		$sep = '|';
		$priorityLpad = '3';
		$priceLpad = (string)($prec + 9);
		$priceSelects = $priceWhere = [];
		$collection = $this->many()->setSmartJoin(false);

		/** @var \Eshop\DB\Pricelist $pricelist */
		foreach ($pricelists as $id => $pricelist) {
			if ($selects) {
				$price = $this->sqlHandlePrice("prices$id", 'price', $discountLevelPct, $generalPricelistIds, $prec, $convertRatio);
				$priceVat = $this->sqlHandlePrice("prices$id", 'priceVat', $discountLevelPct, $generalPricelistIds, $prec, $convertRatio);
				$priceBefore = $this->sqlHandlePrice("prices$id", 'priceBefore', 0, [], $prec, $convertRatio);
				$priceVatBefore = $this->sqlHandlePrice("prices$id", 'priceVatBefore', 0, [], $prec, $convertRatio);
				$priceSelects[] = "IF(prices$id.price IS NULL,'X',CONCAT_WS('$sep',LPAD(" . $pricelist->priority .
					",$priorityLpad,'0'),LPAD(CAST($price AS DECIMAL($priceLpad,$prec)), $priceLpad, '0'),$priceVat,IFNULL($priceBefore,0),IFNULL($priceVatBefore,0),prices$id.fk_pricelist))";
			}
		}

		if ($selects) {
			$expression = \count($pricelists) > 1 ? 'LEAST(' . \implode(',', $priceSelects) . ')' : $priceSelects[0];
			$collection->select(['price' => $this->sqlExplode($expression, $sep, 2)]);
			$collection->select(['priceVat' => $this->sqlExplode($expression, $sep, 3)]);
			$collection->select(['priceBefore' => $this->sqlExplode($expression, $sep, 4)]);
			$collection->select(['priceVatBefore' => $this->sqlExplode($expression, $sep, 5)]);
			$collection->select(['pricelist' => $this->sqlExplode($expression, $sep, 6)]);
			$collection->select(['currencyCode' => "'" . $currency->code . "'"]);

			$collection->select(['vatPct' => "IF(vatRate = 'standard'," . ($vatRates['standard'] ?? 0) . ",IF(vatRate = 'reduced-high'," .
				($vatRates['reduced-high'] ?? 0) . ",IF(vatRate = 'reduced-low'," . ($vatRates['reduced-low'] ?? 0) . ',0)))']);

			$subSelect = $this->getConnection()
				->rows(['eshop_attributevalue'], ["GROUP_CONCAT(CONCAT_WS('$sep', eshop_attributevalue.uuid, fk_attribute, eshop_attributevalue.label$suffix, eshop_attributevalue.metaValue))"])
				->join(['eshop_attributeassign'], 'eshop_attributeassign.fk_value = eshop_attributevalue.uuid')
				->join(['eshop_attribute'], 'eshop_attribute.uuid = eshop_attributevalue.fk_attribute')
				->where('eshop_attribute.showProduct=1')
				->where('eshop_attributeassign.fk_product=this.uuid');
			$collection->select(['parameters' => $subSelect]);

			$subSelect = $this->getConnection()->rows(['eshop_ribbon'], ['GROUP_CONCAT(uuid)'])
				->join(['nxn' => 'eshop_product_nxn_eshop_ribbon'], 'eshop_ribbon.uuid = nxn.fk_ribbon')
				->where('nxn.fk_product=this.uuid');
			$collection->select(['ribbonsIds' => $subSelect]);

			$collection->join(['primaryCategory' => 'eshop_category'], 'primaryCategory.uuid=this.fk_primaryCategory');
			$collection->select([
				'fallbackImage' => 'primaryCategory.productFallbackImageFileName',
				'primaryCategoryPath' => 'primaryCategory.path',
				'perex' => "COALESCE(this.perex$suffix, primaryCategory.defaultProductPerex$suffix)",
				'content' => "COALESCE(this.content$suffix, primaryCategory.defaultProductContent$suffix)",
			]);

			if ($customer) {
				$subSelect = $this->getConnection()->rows(['eshop_watcher'], ['uuid'])
					->where('eshop_watcher.fk_customer= :test')
					->where('eshop_watcher.fk_product=this.uuid');
				$collection->select(['fk_watcher' => $subSelect], ['test' => $customer->getPK()]);
			}
		}

		$this->setProductsConditions($collection);

		return $collection;
	}
	
	public function setProductsConditions(ICollection $collection, bool $includeHidden = true): void
	{
		$priceWhere = new Expression();
		
		foreach (\array_values($this->shopper->getPricelists()->toArray()) as $id => $pricelist) {
			$collection->join(["prices$id" => 'eshop_price'], "prices$id.fk_product=this.uuid AND prices$id.fk_pricelist = '" . $pricelist->getPK() . "'");
			$priceWhere->add('OR', "prices$id.price IS NOT NULL");
		}
		
		if (!$includeHidden) {
			$collection->where('this.hidden = 0');
		}
		
		if (!$sql = $priceWhere->getSql()) {
			return;
		}

		$collection->where($sql);
	}
	
	/**
	 * @param string $groupBy
	 * @param array<string, mixed> $filters
	 * @return array<string, string>
	 */
	public function getCountGroupedBy(string $groupBy, $filters): array
	{
		$index = $this->shopper->getPriceCacheIndex($groupBy, $filters);
		$cache = $index ? $this->cache : new Cache(new DevNullStorage());
		$productRepository = $this;
		
		return $cache->load($index, static function (&$dependencies) use ($groupBy, $filters, $productRepository) {
			$dependencies = [
				Cache::TAGS => ['categories', 'products', 'pricelists'],
			];
			$rows = $productRepository->many();
			$rows->setSelect(['count' => "COUNT($groupBy)"])
				->setIndex($groupBy)
				->setGroupBy([$groupBy]);
			$productRepository->setProductsConditions($rows, false);
			$productRepository->filter($rows, $filters);
			
			return $rows->toArrayOf('count');
		});
	}

	public function getBestDiscountLevel(Customer $customer): int
	{
		$loyaltyProgram = $customer->loyaltyProgram;

		if ($loyaltyProgram === null || $loyaltyProgram->isActive() === false) {
			return $customer->discountLevelPct;
		}

		$customerTurnover = $this->orderRepository->getCustomerTotalTurnover($customer, $loyaltyProgram->turnoverFrom ? new DateTime($loyaltyProgram->turnoverFrom) : null, new DateTime());

		/** @var \Eshop\DB\LoyaltyProgramDiscountLevel|null $discountLevel */
		$discountLevel = $this->loyaltyProgramDiscountLevelRepository->many()
			->where('this.fk_loyaltyProgram', $loyaltyProgram)
			->where('this.priceThreshold <= :turnover', ['turnover' => (string)$customerTurnover])
			->orderBy(['this.discountLevel' => 'DESC'])
			->first();

		if ($discountLevel) {
			return $discountLevel->discountLevel > $customer->discountLevelPct ? $discountLevel->discountLevel : $customer->discountLevelPct;
		}

		return $customer->discountLevelPct;
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

	public function filterCategory($value, ICollection $collection): void
	{
		if ($value === false) {
			$collection->where('this.fk_primaryCategory IS NULL');

			return;
		}

		$id = $this->getConnection()->findRepository(Category::class)->many()->match(['path' => $value])->firstValue('uuid');

		if (!$id) {
			$collection->where('1=0');
		} else {
			$subSelect = $this->getConnection()->rows(['eshop_product_nxn_eshop_category'], ['fk_product'])
				->join(['eshop_category'], 'eshop_category.uuid=eshop_product_nxn_eshop_category.fk_category')
				->where('eshop_category.path LIKE :path', ['path' => "$value%"]);
			$collection->where('this.fk_primaryCategory = :category OR this.uuid IN (' . $subSelect->getSql() . ')', ['category' => $id] + $subSelect->getVars());
		}
	}

	public function filterPriceFrom($value, ICollection $collection): void
	{
		$no = \count($this->shopper->getPricelists()->toArray());
		$expression = new Expression();

		for ($i = 0; $i !== $no; $i++) {
			$expression->add('OR', "prices$i.price >= :priceFrom");
		}

		$collection->where($expression->getSql(), ['priceFrom' => (float)$value]);
	}

	public function filterPriceTo($value, ICollection $collection): void
	{
		$no = \count($this->shopper->getPricelists()->toArray());
		$expression = new Expression();

		for ($i = 0; $i !== $no; $i++) {
			$expression->add('OR', "prices$i.price <= :priceTo");
		}

		$collection->where($expression->getSql(), ['priceTo' => (float)$value]);
	}

	public function filterPriceVatFrom($value, ICollection $collection): void
	{
		$no = \count($this->shopper->getPricelists()->toArray());
		$expression = new Expression();

		for ($i = 0; $i !== $no; $i++) {
			$expression->add('OR', "prices$i.priceVat >= :priceVatFrom");
		}

		$collection->where($expression->getSql(), ['priceVatFrom' => (float)$value]);
	}

	public function filterPriceVatTo($value, ICollection $collection): void
	{
		$no = \count($this->shopper->getPricelists()->toArray());
		$expression = new Expression();

		for ($i = 0; $i !== $no; $i++) {
			$expression->add('OR', "prices$i.priceVat <= :priceVatTo");
		}

		$collection->where($expression->getSql(), ['priceVatTo' => (float)$value]);
	}

	public function filterTag($value, ICollection $collection): void
	{
		$collection->join(['tags' => 'eshop_product_nxn_eshop_tag'], 'tags.fk_product=this.uuid');
		$collection->where('tags.fk_tag', $value);
	}

	public function filterRibbon($value, ICollection $collection): void
	{
		$collection->join(['ribbons' => 'eshop_product_nxn_eshop_ribbon'], 'ribbons.fk_product=this.uuid');

		$value === false ? $collection->where('ribbons.fk_ribbon IS NULL') : $collection->where('ribbons.fk_ribbon', $value);
	}

	public function filterInternalRibbon($value, ICollection $collection): void
	{
		$collection->join(['internalRibbons' => 'eshop_product_nxn_eshop_internalribbon'], 'internalRibbons.fk_product=this.uuid');

		$value === false ? $collection->where('internalRibbons.fk_internalribbon IS NULL') : $collection->where('internalRibbons.fk_internalribbon', $value);
	}

	public function filterPricelist($value, ICollection $collection): void
	{
		$collection->join(['prices' => 'eshop_price'], 'prices.fk_product=this.uuid');

		$value === false ? $collection->where('prices.fk_pricelist IS NULL') : $collection->where('prices.fk_pricelist', $value);
	}

	public function filterProducer($value, ICollection $collection): void
	{
		$value === false ? $collection->where('this.fk_producer IS NULL') : $collection->where('this.fk_producer', $value);
	}

	public function filterProducers($value, ICollection $collection): void
	{
		$collection->where('this.fk_producer', $value);
	}

	public function filterRecommended($value, ICollection $collection): void
	{
		$collection->where('this.recommended', $value);
	}

	public function filterHidden($value, ICollection $collection): void
	{
		$collection->where('this.hidden', $value);
	}

	public function filterRelated($values, ICollection $collection): void
	{
		$collection->whereNot('this.uuid', $values['uuid'])->where('this.fk_primaryCategory = :category', ['category' => $values['category']]);
	}

	public function filterAvailability($values, ICollection $collection): void
	{
		$collection->where('this.fk_displayAmount', $values);
	}

	public function filterDelivery($values, ICollection $collection): void
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
			"IF(this.subCode, CONCAT(this.code,'.',this.subCode), this.code) LIKE :qlikeq",
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
			'rel0' => 'DESC',
			'this.code LIKE :qlike' => 'DESC',
		]);
	}

	public function filterCrossSellFilter($value, ICollection $collection): void
	{
		[$path, $currentProduct] = $value;

		$collection->where('this.uuid != :currentProduct', ['currentProduct' => "$currentProduct"]);

		$sql = '';

		foreach (\str_split($path, 4) as $path) {
			$sql .= " categories.path LIKE '%$path' OR";
		}

		$collection->where(\substr($sql, 0, -2));
	}

	public function filterInStock($value, ICollection $collection): void
	{
		if ($value) {
			$collection
				->join(['displayAmount' => 'eshop_displayamount'], 'displayAmount.uuid=this.fk_displayAmount')
				->where('fk_displayAmount IS NULL OR displayAmount.isSold = 0');
		}
	}

	public function filterDisplayAmount($value, ICollection $collection): void
	{
		if ($value) {
			$collection->where('this.fk_displayAmount', $value);
		}
	}

	public function filterQuery($value, Collection $collection): void
	{
		$this->filterQ($value, $collection);
	}

	/**
	 * @deprecated
	 */
	public function filterRelatedSlave($value, ICollection $collection): void
	{
		$collection->join(['related' => 'eshop_related'], 'this.uuid = related.fk_slave');
		$collection->where('related.fk_type', $value[0]);
		$collection->where('related.fk_master', $value[1]);
	}

	/**
	 * @deprecated
	 */
	public function filterToners($value, ICollection $collection): void
	{
		$collection->join(['related' => 'eshop_related'], 'this.uuid = related.fk_master');
		$collection->where('related.fk_slave', $value);
		$collection->where('related.fk_type = "tonerForPrinter"');
	}

	/**
	 * @deprecated
	 */
	public function filterCompatiblePrinters($value, ICollection $collection): void
	{
		$collection->join(['related' => 'eshop_related'], 'this.uuid = related.fk_slave');
		$collection->where('related.fk_master', $value);
		$collection->where('related.fk_type = "tonerForPrinter"');
	}

	public function filterRelatedTypeMaster(array $value, ICollection $collection): void
	{
		$collection->join(['related' => 'eshop_related'], 'this.uuid = related.fk_slave', [], 'LEFT');
		$collection->where('related.fk_master', $value[0]);
		$collection->where('related.fk_type', $value[1]);
	}

	public function filterRelatedTypeSlave(array $value, ICollection $collection): void
	{
		$collection->join(['related' => 'eshop_related'], 'this.uuid = related.fk_master', [], 'LEFT');
		$collection->where('related.fk_slave', $value[0]);
		$collection->where('related.fk_type', $value[1]);
	}

	public function filterSimilarProducts($value, ICollection $collection): void
	{
		$collection->join(['relation' => 'eshop_related'], 'this.uuid=relation.fk_master OR this.uuid=relation.fk_slave')
			->join(['type' => 'eshop_relatedtype'], 'relation.fk_type=type.uuid')
			->where('type.similar', true)
			->where('this.uuid != :currentRelationProduct', ['currentRelationProduct' => $value]);
	}

	public function filterAttributeValue($value, ICollection $collection): void
	{
		$collection
			->join(['attributeAssign' => 'eshop_attributeassign'], 'this.uuid = attributeAssign.fk_product')
			->where('attributeAssign.fk_value', $value);
	}

	/**
	 * @deprecated
	 */
	public function filterParameters($groups, ICollection $collection): void
	{
		unset($groups);
		unset($collection);
	}

	public function filterAttributes($attributes, ICollection $collection): void
	{
		//@TODO performance

		foreach ($attributes as $attributeKey => $selectedAttributeValues) {
			if (\count($selectedAttributeValues) === 0) {
				continue;
			}

			if (Arrays::contains(\array_keys(ProductFilter::SYSTEMIC_ATTRIBUTES), $attributeKey)) {
				$funcName = 'filter' . Strings::firstUpper($attributeKey);

				$this->$funcName($selectedAttributeValues, $collection);

				continue;
			}

			/** @var \Eshop\DB\Attribute $attribute */
			$attribute = $this->attributeRepository->one($attributeKey);

			if ($attribute->filterType === 'and') {
				foreach ($selectedAttributeValues as $attributeValue) {
					$subSelect = $this->getConnection()->rows(['eshop_attributevalue'])
						->join(['eshop_attributeassign'], 'eshop_attributeassign.fk_value = eshop_attributevalue.uuid')
						->join(['eshop_attribute'], 'eshop_attribute.uuid = eshop_attributevalue.fk_attribute')
						->where('eshop_attributeassign.fk_product=this.uuid')
						->where("eshop_attributevalue.fk_attribute = '$attributeKey'")
						->where($attribute->showRange ? "eshop_attributevalue.fk_attributevaluerange = '$attributeValue'" : "eshop_attributevalue.uuid = '$attributeValue'");

					$collection->where('EXISTS (' . $subSelect->getSql() . ')');
				}
			} else {
				$query = '';

				if ($attribute->showRange) {
					$selectedAttributeValues = $this->getConnection()->rows(['eshop_attributevalue'])
						->where('eshop_attributevalue.fk_attributevaluerange', $selectedAttributeValues)
						->where('eshop_attributevalue.fk_attribute', $attribute->getPK())
						->toArrayOf('uuid');
				}

				$subSelect = $this->getConnection()->rows(['eshop_attributevalue'])
					->join(['eshop_attributeassign'], 'eshop_attributeassign.fk_value = eshop_attributevalue.uuid')
					->join(['eshop_attribute'], 'eshop_attribute.uuid = eshop_attributevalue.fk_attribute')
					->where('eshop_attributeassign.fk_product=this.uuid');

				$query .= "(eshop_attributevalue.fk_attribute = \"$attributeKey\" AND (";

				foreach ($selectedAttributeValues as $attributeValue) {
					$query .= "eshop_attributevalue.uuid = \"$attributeValue\" OR ";
				}

				$query = \substr($query, 0, -3) . '))';

				$subSelect->where($query);

				$collection->where('EXISTS (' . $subSelect->getSql() . ')');
			}
		}
	}

	/**
	 * @inheritDoc
	 */
	public function getArrayForSelect(bool $includeHidden = true): array
	{
		unset($includeHidden);

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

	/**
	 * @deprecated Use same method from DisplayAmountRepository.php
	 */
	public function getDisplayAmount(int $amount): ?Entity
	{
		return $this->getConnection()->findRepository(DisplayAmount::class)->many()->where('amountFrom <= :amount AND amountTo >= :amount', ['amount' => $amount])->orderBy(['priority'])->first();
	}

	/**
	 * @param \Eshop\DB\Product|string $product
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

	/**
	 * @param \Eshop\DB\Product|string $product
	 * @return array<int|string, array<string, array<int, \Eshop\DB\Parameter>|\Eshop\DB\ParameterGroup>>
	 * @throws \StORM\Exception\NotFoundException
	 * @deprecated
	 */
	public function getGroupedProductParameters($product): array
	{
		if (!$product instanceof Product) {
			if (!$product = $this->one($product)) {
				return [];
			}
		}

		/** @var \Eshop\DB\ParameterRepository $paramRepo */
		$paramRepo = $this->getConnection()->findRepository(Parameter::class);

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

	/**
	 * @param \Eshop\DB\Product|string $product
	 * @param bool $showAll
	 * @return array<array<string, array<\Eshop\DB\AttributeValue>|\StORM\Entity>>
	 * @throws \StORM\Exception\NotFoundException
	 */
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

			/** @var \Eshop\DB\AttributeValue[] $attributeValues */
			$attributeValues = $collection->toArray();

			$attributeArray['values'] = $attributeValues;

			if (\count($attributeArray['values']) === 0) {
				continue;
			}

			foreach ($attributeArray['values'] as $attributeValueKey => $attributeValue) {
				$attributeArray['values'][$attributeValueKey]->setValue('page', $this->pageRepository->getPageByTypeAndParams('product_list', null, ['attributeValue' => $attributeValue->getPK()]));
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

		if (!$primaryCategory = $product->primaryCategory) {
			return false;
		}

		return $categoryRepo->getRootCategoryOfCategory($primaryCategory)->getPK() === $category->getPK();
	}

	public function getSlaveProductsCountByRelationAndMaster($relation, $product): int
	{
		$result = $this->getSlaveProductsByRelationAndMaster($relation, $product);

		return $result ? $result->enum() : 0;
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

	/**
	 * @param \Eshop\DB\Product|string|null $product
	 * @throws \StORM\Exception\NotFoundException
	 */
	public function get($product): ?Product
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

	/**
	 * @param \Eshop\DB\CartItem[] $cartItems
	 * @return array<string, array<string, object>>
	 * @throws \StORM\Exception\NotFoundException
	 */
	public function getCartItemsRelations(array $cartItems): array
	{
		$upsells = [];

		foreach ($cartItems as $cartItem) {
			$upsells[$cartItem->getPK()] = $this->getCartItemRelations($cartItem);
		}

		return $upsells;
	}

	/**
	 * @param \Eshop\DB\CartItem $cartItem
	 * @return array<string, \Eshop\DB\Product>
	 * @throws \StORM\Exception\NotFoundException
	 */
	public function getCartItemRelations(CartItem $cartItem): array
	{
		if (!$cartItem->getValue('product')) {
			return [];
		}

		$itemRelationsForCart = [];

		/** @var \Eshop\DB\Related $related */
		foreach ($this->relatedRepository->many()
					 ->join(['relatedType' => 'eshop_relatedtype'], 'this.fk_type = relatedType.uuid')
					 ->where('relatedType.showCart', true)
					 ->where('relatedType.hidden', false)
					 ->where('this.fk_master', $cartItem->getValue('product'))
					 ->whereNot('this.fk_slave', $cartItem->getValue('product'))
					 ->orderBy(['this.priority']) as $related
		) {
			if (isset($itemRelationsForCart[$related->getValue('slave')])) {
				continue;
			}

			/** @var \Eshop\DB\Product|\stdClass|null $slaveProduct */
			$slaveProduct = $this->getProducts()->where('this.uuid', $related->getValue('slave'))->first();

			if (!$slaveProduct) {
				continue;
			}

			$slaveProduct->shortName = $slaveProduct->name;
			$slaveProduct->name = $cartItem->productName . ' - ' . $slaveProduct->name;
			$slaveProduct->amount = $cartItem->amount * $related->amount;

			if ($related->masterPct !== null) {
				$slaveProduct->price = $cartItem->price * $related->masterPct / 100;
				$slaveProduct->priceVat = $cartItem->priceVat * $related->masterPct / 100;
			} elseif ($related->discountPct !== null) {
				$slaveProduct->price = $slaveProduct->getPrice() - ($slaveProduct->getPrice() * $related->discountPct / 100);
				$slaveProduct->priceVat = $slaveProduct->getPriceVat() - ($slaveProduct->getPriceVat() * $related->discountPct / 100);
			}

			$itemRelationsForCart[$slaveProduct->getPK()] = $slaveProduct;
		}

		return $itemRelationsForCart;
	}

	/**
	 * @param \Eshop\DB\Product|string $set
	 * @return \Eshop\DB\Set[]
	 * @throws \StORM\Exception\NotFoundException
	 * @deprecated
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
			->join(['priceTable' => 'eshop_price'], 'this.uuid = priceTable.fk_product')
			->select([
				'priceMin' => 'MIN(priceTable.price)',
				'priceMax' => 'MAX(priceTable.price)',
			])
			->join(['assign' => 'eshop_attributeassign'], 'this.uuid = assign.fk_product')
			->join(['attributeValue' => 'eshop_attributevalue'], 'assign.fk_value = attributeValue.uuid')
			->join(['producer' => 'eshop_producer'], 'producer.uuid = this.fk_producer')
			->join(['storeAmount' => 'eshop_amount'], 'storeAmount.fk_product = this.uuid')
			->join(['store' => 'eshop_store'], 'storeAmount.fk_store = store.uuid')
			->join(['categoryAssign' => 'eshop_product_nxn_eshop_category'], 'this.uuid = categoryAssign.fk_product')
			->join(['category' => 'eshop_category'], 'categoryAssign.fk_category = category.uuid')
			->select([
				'attributes' => "GROUP_CONCAT(DISTINCT CONCAT(attributeValue.fk_attribute, ':', CONCAT(COALESCE(attributeValue.label$mutationSuffix), '#', attributeValue.code)))",
				'producerCodeName' => "CONCAT(COALESCE(producer.name$mutationSuffix, ''), '#', COALESCE(producer.code, ''))",
				'amounts' => "GROUP_CONCAT(DISTINCT CONCAT(storeAmount.inStock, '#', store.code) SEPARATOR ':')",
				'groupedCategories' => "GROUP_CONCAT(DISTINCT CONCAT(category.name$mutationSuffix, '#', 
                IF(category.code IS NULL OR category.code = '', category.uuid, category.code)) ORDER BY LENGTH(category.path) SEPARATOR ':')",
			]);

		while ($product = $products->fetch()) {
			/** @var \Eshop\DB\Product|\stdClass $product */

			$row = [];

			$productAttributes = [];

			if ($product->attributes) {
				$tmp = \explode(',', $product->attributes);

				foreach ($tmp as $value) {
					$tmpExplode = \explode(':', $value);

					if (\count($tmpExplode) !== 2) {
						continue;
					}

					$attributeValue = \explode('#', $tmpExplode[1]);

					if (\count($attributeValue) !== 2) {
						continue;
					}

					$productAttributes[$tmpExplode[0]][$attributeValue[1]] = $attributeValue[0];
				}
			}

			foreach (\array_keys($columns) as $columnKey) {
				if ($columnKey === 'producer') {
					$row[] = $product->producerCodeName;
				} elseif ($columnKey === 'storeAmount') {
					$row[] = $product->amounts;
				} elseif ($columnKey === 'categories') {
					$row[] = $product->groupedCategories;
				} elseif ($columnKey === 'adminUrl') {
					$row[] = $this->linkGenerator->link('Eshop:Admin:Product:edit', [$product]);
				} elseif ($columnKey === 'frontUrl') {
					$page = $this->pageRepository->getPageByTypeAndParams('product_detail', null, ['product' => $product->getPK()]);
					$row[] = $page ? $this->request->getUrl()->getBaseUrl() . $page->getUrl($this->getConnection()->getMutation()) : null;
				} else {
					$row[] = $product->getValue($columnKey) === false ? '0' : $product->getValue($columnKey);
				}
			}

			foreach (\array_keys($attributes) as $attributePK) {
				if (!isset($productAttributes[$attributePK]) || !$product->attributes || !\is_array($productAttributes[$attributePK])) {
					$row[] = null;

					continue;
				}

				$tmp = '';

				foreach ($productAttributes[$attributePK] as $attributeValueCode => $attributeValueLabel) {
					$tmp .= "$attributeValueLabel#$attributeValueCode:";
				}

				$row[] = \substr($tmp, 0, -1);
			}

			$writer->insertOne($row);
		}
	}

	/*public function filterCategory($value, ICollection $collection)
	{
		$collection->join(['eshop_product_nxn_eshop_category'], 'eshop_product_nxn_eshop_category.fk_product=this.uuid');
		$collection->join(['categories' => 'eshop_category'], 'categories.uuid=eshop_product_nxn_eshop_category.fk_category');

		$value === false ? $collection->where('categories.uuid IS NULL') : $collection->where('categories.path LIKE :category', ['category' => "$value%"]);
	}*/

	public function isProductDeliveryFreeVat(Product $product): bool
	{
		return $this->isProductDeliveryFree($product, true);
	}

	public function isProductDeliveryFreeWithoutVat(Product $product): bool
	{
		return $this->isProductDeliveryFree($product, false);
	}

	public function clearCache(): void
	{
		$this->cache->clean([
			Cache::TAGS => ['products'],
		]);
	}

	public static function generateUuid(?string $ean, ?string $fullCode): string
	{
		$namespace = 'product';

		if ($ean) {
			return DIConnection::generateUuid($namespace, $ean);
		}

		if ($fullCode) {
			return DIConnection::generateUuid($namespace, $fullCode);
		}

		throw new InvalidArgumentException('There is no unique parameter');
	}

	private function sqlHandlePrice(string $alias, string $priceExp, ?int $levelDiscountPct, array $generalPricelistIds, int $prec, ?float $rate): string
	{
		$expression = $rate === null ? "$alias.$priceExp" : "ROUND($alias.$priceExp * $rate,$prec)";

		if ($levelDiscountPct && $generalPricelistIds) {
			$pricelists = \implode(',', \array_map(function ($value) {
				return "'$value'";
			}, $generalPricelistIds));

			$expression = "IF($alias.fk_pricelist IN ($pricelists), 
			ROUND($expression * ((100 - IF(this.discountLevelPct > $levelDiscountPct,this.discountLevelPct,$levelDiscountPct)) / 100),$prec),
			$expression)";
		}

		return $expression;
	}

	private function sqlExplode(string $expression, string $delimiter, int $position): string
	{
		return "REPLACE(SUBSTRING(SUBSTRING_INDEX($expression, '$delimiter', $position),
       LENGTH(SUBSTRING_INDEX($expression, '$delimiter', " . ($position - 1) . ")) + 1), '$delimiter', '')";
	}

	private function isProductDeliveryFree(Product $product, bool $vat): bool
	{
		/** @var \Eshop\DB\DeliveryDiscount $deliveryDiscount */
		foreach ($this->deliveryDiscountRepository->many() as $deliveryDiscount) {
			if ($deliveryDiscount->discount->isActive() === false ||
				$deliveryDiscount->discountPriceFrom > ($vat ? $product->getValue('priceVat') : $product->getValue('price')) ||
				(\abs($deliveryDiscount->discountPct - 100) >= \PHP_FLOAT_EPSILON)) {
				continue;
			}

			return true;
		}

		return false;
	}
}
