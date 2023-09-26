<?php

namespace Eshop\Services\DPD;

class DeclaredSender
{
	protected string $name1;

	protected string $name2;

	protected string $street;

	protected string $postal;

	protected string $city;

	protected string $country;

	protected string $contact;

	protected string $phone;

	protected string $email;

	protected ?string $houseNo;

	public function __construct(string $name1, string $name2, string $street, string $postal, string $city, string $country, string $contact, string $phone, string $email, ?string $houseNo = null)
	{
		$this->name1 = $name1;
		$this->name2 = $name2;
		$this->street = $street;
		$this->postal = $postal;
		$this->city = $city;
		$this->country = $country;
		$this->contact = $contact;
		$this->phone = $phone;
		$this->email = $email;
		$this->houseNo = $houseNo;
	}

	/**
	 * @return array<mixed>
	 */
	public function getShipmentArray(): array
	{
		$array = [
			'SNAME1_DECLARED' => $this->name1,
			'SNAME2_DECLARED' => $this->name2,
			'SSTREET_DECLARED' => $this->street,
			'SPOSTAL_DECLARED' => $this->postal,
			'SCITY_DECLARED' => $this->city,
			'SCOUNTRY_DECLARED' => $this->country,
			'SCONTACT_DECLARED' => $this->contact,
			'SPHONE_DECLARED' => $this->phone,
			'SEMAIL_DECLARED' => $this->email,
		];

		if ($this->houseNo) {
			$array['SHOUSENO_DECLARED'] = $this->houseNo;
		}

		return $array;
	}
}
