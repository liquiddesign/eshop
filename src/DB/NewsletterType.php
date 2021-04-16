<?php

declare(strict_types=1);

namespace Eshop\DB;

/**
 * Adresa
 * @table
 */
class NewsletterType extends \StORM\Entity
{
	/**
	 * Popis
	 * @column
	 */
	public ?string $description;

	/**
	 * Priorita
	 * @column
	 */
	public int $priority = 10;

	/**
	 * Skryto
	 * @column
	 */
	public bool $hidden = false;

	/**
	 * Systemový
	 * @column
	 */
	public bool $systemic = false;
}