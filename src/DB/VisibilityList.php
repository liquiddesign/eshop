<?php

namespace Eshop\DB;

use Base\Entity\ShopSystemicEntity;
use StORM\RelationCollection;

/**
 * Seznam viditelnost
 * @table
 * @index{"name":"visibilitylist_priority","unique":false,"columns":["priority"]}
 */
class VisibilityList extends ShopSystemicEntity
{
	/**
	 * Název
	 * @column
	 */
	public string $name;

	/**
	 * Kód
	 * @column{"unique":true}
	 */
	public string $code;

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
	 * ID
	 * column - don't created by auto migration, only by manual
	 */
	public int $id;

	/**
	 * @relation
	 * @var \StORM\RelationCollection<\Eshop\DB\VisibilityListItem>
	 */
	public RelationCollection $visibilityListItems;
}
