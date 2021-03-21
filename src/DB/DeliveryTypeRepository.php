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
	public function getArrayForSelect(bool $includeHidden = true):array
	{
		return $this->getCollection($includeHidden)->toArrayOf('name');
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
	
	public function getDeliveryTypes(Currency $currency, ?Customer $customer, ?CustomerGroup $customerGroup, ?DeliveryDiscount $deliveryDiscount, float $weight): Collection
	{
		$allowedDeliveries = $customer ? $customer->exclusiveDeliveryTypes->toArrayOf('uuid', [], true) : null;
		
		$collection = $this->many()
			->join(['prices' => 'eshop_deliverytypeprice'], 'prices.fk_deliveryType=this.uuid AND prices.fk_currency=:currency', ['currency' => $currency])
			
			->where('prices.weightTo IS NULL OR prices.weightTo <= :weightTo', ['weightTo' => $weight])
			->where('hidden', false)
			->orderBy(['priority']);
		
		if ($deliveryDiscount) {
			$collection->select([
				'price' => 'IF(prices.price IS NULL, 0, IF(:discountPct, prices.price * ((100 - :discountPct) / 100), IF(:discountValue > prices.price, 0, prices.price - :discountValue)))',
				'priceVat' => 'IF(prices.price IS NULL, 0, IF(:discountPct, prices.priceVat * ((100 - :discountPct) / 100), IF(:discountValueVat > prices.priceVat, 0, prices.priceVat - :discountValueVat)))',
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
