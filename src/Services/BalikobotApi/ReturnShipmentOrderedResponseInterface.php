<?php

namespace Eshop\Services\BalikobotApi;

interface ReturnShipmentOrderedResponseInterface
{
	public function getPackageId(): string;

	public function getStatusMessage(): ?string;

	public function getStatus(): int;
}
