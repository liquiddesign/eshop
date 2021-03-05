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
}
