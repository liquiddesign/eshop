<?php

declare(strict_types=1);

namespace Eshop\DB;

/**
 * @extends \StORM\Repository<\Eshop\DB\MinimalOrderValue>
 */
class MinimalOrderValueRepository extends \StORM\Repository
{
	public function getMinimalOrderValue(CustomerGroup $group, Currency $currency): ?MinimalOrderValue
	{
		return $this->one(['fk_customerGroup' => $group->getPK(), 'fk_currency' => $currency->getPK()], false);
	}

	public function getMinimalOrderValueForCustomer(Customer $customer, Currency $currency): ?MinimalOrderValue
	{
		return $this->one(['fk_customer' => $customer->getPK(), 'fk_currency' => $currency->getPK()], false);
	}

	/**
	 * Customer-specific value takes priority over group-level value
	 */
	public function getEffectiveMinimalOrderValue(Customer $customer, Currency $currency): ?MinimalOrderValue
	{
		$customerValue = $this->getMinimalOrderValueForCustomer($customer, $currency);

		if ($customerValue !== null) {
			return $customerValue;
		}

		$group = $customer->group;

		if ($group === null) {
			return null;
		}

		return $this->getMinimalOrderValue($group, $currency);
	}
}
