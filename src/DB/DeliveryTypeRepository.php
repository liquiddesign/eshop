<?php

declare(strict_types=1);

namespace Eshop\DB;

use Base\DB\Shop;
use Base\Repository\GeneralRepositoryHelpers;
use Base\ShopsConfig;
use Common\DB\IGeneralRepository;
use StORM\Collection;
use StORM\DIConnection;
use StORM\SchemaManager;

/**
 * @extends \StORM\Repository<\Eshop\DB\DeliveryType>
 */
class DeliveryTypeRepository extends \StORM\Repository implements IGeneralRepository
{
	public function __construct(
		DIConnection $connection,
		SchemaManager $schemaManager,
		private readonly ShopsConfig $shopsConfig,
	) {
		parent::__construct($connection, $schemaManager);
	}

	/**
	 * @inheritDoc
	 */
	public function getArrayForSelect(bool $includeHidden = true): array
	{
		$mutationSuffix = $this->getConnection()->getMutationSuffix();

		return GeneralRepositoryHelpers::toArrayOfFullName(
			GeneralRepositoryHelpers::selectFullName($this->getCollection($includeHidden), selectColumnName: "this.name$mutationSuffix", uniqueColumnName: 'this.code')
		);
	}
	
	public function getCollection(bool $includeHidden = false): Collection
	{
		$suffix = $this->getConnection()->getMutationSuffix();
		$collection = $this->many();
		
		if (!$includeHidden) {
			$collection->where('hidden', false);
		}
		
		return $collection->orderBy(['priority DESC', "name$suffix"]);
	}

	/**
	 * @param \Eshop\DB\Currency $currency
	 * @param \Eshop\DB\Customer|null $customer
	 * @param \Eshop\DB\CustomerGroup|null $customerGroup
	 * @param \Eshop\DB\DeliveryDiscount|null $deliveryDiscount
	 * @param float $weight
	 * @param float $dimension
	 * @param float $totalWeight
	 * @return \StORM\Collection<\Eshop\DB\DeliveryType>
	 */
	public function getDeliveryTypes(
		Currency $currency,
		Customer|null $customer,
		CustomerGroup|null $customerGroup,
		DeliveryDiscount|null $deliveryDiscount,
		float $weight,
		float $dimension,
		float $totalWeight = 0.0,
		Shop|null $selectedShop = null,
	): Collection {
		$allowedDeliveries = $customer?->exclusiveDeliveryTypes->toArrayOf('uuid', [], true);

		$collection = $this->many()
			->join(['prices' => 'eshop_deliverytypeprice'], 'prices.fk_deliveryType=this.uuid AND prices.fk_currency=:currency', ['currency' => $currency])
			->where('prices.weightTo IS NULL OR prices.weightTo >= (if(this.totalMaxWeight IS NULL, :weightTo, :totalWeight))', ['weightTo' => $weight, 'totalWeight' => $totalWeight])
			->where('prices.dimensionTo IS NULL OR prices.dimensionTo >= :dimensionTo', ['dimensionTo' => $dimension])
			->where('this.maxWeight IS NULL OR this.maxWeight >= :weight', ['weight' => $weight])
			->where('this.totalMaxWeight IS NULL OR this.totalMaxWeight >= :totalWeight')
			->where('COALESCE(this.maxLength, this.maxWidth, this.maxDepth) IS NULL OR GREATEST(this.maxLength, this.maxWidth, this.maxDepth) >= :dimension', ['dimension' => $dimension])
			->where('hidden', false)
			->orderBy(['priority' => 'ASC', 'weightTo' => 'DESC', 'dimensionTo' => 'DESC']);

		if ($selectedShop) {
			$this->shopsConfig->filterShopsInShopEntityCollection($collection, $selectedShop);
		}

		if ($deliveryDiscount) {
			$collection->select([
				'price' => 'IF(prices.price IS NULL, 0, IF(:discountPct, prices.price * ((100 - :discountPct) / 100), IF(:discountValue > prices.price, 0, prices.price - :discountValue)))',
				'priceVat' => 'IF(prices.price IS NULL, 0, IF(:discountPct, prices.priceVat * ((100 - :discountPct) / 100), 
				IF(:discountValueVat > prices.priceVat, 0, prices.priceVat - :discountValueVat)))',
				'priceBefore' => 'prices.price',
				'priceBeforeVat' => 'prices.priceVat',
			], [
				'discountPct' => \intval($deliveryDiscount->discountPct),
				'discountValue' => \intval($deliveryDiscount->discountValue),
				'discountValueVat' => \intval($deliveryDiscount->discountValueVat),
			]);
		} else {
			$collection->select(['price' => 'IFNULL(prices.price,0)', 'priceVat' => 'IFNULL(prices.priceVat,0)', 'priceBefore' => 'NULL', 'priceBeforeVat' => 'NULL']);
		}
		
		if ($allowedDeliveries) {
			$collection->where('this.uuid', $allowedDeliveries);
		} elseif ($customerGroup) {
			$collection->where('fk_exclusive IS NULL OR fk_exclusive = :customerGroup', ['customerGroup' => $customerGroup]);
		}
		
		return $collection;
	}
}
