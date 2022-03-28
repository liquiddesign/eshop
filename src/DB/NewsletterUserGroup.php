<?php

declare(strict_types=1);

namespace Eshop\DB;

/**
 * Newsletter user group
 * @table
 */
class NewsletterUserGroup extends \StORM\Entity
{
	/**
	 * Name
	 * @column
	 */
	public string $name;
}
