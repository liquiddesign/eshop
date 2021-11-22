<?php

declare(strict_types=1);

namespace Eshop\DB;

use Common\DB\IGeneralRepository;
use League\Csv\Writer;
use Nette\Utils\Validators;
use Security\DB\IUserRepository;
use Security\DB\UserRepositoryTrait;
use StORM\Collection;
use StORM\ICollection;

/**
 * @extends \StORM\Repository<\Eshop\DB\Customer>
 */
class CustomerRepository extends \StORM\Repository implements IUserRepository, IGeneralRepository
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

		foreach ($customers->toArray() as $customer) {
			$writer->insertOne([
				$customer->email,
			]);
		}
	}

	public function csvExportAccounts(ICollection $accounts, Writer $writer): void
	{
		$writer->setDelimiter(';');

		foreach ($accounts->toArray() as $account) {
			if (Validators::isEmail($account->login)) {
				$writer->insertOne([
					$account->login,
				]);
			}
		}
	}

	/**
	 * @deprecated use getArrayForSelect()
	 * @return string[]
	 */
	public function getListForSelect(): array
	{
		return $this->getArrayForSelect();
	}

	/**
	 * @param \Eshop\DB\Customer $customer
	 * @return string[]
	 */
	public function getEmailVariables(Customer $customer): array
	{
		return [
			'login' => $customer->email,
		];
	}

	/**
	 * @inheritDoc
	 */
	public function getArrayForSelect(bool $includeHidden = true): array
	{
		return $this->getCollection($includeHidden)->select(['name' => 'IF(this.company != "",this.company,this.fullname)'])->toArrayOf('name');
	}

	public function getCollection(bool $includeHidden = false): Collection
	{
		unset($includeHidden);

		return $this->many()->orderBy(['fullname']);
	}
}
