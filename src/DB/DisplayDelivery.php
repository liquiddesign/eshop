<?php

declare(strict_types=1);

namespace Eshop\DB;

/**
 * Zobrazení doručení
 * @table
 */
class DisplayDelivery extends \StORM\Entity
{
	/**
	 * Popisek
	 * @column{"mutations":true}
	 */
	public ?string $label;

	/**
	 * Casovy prah
	 * @column
	 */
	public ?string $timeThreshold;

	/**
	 * Popisek pred casovym prahem
	 * @column{"mutations":true}
	 */
	public ?string $beforeTimeThresholdLabel;

	/**
	 * Popisek po casovem prahu
	 * @column{"mutations":true}
	 */
	public ?string $afterTimeThresholdLabel;

	/**
	 * Priorita
	 * @column
	 */
	public int $priority = 10;
}