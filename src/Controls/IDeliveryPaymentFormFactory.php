<?php

declare(strict_types=1);

namespace Eshop\Controls;

interface IDeliveryPaymentFormFactory
{
	public function create(): DeliveryPaymentForm;
}