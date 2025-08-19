<?php

namespace Eshop\Services\BalikobotApi;

interface DeliveryProviderInterface
{
	public function orderReturnShipment(PackageInfo $packageInfo): ReturnShipmentOrderedResponseInterface;

	public function getNoteCharacterLimit(): int;
}
