<?php

declare(strict_types=1);

namespace Eshop\DB;

use StORM\Entity;

/**
 * Registr IČO firem pro přepis fakturační adresy u NP objednávek
 * @table
 */
class NpBillingOverride extends Entity
{
	/**
	 * IČO firmy
	 * @column{"unique":true}
	 */
	public string $ic;

	/**
	 * Poznámka
	 * @column{"type":"text"}
	 */
	public string|null $note = null;

	/**
	 * @column{"type":"timestamp","default":"CURRENT_TIMESTAMP"}
	 */
	public string $createdTs;
}
