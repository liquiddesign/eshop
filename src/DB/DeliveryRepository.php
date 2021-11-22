<?php

declare(strict_types=1);

namespace Eshop\DB;

/**
 * @extends \StORM\Repository<\Eshop\DB\Delivery>
 */
class DeliveryRepository extends \StORM\Repository
{
	public function getDeliveryByOrder(string $orderId): Delivery
	{
		return $this->many()->where('fk_order', $orderId)->first();
	}
}
