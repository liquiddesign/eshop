<?php

declare(strict_types=1);

namespace Eshop\DB;

use StORM\Entity;

/**
 * Kraj (geografická oblast). Globální — sdílený napříč shopy.
 * @table
 * @index{"name":"deliveryregion_code","unique":true,"columns":["code"]}
 */
class DeliveryRegion extends Entity
{
	/**
	 * Strojový kód (např. praha, jihocesky, severni-morava)
	 * @column
	 */
	public string $code;

	/**
	 * Lidský název (např. „Praha", „Jihočeský", „Severní Morava")
	 * @column
	 */
	public string $name;

	/**
	 * Priorita pro řazení v adminu
	 * @column
	 */
	public int $priority = 10;
}
