<?php

declare(strict_types=1);

namespace Eshop\DB;

/**
 * Poplatky a daně
 * @table
 */
class Tax extends \StORM\Entity
{
	/**
	 * Název
	 * @column{"mutations":true}
	 */
	public ?string $name;

	/**
	 * Cena
	 * @column
	 */
	public ?float $price;

	/**
	 * Měna
	 * @relation
	 */
	public Currency $currency;
}
