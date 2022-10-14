<?php

declare(strict_types=1);

namespace Eshop\DB;

use Common\DB\IGeneralRepository;
use StORM\Collection;

/**
 * @extends \StORM\Repository<\Eshop\DB\CustomerRole>
 */
class CustomerRoleRepository extends \StORM\Repository implements IGeneralRepository
{
	/**
	 * @inheritDoc
	 */
	public function getArrayForSelect(bool $includeHidden = true): array
	{
		$collection = $this->getCollection($includeHidden);

		return $collection->toArrayOf('name');
	}

	public function getCollection(bool $includeHidden = false): Collection
	{
		unset($includeHidden);

		return $this->many()->orderBy(['name']);
	}

	/**
	 * @TODO
	 */
	public function getCustomerDiscountLevelPct(Customer $customer, CustomerRole $customerRole): int
	{
		unset($customer);
		unset($customerRole);

		return 10;
	}

	public function updateDiscountLevelOfRoleCustomers(CustomerRole $customerRole): void
	{
		foreach ($customerRole->customers as $customer) {
			$customer->update([
				'discountLevelPct' => $this->getCustomerDiscountLevelPct($customer, $customerRole),
			]);
		}
	}
}
