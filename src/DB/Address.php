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
	public ?string $name;

	/**
	 * Poznámka
	 * @column{"type":"text"}
	 */
	public ?string $note;

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

	public function getFullAddress(): string
	{
		return "$this->street, $this->zipcode $this->city";
	}
}