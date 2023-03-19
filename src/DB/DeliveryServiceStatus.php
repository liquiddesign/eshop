<?php

declare(strict_types=1);

namespace Eshop\DB;

/**
 * Stav dopravy objednávky
 * @table
 * @index{"name":"deliveryservicestatus_unique_status","unique":true,"columns":["status", "service"]}
 */
class DeliveryServiceStatus extends \StORM\Entity
{
	public const SERVICE_DPD = 'dpd';
	public const SERVICE_PPL = 'ppl';

	/**
	 * @column{"type":"enum","length":"'dpd','ppl'"}
	 */
	public string $service;

	/**
	 * @column
	 */
	public string $status;

	/**
	 * @column{"mutations":true}
	 */
	public ?string $text;
}
