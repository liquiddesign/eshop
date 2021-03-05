<?php

declare(strict_types=1);

namespace Eshop\DB;

use StORM\Entity;

/**
 * @extends \StORM\Repository<\Eshop\DB\Delivery>
 */
class DeliveryRepository extends \StORM\Repository
{
	/**
	 * @param string $orderId
	 * @return \StORM\Entity<\Eshop\DB\Delivery>
	 * @throws \StORM\Exception\NotFoundException
	 */
	public function getDeliveryByOrder(string $orderId): Entity
	{
		return $this->many()->where('fk_order', $orderId)->first();
	}
}
