<?php

declare(strict_types=1);

namespace Eshop\DB;

use StORM\RelationCollection;

/**
 * Faktury
 * @table
 * @index{"name":"invoice_codehash","unique":true,"columns":["code", "hash"]}
 */
class Invoice extends \StORM\Entity
{
	/**
	 * Id
	 * @column{"autoincrement":true}
	 */
	public int $id;

	/**
	 * Kód
	 * @column
	 */
	public ?string $code;

	/**
	 * Vygenerovaný hash pro externí přístup
	 * @column
	 */
	public ?string $hash;

	/**
	 * Subject
	 * @column
	 */
	public ?string $subject;

	/**
	 * IČ
	 * @column
	 */
	public ?string $ic;

	/**
	 * DIČ
	 * @column
	 */
	public ?string $dic;

	/**
	 * Vystaveno
	 * @column{"type":"date"}
	 */
	public string $exposed;

	/**
	 * Datum zdanitelného plnění
	 * @column{"type":"date"}
	 */
	public ?string $taxDate = null;

	/**
	 * Datum splatnosti
	 * @column{"type":"date"}
	 */
	public ?string $dueDate = null;

	/**
	 * Zaplaceno
	 * @column{"type":"date"}
	 */
	public ?string $paidDate = null;

	/**
	 * Stornovano
	 * @column{"type":"date"}
	 */
	public ?string $canceled = null;

	/**
	 * Cena k úhradě
	 * @column
	 */
	public float $totalPrice;

	/**
	 * Celková cena s daní
	 * @column
	 */
	public float $totalPriceVat;

	/**
	 * Cena k úhradě
	 * @column
	 */
	public float $totalPriceWithoutDiscount;

	/**
	 * Celková cena s daní
	 * @column
	 */
	public float $totalPriceVatWithoutDiscount;

	/**
	 * Uhrazeno částka
	 * @column
	 */
	public ?float $paid;

	/**
	 * Měna
	 * @constraint{"onUpdate":"RESTRICT","onDelete":"RESTRICT"}
	 * @relation
	 */
	public Currency $currency;

	/**
	 * Adresa
	 * @constraint{"onUpdate":"RESTRICT","onDelete":"RESTRICT"}
	 * @relation
	 */
	public Address $address;

	/**
	 * @constraint{"onUpdate":"SET NULL","onDelete":"SET NULL"}
	 * @relation
	 */
	public ?Customer $customer;

	/**
	 * Vybraná platba
	 * @relation
	 * @constraint{"onUpdate":"CASCADE","onDelete":"SET NULL"}
	 */
	public ?PaymentType $paymentType;

	/**
	 * Objednávky
	 * @relationNxN{"sourceViaKey":"fk_invoice","targetViaKey":"fk_order"}
	 * @var \StORM\RelationCollection<\Eshop\DB\Order>
	 */
	public RelationCollection $orders;

	/**
	 * Položky
	 * @relation
	 * @var \StORM\RelationCollection<\Eshop\DB\InvoiceItem>
	 */
	public RelationCollection $items;

	/**
	 * @return array<mixed>
	 */
	public function getEmailVariables(): array
	{
		return [
			'code' => $this->code,
		];
	}
}
