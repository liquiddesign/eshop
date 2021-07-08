<?php

namespace Eshop\DB;

use Eshop\DB\Order;
use StORM\Repository;

class ComgateRepository extends Repository
{
	public function saveTransaction(string $transactionId, float $price, string $currency, string $status, Order $order): void
	{
		$this->createOne([
			'transactionId' => $transactionId,
			'refId' => $order->getPK(),
			'price' => $price,
			'currency' => $currency,
			'status' => $status,
			'order' => $order->getPK(),
		]);
	}
}
