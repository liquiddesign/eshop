<?php

declare(strict_types=1);

namespace Eshop\DB;

/**
 * Reklamace
 * @table
 */
class Complaint extends \StORM\Entity
{
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
	 * Zákazník
	 * @constraint
	 * @relation
	 */
	public Customer $customer;
	
	/**
	 * Položka objednávky
	 * @constraint
	 * @relation
	 */
	public CartItem $cartItem;
	
	/**
	 * Objednávka
	 * @constraint
	 * @relation
	 */
	public Order $order;
	
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
}
