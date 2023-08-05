<?php

declare(strict_types=1);

namespace Eshop\DB;

/**
 * Adresa
 * @table
 */
class Address extends \StORM\Entity
{
	/**
	 * Název
	 * @column
	 */
	public ?string $name = null;

	/**
	 * Název firmy
	 * @column
	 */
	public ?string $companyName = null;

	/**
	 * Poznámka
	 * @column{"type":"text"}
	 */
	public ?string $note = null;

	/**
	 * Ulice
	 * @column
	 */
	public string $street;

	/**
	 * Město
	 * @column
	 */
	public string $city;

	/**
	 * PSČ
	 * @column
	 */
	public ?string $zipcode;

	/**
	 * Stát
	 * @column
	 */
	public ?string $state;

	/**
	 * ID
	 * column - don't created by auto migration, only by manual
	 */
	public int $id;

	/**
	 * Externí kód
	 * @column
	 */
	public ?string $externalCode;
	
	/**
	 * Externí ID
	 * @column
	 */
	public ?string $externalId;

	public function getFullAddress(): string
	{
		return "$this->street, $this->zipcode $this->city";
	}
}
