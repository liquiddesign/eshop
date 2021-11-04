<?php

declare(strict_types=1);

namespace Eshop\DB;

/**
 * Měna
 * @table
 */
class Currency extends \StORM\Entity
{
	/**
	 * Kód
	 * @unique
	 * @column
	 */
	public string $code;
	
	/**
	 * Název
	 * @column
	 */
	public ?string $name;
	
	/**
	 * Symbol
	 * @column
	 */
	public string $symbol;
	
	/**
	 * Formát, počet desetiných míst, pokud je null použije se automatika
	 * @column
	 */
	public ?int $formatDecimals = 2;
	
	/**
	 * Formát, oddělovač desetiných míst
	 * @column
	 */
	public ?string $formatDecimalSeparator = ',';
	
	/**
	 * Formát, oddělovač tisícovek
	 * @column
	 */
	public ?string $formatThousandsSeparator = ' ';
	
	/**
	 * Pozice symbolu
	 * @column{"type":"enum","length":"'after','before'"}
	 */
	public string $formatSymbolPosition = 'after';
	
	/**
	 * Kurz
	 * @column
	 */
	public ?float $convertRatio;
	
	/**
	 * Konverze změny
	 * @relation
	 * @constraint{"onUpdate":"SET NULL","onDelete":"SET NULL"}
	 */
	public ?Currency $convertCurrency;
	
	/**
	 * Počet desetiných míst pro zaokrouhlení při konverzi, výpočtu slev
	 * @column
	 */
	public int $calculationPrecision = 4;
	
	/**
	 * Nebudou fungovat ceníky a zvolí se přepočet
	 * @column
	 */
	public bool $enableConversion = false;

	/**
	 * Cashback měna
	 * @column
	 */
	public bool $cashback = false;
	
	public function isConversionEnabled(): bool
	{
		return $this->enableConversion && $this->convertCurrency && $this->convertRatio;
	}


}