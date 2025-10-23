<?php

namespace Eshop\DB;

class CustomerRegion extends \StORM\Entity
{
	/**
	 * @column
	 */
	public string $name;

	/**
	 * @column
	 */
	public string $code;

	/**
	 * @column
	 */
	public int $priority = 10;

	/**
	 * @column
	 */
	public bool $hidden = false;
}
