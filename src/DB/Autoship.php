<?php

declare(strict_types=1);

namespace Eshop\DB;

/**
 * Automatická objednávka
 * @table
 */
class Autoship extends \StORM\Entity
{
	/**
	 * Id
	 * @column{"autoincrement":true}
	 */
	public int $id;
	
	/**
	 * Počet dní
	 * @column
	 */
	public int $dayInterval = 28;
	
	/**
	 * Od kdy je aktivní
	 * @column{"type":"date"}
	 */
	public string $activeFrom;
	
	/**
	 * Do kdy je aktivní
	 * @column{"type":"date"}
	 */
	public ?string $activeTo;
	
	/**
	 * Aktivní
	 * @column
	 */
	public bool $active;
	
	/**
	 * Vytvořen
	 * @column{"type":"timestamp","default":"CURRENT_TIMESTAMP"}
	 */
	public string $createdTs;
	
	/**
	 * Vytvoření poslední objednávky
	 * @column{"type":"timestamp"}
	 */
	public ?string $lastCreatedOrderTs;
	
	/**
	 * Čas poslední chyby
	 * @column{"type":"timestamp"}
	 */
	public ?string $lastErrorOrderTs;
	
	/**
	 * Popis poslední chyby
	 * @column{"type":"text"}
	 */
	public ?string $lastErrorOrderInfo;
	
	/**
	 * Nákup
	 * @relation
	 * @constraint{"onUpdate":"RESTRICT","onDelete":"RESTRICT"}
	 */
	public Purchase $purchase;
}
