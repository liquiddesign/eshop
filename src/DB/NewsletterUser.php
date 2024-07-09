<?php

declare(strict_types=1);

namespace Eshop\DB;

use Base\Entity\ShopEntity;
use Security\DB\Account;
use StORM\RelationCollection;

/**
 * Newsletter user
 * @table
 * @index{"name":"newsletteruser_unique_email","unique":true,"columns":["email"]}
 * @index{"name":"newsletteruser_unique_customerAccount","unique":true,"columns":["fk_customerAccount"]}
 * @index{"name":"newsletteruser_unique_email_customerAccount","unique":true,"columns":["email", "fk_customerAccount", "fk_shop"]}
 */
class NewsletterUser extends ShopEntity
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
	 * @var \StORM\RelationCollection<\Eshop\DB\NewsletterUserGroup>
	 */
	public RelationCollection $groups;
	
	/**
	 * Uživatel
	 * @relation
	 * @constraint{"onUpdate":"CASCADE","onDelete":"CASCADE"}
	 */
	public ?Account $customerAccount;
}
