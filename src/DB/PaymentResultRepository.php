<?php

namespace Eshop\DB;

use Nette\Utils\Arrays;
use StORM\Repository;

class PaymentResultRepository extends Repository
{
	public const ALLOWED_SERVICE_TYPES = [
		'comgate',
		'goPay',
	];

	public function saveTransaction(string $transactionId, float $price, string $currency, string $status, string $service, Order $order, bool $test = false): void
	{
		if (!Arrays::contains($this::ALLOWED_SERVICE_TYPES, $service)) {
			throw new \Exception("Service type '$service' is not allowed! Allowed: " . \implode(',', $this::ALLOWED_SERVICE_TYPES));
		}

		$this->createOne([
			'id' => $transactionId,
			'price' => $price,
			'currency' => $currency,
			'status' => $status,
			'order' => $order->getPK(),
			'test' => $test,
			'service' => $service,
		]);
	}
}
