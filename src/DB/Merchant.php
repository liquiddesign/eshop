<?php

declare(strict_types=1);

namespace Eshop\DB;

use Nette\Security\IIdentity;
use Security\DB\Account;
use Security\DB\IUser;
use StORM\RelationCollection;

/**
 * Obchodník
 * @table
 */
class Merchant extends \StORM\Entity implements IIdentity, IUser
{
	/**
	 * Kód
	 * @column
	 */
	public ?string $code;
	
	/**
	 * Jméno
	 * @column
	 */
	public string $fullname;
	
	/**
	 * Email
	 * @column
	 */
	public string $email;

	/**
	 * Právě přihlášený zákazník
	 * @relation
	 * @constraint{"onUpdate":"SET NULL","onDelete":"SET NULL"}
	 */
	public ?Customer $activeCustomer;

	/**
	 * Právě přihlášený účet zákazníka
	 * @relation
	 * @constraint{"onUpdate":"SET NULL","onDelete":"SET NULL"}
	 */
	public ?Account $activeCustomerAccount;

	/**
	 * Skupina zákazníků, ve které je správce
	 * @relation
	 * @constraint{"onUpdate":"SET NULL","onDelete":"SET NULL"}
	 */
	public ?CustomerGroup $customerGroup;

	/**
	 * Oprávnění správy zákazníků
	 * @column
	 */
	public bool $customersPermission = false;

	/**
	 * Oprávnění správy objednávek
	 * @column
	 */
	public bool $ordersPermission = false;

	/**
	 * Informace o objednávkách zákazníků
	 * @column
	 */
	public bool $customerEmailNotification = false;

	/**
	 * Preferovaná mutace
	 * @column
	 */
	public ?string $preferredMutation;

	/**
	 * Ceníky
	 * @relationNxN
	 * @var \StORM\RelationCollection<\Eshop\DB\Pricelist>
	 */
	public RelationCollection $pricelists;

	/**
	 * Zákazníci
	 * @relationNxN
	 * @var \StORM\RelationCollection<\Eshop\DB\Customer>
	 */
	public RelationCollection $customers;
	
	/**
	 * @relationNxN
	 * @var \StORM\RelationCollection<\Security\DB\Account>
	 */
	public RelationCollection $accounts;
	
	protected ?Account $account = null;
	
	public function getId(): string
	{
		return $this->getPK();
	}

	/**
	 * @return array<object>
	 */
	public function getRoles(): array
	{
		return [];
	}
	
	public function getAccount(): ?Account
	{
		return $this->account;
	}
	
	public function setAccount(Account $account): void
	{
		$this->account = $account;
	}

	public function getPreferredMutation(): ?string
	{
		return $this->preferredMutation;
	}
}
