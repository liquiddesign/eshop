<?php

namespace Eshop\Services\BalikobotApi;

interface DeliveryProviderInterface
{
	function orderReturnShipment(array $requestInfo): ReturnShipmentOrderedResponseInterface;
}