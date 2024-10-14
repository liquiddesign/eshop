<?php

namespace Eshop\DTO\Checkout;

use Eshop\DB\DeliveryType;

class DeliveryTypeWithPaymentTypes
{
	/**
	 * @param \Eshop\DB\DeliveryType $deliveryType
	 * @param array<\Eshop\DB\PaymentType> $paymentTypes
	 */
	public function __construct(private readonly DeliveryType $deliveryType, private readonly array $paymentTypes)
	{
	}

	public function getDeliveryType(): DeliveryType
	{
		return $this->deliveryType;
	}

	/**
	 * @return array<\Eshop\DB\PaymentType>
	 */
	public function getPaymentTypes(): array
	{
		return $this->paymentTypes;
	}
}
