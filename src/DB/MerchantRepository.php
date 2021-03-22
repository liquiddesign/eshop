<?php

declare(strict_types=1);

namespace Eshop\DB;

use Security\DB\IUserRepository;
use Security\DB\UserRepositoryTrait;

/**
 * @extends \StORM\Repository<\Eshop\DB\Merchant>
 */
class MerchantRepository extends \StORM\Repository implements IUserRepository
{
	use UserRepositoryTrait;

	public function getListForSelect(): array
	{
		return $this->many()->toArrayOf('fullname');
	}

	public function getMerchantCustomers(Merchant $merchant): array
	{
		$customerRepo = $this->getConnection()->findRepository(Customer::class);

		return $customerRepo->many()
			->where('fk_merchant', $merchant->getPK())
			->toArray();
	}

	/**
	 * @param \Eshop\DB\Customer|string $customer
	 * @return \Eshop\DB\Merchant[]
	 * @throws \StORM\Exception\NotFoundException
	 */
	public function getMerchantsByCustomer($customer): array
	{
		/** @var \Eshop\DB\CustomerRepository $customerRepository */
		$customerRepository = $this->getConnection()->findRepository(Customer::class);

		/** @var \Eshop\DB\Customer $customer */
		if (!$customer instanceof Customer) {
			if ($customer = $customerRepository->one($customer)) {
				return [];
			}
		}

		if ($customer->merchant) {
			return [$customer->merchant];
		}

		if (!$customer->group) {
			return [];
		}

		return $this->many()->where('fk_customerGroup', $customer->group->getPK())->toArray();
	}
}
