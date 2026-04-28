<?php

declare(strict_types=1);

namespace Eshop\DB;

use StORM\Entity;

/**
 * Mapování PSČ → kraj (1:1).
 * @table
 * @index{"name":"deliveryregionzip_zipcode","unique":true,"columns":["zipcode"]}
 */
class DeliveryRegionZip extends Entity
{
	/**
	 * PSČ bez mezer (5 číslic)
	 * @column
	 */
	public string $zipcode;

	/**
	 * Přiřazený kraj
	 * @relation
	 * @constraint{"onUpdate":"CASCADE","onDelete":"CASCADE"}
	 */
	public DeliveryRegion $region;

	/**
	 * Název obce z importního CSV (jen pro orientaci v adminu)
	 * @column
	 */
	public string|null $municipality = null;

	/**
	 * Název okresu z importního CSV (jen pro orientaci v adminu)
	 * @column
	 */
	public string|null $district = null;
}
