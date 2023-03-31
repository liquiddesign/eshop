<?php

declare(strict_types=1);

namespace Eshop\DB;

/**
 * Reklamace
 * @table
 * @index{"name":"complaint_code_unique","unique":true,"columns":["code"]}
 */
class Complaint extends \StORM\Entity
{
	/**
	 * @column
	 */
	public string $code;

	/**
	 * Důvod reklamace
	 * @column{"type":"text"}
	 */
	public ?string $reason;
	
	/**
	 * Poznámka
	 * @column{"type":"text"}
	 */
	public ?string $note;

	/**
	 * Číslo účtu
	 * @column
	 */
	public ?string $customerBankAccountNumber;

	/**
	 * @column
	 */
	public string $customerEmail;

	/**
	 * @column
	 */
	public string $customerFullName;

	/**
	 * @column
	 */
	public string $customerPhone;

	/**
	 * @column
	 */
	public string $orderCode;
	
	/**
	 * Zákazník
	 * @constraint{"onUpdate":"CASCADE","onDelete":"SET NULL"}
	 * @relation
	 */
	public ?Customer $customer;
	
	/**
	 * Položka objednávky
	 * @constraint{"onUpdate":"CASCADE","onDelete":"SET NULL"}
	 * @relation
	 */
	public ?CartItem $cartItem;
	
	/**
	 * Objednávka
	 * @constraint{"onUpdate":"CASCADE","onDelete":"SET NULL"}
	 * @relation
	 */
	public ?Order $order;

	/**
	 * Stav
	 * @constraint{"onUpdate":"CASCADE","onDelete":"CASCADE"}
	 * @relation
	 */
	public ComplaintState $complaintState;

	/**
	 * Typ
	 * @constraint{"onUpdate":"CASCADE","onDelete":"CASCADE"}
	 * @relation
	 */
	public ComplaintType $complaintType;
	
	/**
	 * Fotografie produkt
	 * @column
	 */
	public ?string $productPhotoFileName;
	
	/**
	 * Fotografie účtenka
	 * @column
	 */
	public ?string $documentPhotoFileName;
	
	/**
	 * Vytvořen
	 * @column{"type":"timestamp","default":"CURRENT_TIMESTAMP"}
	 */
	public string $createdTs;

	/**
	 * @return array<string|float|int|null>
	 */
	public function getEmailVariables(): array
	{
		return [
			'code' => $this->code,
			'note' => $this->note,
			'customerFullName' => $this->customer ? $this->customer->fullname : $this->customerFullName,
			'customerEmail' => $this->customer ? $this->customer->email : $this->customerEmail,
			'customerPhone' => $this->customer ? $this->customer->phone : $this->customerPhone,
			'createdTs' => $this->createdTs,
			'orderCode' => $this->order ? $this->order->code : $this->orderCode,
		];
	}
}
