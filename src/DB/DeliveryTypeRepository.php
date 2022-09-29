<?php

declare(strict_types=1);

namespace Eshop\DB;

use Common\DB\IGeneralRepository;
use StORM\Collection;

/**
 * @extends \StORM\Repository<\Eshop\DB\DeliveryType>
 */
class DeliveryTypeRepository extends \StORM\Repository implements IGeneralRepository
{
	/**
	 * @inheritDoc
	 */
	public function getArrayForSelect(bool $includeHidden = true): array
	{
		$mutationSuffix = $this->getConnection()->getMutationSuffix();

		return $this->getCollection($includeHidden)
			->select(['fullName' => "IF(this.systemicLock > 0, CONCAT(name$mutationSuffix, ' (', code, ', systémový)'), CONCAT(name$mutationSuffix, ' (', code, ')'))"])
			->toArrayOf('fullName');
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
	
	public function getDeliveryTypes(
		Currency $currency,
		?Customer $customer,
		?CustomerGroup $customerGroup,
		?DeliveryDiscount $deliveryDiscount,
		float $weight,
		float $dimension,
		float $totalWeight = 0.0
	): Collection {
		$allowedDeliveries = $customer ? $customer->exclusiveDeliveryTypes->toArrayOf('uuid', [], true) : null;
		
		$collection = $this->many()
			->join(['prices' => 'eshop_deliverytypeprice'], 'prices.fk_deliveryType=this.uuid AND prices.fk_currency=:currency', ['currency' => $currency])
			->where('prices.weightTo IS NULL OR prices.weightTo >= :weightTo', ['weightTo' => $totalWeight])
			->where('prices.dimensionTo IS NULL OR prices.dimensionTo >= :dimensionTo', ['dimensionTo' => $dimension])
			->where('this.maxWeight IS NULL OR this.maxWeight >= :weight', ['weight' => $weight])
			->where('COALESCE(this.maxLength, this.maxWidth, this.maxDepth) IS NULL OR GREATEST(this.maxLength, this.maxWidth, this.maxDepth) >= :dimension', ['dimension' => $dimension])
			->where('hidden', false)
			->orderBy(['priority' => 'ASC', 'weightTo' => 'DESC', 'dimensionTo' => 'DESC']);

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
