<?php

declare(strict_types=1);

namespace Eshop\DB;

use Common\DB\IGeneralRepository;
use Security\DB\IUserRepository;
use Security\DB\UserRepositoryTrait;
use StORM\Collection;

/**
 * @extends \StORM\Repository<\Eshop\DB\Merchant>
 */
class MerchantRepository extends \StORM\Repository implements IUserRepository, IGeneralRepository
{
	use UserRepositoryTrait;

	/**
	 * @deprecated use getArrayForSelect()
	 */
	public function getListForSelect(): array
	{
		return $this->getArrayForSelect();
	}

	public function getMerchantCustomers(Merchant $merchant): Collection
	{
		$customerRepo = $this->getConnection()->findRepository(Customer::class);

		return $customerRepo->many()
			->join(['nxn' => 'eshop_merchant_nxn_eshop_customer'], 'this.uuid = nxn.fk_customer')
			->where('nxn.fk_merchant', $merchant->getPK());
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

		return $this->many()
			->join(['nxn' => 'eshop_merchant_nxn_eshop_customer'], 'this.uuid = nxn.fk_merchant')
			->where('nxn.fk_customer', $customer->getPK())
			->toArray();
	}

	public function getArrayForSelect(bool $includeHidden = true): array
	{
		return $this->getCollection($includeHidden)->toArrayOf('fullname');
	}

	public function getCollection(bool $includeHidden = false): Collection
	{
		$collection = $this->many();

		return $collection->orderBy(['fullname']);
	}
}
