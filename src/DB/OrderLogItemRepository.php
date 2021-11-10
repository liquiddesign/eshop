<?php

declare(strict_types=1);

namespace Eshop\DB;

use Admin\DB\Administrator;

/**
 * @extends \StORM\Repository<\Eshop\DB\OrderLogItem>
 */
class OrderLogItemRepository extends \StORM\Repository
{
	public function createLog(Order $order, string $operation, ?Administrator $administrator = null): OrderLogItem
	{
		return $this->createOne([
			'order' => $order->getPK(),
			'operation' => $operation,
			'administrator' => $administrator,
			'administratorFullName' => $administrator ? $administrator->fullName : null,
		]);
	}
}
