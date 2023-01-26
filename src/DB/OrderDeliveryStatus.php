<?php

declare(strict_types=1);

namespace Eshop\DB;

/**
 * Stav dopravy objednávky
 * @table
 * @index{"name":"orderdeliverystatus_unique_status","unique":true,"columns":["packageCode", "status", "service"]}
 */
class OrderDeliveryStatus extends \StORM\Entity
{
	public const SERVICE_DPD = 'dpd';
	public const SERVICE_PPL = 'ppl';

	/**
	 * Timestamp of status creation from service
	 * @column{"type":"timestamp"}
	 */
	public ?string $createdTs;

	/**
	 * @column{"type":"enum","length":"'dpd','ppl'"}
	 */
	public string $service;

	/**
	 * @column
	 */
	public string $status;

	/**
	 * @column
	 */
	public string $packageCode;

	/**
	 * @relation
	 * @constraint{"onUpdate":"CASCADE","onDelete":"CASCADE"}
	 */
	public Order $order;
}
