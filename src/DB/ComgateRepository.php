<?php

namespace Eshop\DB;

use StORM\Repository;

class ComgateRepository extends Repository
{
	public function saveTransaction(string $transactionId, float $price, string $currency, string $status, Order $order, bool $test = false): void
	{
		$this->createOne([
			'transactionId' => $transactionId,
			'refId' => $order->getPK(),
			'price' => $price,
			'currency' => $currency,
			'status' => $status,
			'order' => $order->getPK(),
			'test' => $test,
		]);
	}
}
