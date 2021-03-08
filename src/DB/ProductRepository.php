<?php

declare(strict_types=1);

namespace Eshop\DB;

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

	public function __construct(Shopper $shopper, DIConnection $connection, SchemaManager $schemaManager)
	{
		parent::__construct($connection, $schemaManager);

		$this->shopper = $shopper;
	}

	static public function generateUuid(?string $ean, ?string $fullCode, ?string $supplierCode)
	{
		$namespace = 'product';

		if ($ean) {
			return DIConnection::generateUuid($namespace, $ean);
		} elseif ($fullCode) {
			return DIConnection::generateUuid($namespace, $fullCode);
		} elseif ($supplierCode) {
			return DIConnection::generateUuid($namespace, $supplierCode);
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
	 * @return Collection
	 */
	public function getProducts(?array $pricelists = null, ?Customer $customer = null): Collection
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

			if ($pricelist->getValue('currency') !== $currency->getPK() && $currency->czkRatio) {
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
			$price = $this->sqlHandlePrice("prices$id", 'price', $discountLevelPct, $generalPricelistIds, $prec, $convertRatio);
			$priceVat = $this->sqlHandlePrice("prices$id", 'priceVat', $discountLevelPct, $generalPricelistIds, $prec, $convertRatio);
			$priceBefore = $this->sqlHandlePrice("prices$id", 'priceBefore', 0, [], $prec, $convertRatio);
			$priceVatBefore = $this->sqlHandlePrice("prices$id", 'priceVatBefore', 0, [], $prec, $convertRatio);

			$collection->join(["prices$id" => 'eshop_price'], "prices$id.fk_product=this.uuid AND prices$id.fk_pricelist = '" . $pricelist->getPK() . "'");
			$priceSelects[] = "IF(prices$id.price IS NULL,'X',CONCAT_WS('$sep',LPAD(" . $pricelist->priority . ",$priorityLpad,'0'),LPAD(CAST($price AS DECIMAL($priceLpad,$prec)), $priceLpad, '0'),$priceVat,IFNULL($priceBefore,0),IFNULL($priceVatBefore,0),prices$id.fk_pricelist))";
			$priceWhere[] = "prices$id.price IS NOT NULL";
		}

		$expression = \count($pricelists) > 1 ? 'LEAST(' . \implode(',', $priceSelects) . ')' : $priceSelects[0];
		$collection->select(['price' => $this->sqlExplode($expression, $sep, 2)]);
		$collection->select(['pricexxx' => $this->sqlExplode($expression, $sep, 2)]);
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
	 * @return array
	 */
	public function getSimilarProductsByProduct($product): array
	{
		if (!$product instanceof Product) {
			if (!$product = $this->one($product)) {
				return [];
			}
		}

		/** @var \Eshop\DB\RelatedRepository $relatedRepo */
		$relatedRepo = $this->getConnection()->findRepository(Related::class);

		/** @var \Eshop\DB\Related[] $similarCollection */
		$similarCollection = $relatedRepo->getCollection()
			->join(['type' => 'eshop_relatedtype'], 'this.fk_type=type.uuid')
			->where('fk_master = :q OR fk_slave = :q', ['q' => $product->getPK()])
			->where('type.similar', true);

		$similarProducts = [];

		foreach ($similarCollection as $relation) {
			$similarProducts[] = $relation->master->getPK() == $product->getPK() ? $relation->slave : $relation->master;
		}

		return $similarProducts;
	}

	public function getGroupedProductParameters($product): array
	{
		if (!$product instanceof Product) {
			if (!$product = $this->one($product)) {
				return [];
			}
		}

		$groups = [];

		$groupRepo = $this-$this->getConnection()->findRepository(ParameterGroup::class);
		$paramRepo = $this-$this->getConnection()->findRepository(Parameter::class);
		$paramValueRepo = $this-$this->getConnection()->findRepository(ParameterValue::class);

		return $groups;
	}

}
