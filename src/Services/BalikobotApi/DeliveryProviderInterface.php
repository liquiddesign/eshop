<?php

namespace Eshop\Services\BalikobotApi;

interface DeliveryProviderInterface
{
	public function orderReturnShipment(array $requestInfo): ReturnShipmentOrderedResponseInterface;
}
