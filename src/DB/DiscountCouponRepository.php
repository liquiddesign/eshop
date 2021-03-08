<?php

declare(strict_types=1);

namespace Eshop\DB;

/**
 * @extends \StORM\Repository<\Eshop\DB\DiscountCoupon>
 */
class DiscountCouponRepository extends \StORM\Repository
{
	public function getValidCoupon(string $code, Currency $currency, ?Customer $customer): ?DiscountCoupon
	{
		$collection = $this->many()
			->where('code', $code)
			->where('fk_currency', $currency)
			->where('discount.validFrom IS NULL OR discount.validFrom <= now()')
			->where('discount.validTo IS NULL OR discount.validTo >= now()');
		
		if ($customer) {
			$collection->where('fk_exclusiveCustomer IS NULL OR fk_exclusiveCustomer = :customer', ['customer' => $customer]);
		} else {
			$collection->where('fk_exclusiveCustomer IS NULL');
		}
		
		return $collection->first();
	}
}
