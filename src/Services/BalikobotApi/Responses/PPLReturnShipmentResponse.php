<?php

namespace Eshop\Services\BalikobotApi\Responses;

use Eshop\Services\BalikobotApi\ReturnShipmentOrderedResponseInterface;

class PPLReturnShipmentResponse implements ReturnShipmentOrderedResponseInterface
{
	/**
	 * Unique ID of the order within the carrier in the API
	 */
	private string $packageId;

	/**
	 * Text representation of the status standard output.
	 */
	private ?string $statusMessage;

	/**
	 * Expression of how the operation turned out
	 * possible values:
	 * 200 – OK,
	 * 208 – transport under the sent eid has already been ordered before,
	 * 400 – Data validation error,
	 * 503 – Order creation error
	 */
	private int $status;

	public function __construct(string $packageId, ?string $statusMessage, int $status)
	{
		$this->packageId = $packageId;
		$this->statusMessage = $statusMessage;
		$this->status = $status;
	}

	public function getPackageId(): string
	{
		return $this->packageId;
	}

	public function getStatusMessage(): ?string
	{
		return $this->statusMessage;
	}

	public function getStatus(): int
	{
		return $this->status;
	}
}
