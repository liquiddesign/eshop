<?php

declare(strict_types=1);

namespace Eshop\DB;

/**
 * Otevírací doba
 * @table
 * @index{"name":"openinghours_unique","unique":true,"columns":["day","fk_pickupPoint","date"]}
 */
class OpeningHours extends \StORM\Entity
{
	/**
	 * Den v týdnu
	 * @column
	 */
	public ?int $day;

	/**
	 * Otevřeno od
	 * @column{"type":"time"}
	 */
	public ?string $openFrom;

	/**
	 * Otevřeno do
	 * @column{"type":"time"}
	 */
	public ?string $openTo;

	/**
	 * Pauza od
	 * @column{"type":"time"}
	 */
	public ?string $pauseFrom;

	/**
	 * Pauza do
	 * @column{"type":"time"}
	 */
	public ?string $pauseTo;

	/**
	 * Datum, v případě mimořádné otevírací doby
	 * @column{"type":"date"}
	 */
	public ?string $date;

	/**
	 * Výdejní místo
	 * @relation
	 * @constraint{"onUpdate":"CASCADE","onDelete":"CASCADE"}
	 */
	public PickupPoint $pickupPoint;
}
