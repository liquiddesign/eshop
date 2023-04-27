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
	 * @relation
	 * @var \StORM\RelationCollection<\Eshop\DB\VisibilityListItem>
	 */
	public RelationCollection $visibilityListItems;
}
