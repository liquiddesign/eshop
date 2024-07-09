<?php

declare(strict_types=1);

namespace Eshop\DB;

use Base\DB\Shop;
use Base\ShopsConfig;
use Security\DB\AccountRepository;
use StORM\DIConnection;
use StORM\SchemaManager;

/**
 * @extends \StORM\Repository<\Eshop\DB\NewsletterUser>
 */
class NewsletterUserRepository extends \StORM\Repository
{
	private AccountRepository $accountRepository;

	public function __construct(DIConnection $connection, SchemaManager $schemaManager, AccountRepository $accountRepository, protected readonly ShopsConfig $shopsConfig)
	{
		parent::__construct($connection, $schemaManager);

		$this->accountRepository = $accountRepository;
	}

	public function registerEmail(string $email, Shop|string|null $shop = null): void
	{
		$existingAccount = $this->accountRepository->many()
			->join(['cm' => 'eshop_catalogpermission'], 'this.uuid = cm.fk_account', [], 'INNER')
			->where('this.login', $email)
			->first();

		$values = ['email' => $email];

		if ($existingAccount) {
			$values['customerAccount'] = $existingAccount->getPK();
		}

		$values['shop'] = $shop instanceof Shop ? $shop->getPK() : (\is_string($shop) ? $shop : $this->shopsConfig->getSelectedShop());

		$this->syncOne($values);
	}

	public function isEmailRegistered(string $email, Shop|string|null $shop = null): bool
	{
		$query = $this->many()->where('this.email', $email);

		$this->shopsConfig->filterShopsInShopEntityCollection($query, $shop);

		return !$query->isEmpty();
	}

	public function unregisterEmail(string $email, Shop|string|null $shop = null): void
	{
		$query = $this->many()->where('this.email', $email);

		$this->shopsConfig->filterShopsInShopEntityCollection($query, $shop);

		$query->delete();
	}
}
