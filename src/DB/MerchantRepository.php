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
	public function getMerchantCustomers(Merchant $merchant): Collection
	{
		$customerRepo = $this->getConnection()->findRepository(Customer::class);

		return $customerRepo->many()
			->join(['nxn' => 'eshop_merchant_nxn_eshop_customer'], 'this.uuid = nxn.fk_customer')
			->where('nxn.fk_merchant', $merchant->getPK());
	}

	/**
	 * @param \Eshop\DB\Customer|string|null $customer
	 * @return array<\Eshop\DB\Merchant>
	 * @throws \StORM\Exception\NotFoundException
	 */
	public function getMerchantsByCustomer($customer = null): array
	{
		if ($customer === null) {
			return [];
		}

		/** @var \Eshop\DB\CustomerRepository $customerRepository */
		$customerRepository = $this->getConnection()->findRepository(Customer::class);

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

	/**
	 * @inheritDoc
	 */
	public function getArrayForSelect(bool $includeHidden = true): array
	{
		return $this->getCollection($includeHidden)->toArrayOf('fullname');
	}

	public function getCollection(bool $includeHidden = false): Collection
	{
		unset($includeHidden);

		return $this->many()->orderBy(['fullname']);
	}
}
