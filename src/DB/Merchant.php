<?php

declare(strict_types=1);

namespace Eshop\DB;

use Nette\Security\IIdentity;
use Security\DB\Account;
use Security\DB\IUser;

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
	 * @relation
	 * @constraint{"onUpdate":"SET NULL","onDelete":"SET NULL"}
	 */
	public ?Account $account;
	
	function getId()
	{
		return $this->getValue('account');
	}
	
	function getRoles(): array
	{
		return [];
	}
	
	public function getAccount(): ?Account
	{
		return $this->account;
	}
}