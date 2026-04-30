<?php

declare(strict_types=1);

namespace Eshop\DB;

use StORM\Entity;

/**
 * Customer bonita based on 12-month turnover aggregated by IC (or ckpIcOverride)
 * @table
 * @index{"name":"customer_bonita_ckp_ic","unique":true,"columns":["ckpIc"]}
 */
class CustomerBonita extends Entity
{
	/**
	 * IC identifier (ckpIcOverride if set, otherwise bare ic)
	 * @column
	 */
	public string $ckpIc;

	/**
	 * 12-month turnover without VAT
	 * @column
	 */
	public float $turnover12m;

	/**
	 * Percentage of total turnover
	 * @column
	 */
	public float $turnoverPct;

	/**
	 * Cumulative percentage (for bonita calculation)
	 * @column
	 */
	public float $cumulativePct;

	/**
	 * Bonita category: A, B, or C
	 * @column{"type":"enum","length":"'A','B','C'"}
	 */
	public string $bonita;

	/**
	 * Calculation timestamp
	 * @column{"type":"timestamp","default":"CURRENT_TIMESTAMP"}
	 */
	public string $calculatedTs;
}
