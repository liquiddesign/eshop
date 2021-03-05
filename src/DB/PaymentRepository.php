<?php

declare(strict_types=1);

namespace Eshop\DB;

use StORM\Entity;

/**
 * @extends \StORM\Repository<\Eshop\DB\Payment>
 */
class PaymentRepository extends \StORM\Repository
{
	/**
	 * @param string $orderId
	 * @return \StORM\Entity<\Eshop\DB\Payment>
	 * @throws \StORM\Exception\NotFoundException
	 */
	public function getPaymentByOrder(string $orderId): Entity
	{
		return $this->many()->where('fk_order', $orderId)->first();
	}
}
