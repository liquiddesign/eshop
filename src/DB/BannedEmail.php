<?php

declare(strict_types=1);

namespace Eshop\DB;

/**
 * Zablokovaný email
 * @table
 * @index{"name":"banned_email_unique","unique":true,"columns":["email"]}
 */
class BannedEmail extends \StORM\Entity
{
	/**
	 * Email
	 * @column
	 */
	public string $email;

	/**
	 * Vytvořen
	 * @column{"type":"timestamp","default":"CURRENT_TIMESTAMP"}
	 */
	public string $createdTs;
}
