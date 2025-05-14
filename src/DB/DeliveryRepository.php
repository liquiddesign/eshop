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

	public function updateDeliveryType(Delivery $delivery, DeliveryType $deliveryType): void
	{
		$mutations = \array_keys($this->getConnection()->getAvailableMutations());

		$typeNames = [];

		foreach ($mutations as $mutation) {
			$typeNames[$mutation] = $deliveryType->getValue('name', $mutation);
		}

		$delivery->update([
			'deliveryType' => $deliveryType->getPK(),
			'typeCode' => $deliveryType->code,
			'typeName' => $typeNames,
		]);
	}
}
