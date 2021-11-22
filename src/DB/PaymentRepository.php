<?php

declare(strict_types=1);

namespace Eshop\DB;

/**
 * @extends \StORM\Repository<\Eshop\DB\Payment>
 */
class PaymentRepository extends \StORM\Repository
{
	public function getPaymentByOrder(string $orderId): ?Payment
	{
		return $this->many()->where('fk_order', $orderId)->first();
	}
}
