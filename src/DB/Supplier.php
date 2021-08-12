<?php

declare(strict_types=1);

namespace Eshop\DB;

/**
 * Dodavatel
 * @table
 */
class Supplier extends \StORM\Entity
{
	/**
	 * Kód
	 * @column{"unique":true}
	 */
	public ?string $code;
	
	/**
	 * Prefix kódu produktu
	 * @column{"unique":true}
	 */
	public ?string $productCodePrefix;
	
	/**
	 * Název
	 * @column
	 */
	public string $name;
	
	/**
	 * Třída importu
	 * @column
	 */
	public string $providerClass;
	
	/**
	 * Priorita importu
	 * @column
	 */
	public int $importPriority = 0;
	
	/**
	 * Procentuální změna cen
	 * @column
	 */
	public int $importPriceRatio = 100;

	/**
	 * Import obrázků
	 * @column
	 */
	public bool $importImages = true;
	
	/**
	 * Aktualizován
	 * @column{"type":"timestamp","default":"CURRENT_TIMESTAMP"}
	 */
	public string $updatedTs;
	
	/**
	 * Poslední import
	 * @column{"type":"timestamp"}
	 */
	public ?string $lastImportTs;
	
	/**
	 * Poslední update z importu
	 * @column{"type":"timestamp"}
	 */
	public ?string $lastUpdateTs;
	
	/**
	 * Rozdělí při importu ceníky na dostupné a nedostupné
	 * @column
	 */
	public bool $splitPricelists = true;
	
	/**
	 * Defaultně skryté produkty
	 * @column
	 */
	public bool $defaultHiddenProduct = true;
	
	/**
	 * Defaultní zobrazení množství
	 * @relation
	 * @constraint{"onUpdate":"CASCADE","onDelete":"SET NULL"}
	 */
	public ?DisplayAmount $defaultDisplayAmount;
	
	/**
	 * Defaultní zobrazení doručení
	 * @relation
	 * @constraint{"onUpdate":"CASCADE","onDelete":"SET NULL"}
	 */
	public ?DisplayDelivery $defaultDisplayDelivery;
}
