<?php

declare(strict_types=1);

namespace Eshop\DB;

use Security\DB\AccountRepository;
use StORM\DIConnection;
use StORM\SchemaManager;

/**
 * @extends \StORM\Repository<\Eshop\DB\NewsletterUser>
 */
class NewsletterUserRepository extends \StORM\Repository
{
	private AccountRepository $accountRepository;

	public function __construct(DIConnection $connection, SchemaManager $schemaManager, AccountRepository $accountRepository)
	{
		parent::__construct($connection, $schemaManager);

		$this->accountRepository = $accountRepository;
	}

	public function registerEmail(string $email): void
	{
		$existingAccount = $this->accountRepository->many()
			->join(['cm' => 'eshop_catalogpermission'], 'this.uuid = cm.fk_account', [], 'INNER')
			->where('this.login', $email)
			->first();

		$values = ['email' => $email];

		if ($existingAccount) {
			$values['customerAccount'] = $existingAccount->getPK();
		}

		$this->syncOne($values);
	}

	public function isEmailRegistered(string $email): bool
	{
		return !$this->many()->where('this.email', $email)->isEmpty();
	}

	public function unregisterEmail(string $email): void
	{
		$this->many()->where('this.email', $email)->delete();
	}
}
