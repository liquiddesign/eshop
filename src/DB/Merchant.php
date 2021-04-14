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
	 * Oprávnění potvrzovat registrace přiřazených zákazníků (přímo, skupina), nastavovat katalogové oprávnění
	 * @column
	 */
	public bool $extendedPermission = false;

	/**
	 * Informace o objednávkách zákazníků
	 * @column
	 */
	public bool $customerEmailNotification = false;
	
	/**
	 * @relationNxN
	 * @var \StORM\RelationCollection<\Security\DB\Account>|\Security\DB\Account[]
	 */
	public RelationCollection $accounts;
	
	protected ?Account $account = null;
	
	function getId()
	{
		return $this->getPK();
	}
	
	function getRoles(): array
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
}