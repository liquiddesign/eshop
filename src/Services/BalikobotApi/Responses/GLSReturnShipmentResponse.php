<?php

namespace Eshop\Services\BalikobotApi\Responses;

use Eshop\Services\BalikobotApi\ReturnShipmentOrderedResponseInterface;

class GLSReturnShipmentResponse implements ReturnShipmentOrderedResponseInterface
{
	/**
	 * Unique ID of the order within the carrier in the API.
	 * @var int
	 */
	private int $packageId;

	/**
	 * Order ID within the carrier, used for possible track&trace (TRACK method).
	 * @var ?string
	 */
	private ?string $carrierId;

	/**
	 * URL for shipping tracking.
	 * @var ?string
	 */
	private ?string $trackUrl;

	/**
	 * Text representation of the status standard output.
	 * @var string
	 */
	private string $statusMessage;

	/**
	 * Expression of how the operation turned out
	 * possible values:
	 * 200 – OK,
	 * 208 – transport under the sent eid has already been ordered before,
	 * 400 – Data validation error,
	 * 503 – Order creation error
	 * @var string
	 */
	private int $status;

	public function __construct(int $packageId, ?string $carrierId, ?string $trackUrl, string $statusMessage, int $status)
	{
		$this->packageId = $packageId;
		$this->carrierId = $carrierId;
		$this->trackUrl = $trackUrl;
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

	public function getTrackUrl(): ?string
	{
		return $this->trackUrl;
	}

	public function getStatusMessage(): string
	{
		return $this->statusMessage;
	}

	public function getStatus(): int
	{
		return $this->status;
	}
}