<?php

namespace Eshop\Services\BalikobotApi\Responses;

use Eshop\Services\BalikobotApi\ReturnShipmentOrderedResponseInterface;

class PPLReturnShipmentResponse implements ReturnShipmentOrderedResponseInterface
{
	/**
	 * Unique ID of the order within the carrier in the API
	 */
	private int $packageId;

	/**
	 * Carrier's consignment number (only for service 50 - PPL Return Connect)
	 */
	private ?string $carrierId;

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

	public function __construct(int $packageId, ?string $carrierId, ?string $statusMessage, int $status)
	{
		$this->packageId = $packageId;
		$this->carrierId = $carrierId;
		$this->statusMessage = $statusMessage;
		$this->status = $status;
	}

	public function getPackageId(): int
	{
		return $this->packageId;
	}

	public function getCarrierId(): ?string
	{
		return $this->carrierId;
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
