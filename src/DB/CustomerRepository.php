<?php

declare(strict_types=1);

namespace Eshop\DB;

use League\Csv\Writer;
use Security\DB\IUserRepository;
use Security\DB\UserRepositoryTrait;
use StORM\Collection;
use StORM\ICollection;

/**
 * @extends \StORM\Repository<\Eshop\DB\Customer>
 */
class CustomerRepository extends \StORM\Repository implements IUserRepository
{
	use UserRepositoryTrait;

	public function createNew(array $values): ?Customer
	{
		return $this->createOne($values);
	}

	public function removePriceList(Customer $customer, Pricelist $pricelist): void
	{
		$this->connection->rows(['eshop_customer_nxn_eshop_pricelist'])
			->where('fk_customer', $customer->getPK())
			->where('fk_pricelist', $pricelist->getPK())
			->delete();
	}

	public function addPriceList(Customer $customer, Pricelist $pricelist): void
	{
		$this->connection->createRow('eshop_customer_nxn_eshop_pricelist', [
			'fk_customer' => $customer->getPK(),
			'fk_pricelist' => $pricelist->getPK(),
		]);
	}
	
	public function csvExport(ICollection $customers, Writer $writer): void
	{
		$writer->setDelimiter(';');
		
		$writer->insertOne(['email', 'fullname']);
		
		foreach ($customers as $customer) {
			
			$writer->insertOne([
				$customer->email,
				$customer->fullname
			]);
		}
	}

	public function getListForSelect(): array
	{
		return $this->many()->toArrayOf('%s', ['fullname']);
	}
	
	public function getEmailVariables(Customer $customer)
	{
		return [
			'login' => $customer->email,
		];
	}
}
