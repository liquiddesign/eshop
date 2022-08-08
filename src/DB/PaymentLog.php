<?php

declare(strict_types=1);

namespace Eshop\DB;

/**
 * Log platba
 * @table
 */
class PaymentLog extends \StORM\Entity
{
	/**
	 * Vytvořeno
	 * @column{"type":"datetime"}
	 */
	public string $created;
	
	/**
	 * Výřešeno
	 * @column{"type":"datetime"}
	 */
	public ?string $solved;
	
	/**
	 * Externí ID
	 * @column
	 */
	public ?string $externalId;
	
	/**
	 * Externí code
	 * @column
	 */
	public string $externalCode;
	
	/**
	 * Částka
	 * @column
	 */
	public float $amount;
	
	/**
	 * Protiúčet
	 * @column
	 */
	public ?string $countermeasure;
	
	/**
	 * Poznámka
	 * @column
	 */
	public ?string $note;
	
	/**
	 * Měna
	 * @relation
	 * @constraint
	 */
	public Currency $currency;
	
	/**
	 * Typ platby
	 * @constraint{"onUpdate":"CASCADE","onDelete":"CASCADE"}
	 * @relation
	 */
	public ?PaymentType $type;
	
	/**
	 * Spárovaná platba
	 * @constraint{"onUpdate":"CASCADE","onDelete":"CASCADE"}
	 * @relation
	 */
	public ?Payment $payment;
}
