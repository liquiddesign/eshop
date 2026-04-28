<?php

declare(strict_types=1);

namespace Eshop\DB;

use StORM\Entity;

/**
 * Množstevní (tier) cena položky nabídky
 * @table{"name":"eshop_offeritemquantityprice"}
 * @index{"name":"offeritemquantityprice_validfrom_unique","unique":true,"columns":["fk_offerItem","validFrom"]}
 */
class OfferItemQuantityPrice extends Entity
{
	/**
	 * Od jakého množství cena platí (vždy >= 2; 1 ks pokrývá hlavní OfferItem.price)
	 * @column
	 */
	public int $validFrom;

	/**
	 * Cena bez DPH
	 * @column
	 */
	public float $price;

	/**
	 * Cena s DPH
	 * @column
	 */
	public float $priceVat;

	/**
	 * Datum vytvoření
	 * @column{"type":"timestamp","default":"CURRENT_TIMESTAMP"}
	 */
	public string $createdTs;

	/**
	 * Datum poslední úpravy
	 * @column{"type":"timestamp","default":"CURRENT_TIMESTAMP"}
	 */
	public string $updatedTs;

	/**
	 * Položka nabídky
	 * @relation
	 * @constraint{"onUpdate":"CASCADE","onDelete":"CASCADE"}
	 */
	public OfferItem $offerItem;
}
