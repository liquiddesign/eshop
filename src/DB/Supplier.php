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
	 * URL zdroje
	 * @column
	 */
	public ?string $url;
	
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
	 * Povolit párování produktů pomocí Algolia
	 * @column
	 */
	public bool $pairWithAlgolia = false;

	/**
	 * Zobrazit kód na eshopu s prefixem
	 * @column
	 */
	public bool $showCodeWithPrefix = true;

	/**
	 * Povoluje zařazení do katalogu
	 * @column
	 */
	public bool $syncProductsAllowed = true;

	/**
	 * Zvýraznit dostupnost produktu od tohoto dodavatele
	 * @column
	 */
	public bool $highlightStoreAmount = false;
	
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
