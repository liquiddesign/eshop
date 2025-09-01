<?php

declare(strict_types=1);

namespace Eshop\Services\BalikobotApi;

use Carbon\Carbon;

class PackageInfo
{
	// Common required identifiers
	// eid for both providers
	private string $id;

	// rec_name
	private string $recipientName;

	// rec_firm
	private ?string $recipientCompany;

	// rec_phone
	private string $recipientPhone;

	// rec_email
	private string $recipientEmail;

	// Unified address field
	// Can contain street + house number, split later if needed
	private string $streetAddress;

	// rec_city
	private string $city;

	// rec_zip
	private string $zipCode;

	// rec_country
	private string $countryCode;

	// Shipment meta
	// note to carrier WARNING GLS has only 64 characters allowed
	private ?string $note;

	// pickup_date (YYYY-MM-DD)
	private ?Carbon $pickupDate;

	// Optional PPL extras
	// PPL only
	private ?int $piecesCount;

	public function __construct(
		string $id,
		string $recipientName,
		?string $recipientCompany,
		string $recipientPhone,
		string $recipientEmail,
		string $streetAddress,
		string $city,
		string $zipCode,
		string $countryCode,
		?string $note = null,
		?Carbon $pickupDate = null,
		?int $piecesCount = null,
	) {
		$this->id = $id;
		$this->recipientName = $recipientName;
		$this->recipientCompany = $recipientCompany;
		$this->recipientPhone = $recipientPhone;
		$this->recipientEmail = $recipientEmail;
		$this->streetAddress = $streetAddress;
		$this->city = $city;
		$this->zipCode = $zipCode;
		$this->countryCode = $countryCode;
		$this->note = $note;
		$this->pickupDate = $pickupDate;
		$this->piecesCount = $piecesCount;
	}

	public function getId(): string
	{
		return $this->id;
	}

	public function getRecipientName(): string
	{
		return $this->recipientName;
	}

	public function getRecipientCompany(): ?string
	{
		return $this->recipientCompany;
	}

	public function getRecipientPhone(): string
	{
		return $this->recipientPhone;
	}

	public function getRecipientEmail(): string
	{
		return $this->recipientEmail;
	}

	public function getStreetAddress(): string
	{
		return $this->streetAddress;
	}

	public function getCity(): string
	{
		return $this->city;
	}

	public function getZipCode(): string
	{
		return $this->zipCode;
	}

	public function getCountryCode(): string
	{
		return $this->countryCode;
	}

	public function getNote(): ?string
	{
		return $this->note;
	}

	public function getPickupDate(): ?Carbon
	{
		return $this->pickupDate;
	}

	public function getPiecesCount(): ?int
	{
		return $this->piecesCount;
	}
}
