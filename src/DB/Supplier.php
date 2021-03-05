<?php

declare(strict_types=1);

namespace Eshop\DB;

/**
 * Dodavatel
 * @table
 */
class Supplier extends \StORM\Entity
{
	/**
	 * Název
	 * @column
	 */
	public string $name;
	
	/**
	 * Email
	 * @column
	 */
	public ?string $email;
	
	/**
	 * Telefon
	 * @column
	 */
	public ?string $phone;
	
	/**
	 * Dodavatel na kterém je web závislý
	 * @column
	 */
	public bool $systemic = false;
	
	/**
	 * Priorita importu
	 * @column
	 */
	public int $importPriority = 0;
	
	/**
	 * Je importu aktivní
	 * @column
	 */
	public ?bool $isImportActive;
	
	/**
	 * Dodací adresa
	 * @relation
	 * @constraint
	 */
	public ?Address $deliveryAddress;
	
	/**
	 * Fakturační adresa
	 * @relation
	 * @constraint
	 */
	public ?Address $billAddress;
	
	public function isSystemic(): bool
	{
		return $this->systemic;
	}
}
