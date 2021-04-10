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
	 * Kód
	 * @column{"unique":true}
	 */
	public ?string $code;
	
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
	 * Priorita importu
	 * @column
	 */
	public int $importPriority = 0;
	
	/**
	 * Procentuální změna cen
	 * @column
	 */
	public int $importPriceRatio = 100;
	
	/**
	 * Je importu aktivní
	 * @column
	 */
	public ?bool $isImportActive;
}
