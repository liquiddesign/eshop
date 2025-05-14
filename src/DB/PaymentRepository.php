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

	public function updatePaymentType(Payment $payment, PaymentType $paymentType): void
	{
		$mutations = \array_keys($this->getConnection()->getAvailableMutations());

		$typeNames = [];

		foreach ($mutations as $mutation) {
			$typeNames[$mutation] = $paymentType->getValue('name', $mutation);
		}

		$payment->update([
			'paymentType' => $paymentType->getPK(),
			'typeCode' => $paymentType->code,
			'typeName' => $typeNames,
		]);
	}
}
