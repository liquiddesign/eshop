<?php

declare(strict_types=1);

namespace Eshop\DB;

use Admin\DB\IGeneralAjaxRepository;
use Base\ShopsConfig;
use Common\DB\IGeneralRepository;
use Eshop\Providers\Helpers;
use League\Csv\EncloseField;
use League\Csv\Writer;
use Nette\Utils\Strings;
use Nette\Utils\Validators;
use Security\DB\IUserRepository;
use Security\DB\UserRepositoryTrait;
use StORM\Collection;
use StORM\DIConnection;
use StORM\ICollection;
use StORM\SchemaManager;

/**
 * @template T of \Eshop\DB\Customer
 * @extends \StORM\Repository<T>
 */
class CustomerRepository extends \StORM\Repository implements IUserRepository, IGeneralRepository, IGeneralAjaxRepository
{
	use UserRepositoryTrait;
	public function __construct(DIConnection $connection, SchemaManager $schemaManager, protected readonly ShopsConfig $shopsConfig)
	{
		parent::__construct($connection, $schemaManager);
	}

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

	/**
	 * @param \StORM\ICollection<\Eshop\DB\Customer> $customers
	 * @param \League\Csv\Writer $writer
	 * @throws \League\Csv\CannotInsertRecord
	 * @throws \League\Csv\InvalidArgument
	 */
	public function csvExportTargito(ICollection $customers, Writer $writer, ?string $origin = null): void
	{
		$writer->setDelimiter(',');
		EncloseField::addTo($writer, "\t\x1f");

		$writer->insertOne([
			'email',
			'origin',
			'last_update',
			'first_name',
			'last_name',
			'city',
			'company',
			'newsletter',
			'phone',
		]);

		$customers = $customers->join(['ecp' => 'eshop_catalogpermission'], 'this.uuid = ecp.fk_customer')
			->join(['enu' => 'eshop_newsletteruser'], 'ecp.fk_account = enu.fk_customerAccount')
			->select(['newsletterPK' => 'enu.uuid']);

		while ($customer = $customers->fetch()) {
			/** @var \Eshop\DB\Customer $customer */

			[$firstName, $lastName] = Helpers::parseFullName($customer->fullname ?? '');

			$phone = \str_replace(' ', '', $customer->phone);

			if (!Strings::startsWith($phone, '+') && Strings::length($phone) === 9) {
				$phone = '+420' . $phone;
			}

			$writer->insertOne([
				$customer->email,
				$origin,
				$customer->createdTs,
				$firstName,
				$lastName,
				$customer->billAddress ? $customer->billAddress->city : null,
				$customer->company,
				$customer->getValue('newsletterPK') ? '1' : '0',
				$phone,
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
	 * @param \Eshop\DB\Customer $customer
	 * @return array<string>
	 */
	public function getEmailVariables(Customer $customer): array
	{
		return [
			'email' => $customer->email,
		];
	}

	/**
	 * @inheritDoc
	 */
	public function getArrayForSelect(bool $includeHidden = true, bool $extended = true): array
	{
		$extended = $extended ? 'extendedName' : 'name';

		return $this->getCollection($includeHidden)->setOrderBy([$extended])->toArrayOf($extended);
	}

	public function getCollection(bool $includeHidden = false): Collection
	{
		unset($includeHidden);

		return $this->many()->select([
			'name' => 'IF(this.company != "",this.company,this.fullname)',
			'extendedName' => 'CONCAT(IF(this.company != "",this.company,this.fullname), " (", IFNULL(this.email, ""), ")")',
		])->orderBy(['fullname']);
	}

	/**
	 * @inheritDoc
	 */
	public function getAjaxArrayForSelect(bool $includeHidden = true, ?string $q = null, ?int $page = null): array
	{
		return $this->getCollection($includeHidden)
			->where('this.fullname LIKE :q OR this.email LIKE :q OR this.company LIKE :q OR this.phone LIKE :q', ['q' => "%$q%", 'exact' => $q,])
			->setPage($page ?? 1, 5)
			->toArrayOf('extendedName');
	}

	/**
	 * Zda má uživatel nějaký aktivní autoship
	 */
	public function hasActiveAutoship(Customer $customer): bool
	{
		$autoships = $this->getConnection()->findRepository(Autoship::class)
			->many()
			->where('active=1 AND activeFrom<NOW() AND purchase.fk_customer=:uuid', ['uuid' => $customer->getPK()]);

		return (bool) \count($autoships);
	}
}
