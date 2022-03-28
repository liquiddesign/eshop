<?php

declare(strict_types=1);

namespace Eshop\DB;

use Security\DB\Account;
use StORM\RelationCollection;

/**
 * Newsletter user
 * @table
 * @index{"name":"newsletteruser_unique_email","unique":true,"columns":["email"]}
 */
class NewsletterUser extends \StORM\Entity
{
	/**
	 * Email
	 * @column
	 * @unique
	 */
	public string $email;

	/**
	 * Created timestamp
	 * @column{"type":"timestamp","default":"CURRENT_TIMESTAMP"}
	 */
	public string $createdTs;

	/**
	 * Skupiny
	 * @relationNxN
	 * @var \StORM\RelationCollection<\Eshop\DB\NewsletterUserGroup>|\Eshop\DB\NewsletterUserGroup[]
	 */
	public RelationCollection $groups;
	
	/**
	 * Uživatel
	 * @relation
	 * @constraint{"onUpdate":"CASCADE","onDelete":"CASCADE"}
	 */
	public ?Account $customerAccount;
}
