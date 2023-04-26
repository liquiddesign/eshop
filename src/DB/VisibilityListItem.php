<?php

namespace Eshop\DB;

use StORM\Entity;

/**
 * Stav dopravy objednávky
 * @table
 * @index{"name":"visibilitylistitem_product_unique","unique":true,"columns":["fk_product", "fk_visibilityList"]}
 */
class VisibilityListItem extends Entity
{
	/**
	 * Skryto
	 * @column
	 */
	public bool $hidden = false;

	/**
	 * Neprodejné
	 * @column
	 */
	public bool $unavailable = false;

	/**
	 * Skryto v menu a vyhledávání, dostupné přes URL
	 * @column
	 */
	public bool $hiddenInMenu = false;

	/**
	 * Doporučené
	 * @column
	 */
	public bool $recommended = false;

	/**
	 * Priorita produktu pro výpis
	 * @column
	 */
	public int $priority = 10;

	/**
	 * @relation
	 * @constraint{"onUpdate":"CASCADE","onDelete":"CASCADE"}
	 */
	public Product $product;

	/**
	 * @relation
	 * @constraint{"onUpdate":"CASCADE","onDelete":"CASCADE"}
	 */
	public VisibilityList $visibilityList;
}