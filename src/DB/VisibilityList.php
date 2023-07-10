<?php

namespace Eshop\DB;

use Base\Entity\ShopSystemicEntity;
use StORM\RelationCollection;

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
	 * @column
	 */
	public int $id;

	/**
	 * @relation
	 * @var \StORM\RelationCollection<\Eshop\DB\VisibilityListItem>
	 */
	public RelationCollection $visibilityListItems;
}
