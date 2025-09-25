<?php

namespace Eshop\Services\Delivery;

use Base\Bridges\AutoWireService;
use Eshop\DB\DeliveryTypeThresholdRepository;

readonly class DeliveryThresholdService implements AutoWireService
{
	public function __construct(private DeliveryTypeThresholdRepository $deliveryTypeThresholdRepository)
	{
	}
}
