<?php

declare(strict_types=1);

namespace Eshop\DB;

/**
 * @extends \StORM\Repository<\Eshop\DB\DeliveryDiscount>
 */
class DeliveryDiscountRepository extends \StORM\Repository
{
	public function getDeliveryDiscount(Currency $currency): ?DeliveryDiscount
	{
		return $this->many()
			->where('fk_currency', $currency)
			->where('discount.validFrom IS NULL OR discount.validFrom <= now()')
			->where('discount.validTo IS NULL OR discount.validTo >= now()')
			->orderBy(['discountPriceFrom' => 'ASC'])
			->first();
	}

	public function getActiveDeliveryDiscount(Currency $currency, float $sumPrice, ?float $cartWeight = null): ?DeliveryDiscount
	{
		$collection = $this->many()
			->where('fk_currency', $currency)
			->where('discountPriceFrom <= :sumPrice', ['sumPrice' => $sumPrice])
			->where('discount.validFrom IS NULL OR discount.validFrom <= now()')
			->where('discount.validTo IS NULL OR discount.validTo >= now()')
			->orderBy(['discountPriceFrom' => 'DESC']);

		if ($cartWeight) {
			$collection->where('(this.weightFrom IS NULL OR this.weightFrom <= :weight) AND (this.weightTo IS NULL OR this.weightTo >= :weight)', ['weight' => $cartWeight]);
		}

		return $collection->first();
	}

	public function getNextDeliveryDiscount(Currency $currency, float $sumPrice, ?float $cartWeight = null): ?DeliveryDiscount
	{
		$collection = $this->many()
			->where('fk_currency', $currency)
			->where('discountPriceFrom > :sumPrice', ['sumPrice' => $sumPrice])
			->where('discount.validFrom IS NULL OR discount.validFrom <= now()')
			->where('discount.validTo IS NULL OR discount.validTo >= now()')
			->orderBy(['discountPriceFrom']);

		if ($cartWeight) {
			$collection->where('(this.weightFrom IS NULL OR this.weightFrom <= :weight) AND (this.weightTo IS NULL OR this.weightTo >= :weight)', ['weight' => $cartWeight]);
		}

		return $collection->first();
	}
}
