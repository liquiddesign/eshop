<?php

declare(strict_types=1);

namespace Eshop\DB;

use Base\Entity\ShopSystemicEntity;
use StORM\RelationCollection;

/**
 * Země
 * @table
 * @index{"name":"country_unique_code","unique":true,"columns":["code","fk_shop"]}
 */
class Country extends ShopSystemicEntity
{
	/**
	 * Kód
	 * @column
	 */
	public ?string $code;
	
	/**
	 * Název
	 * @column
	 */
	public ?string $name;
	
	/**
	 * Formát kódu objednávky
	 * @column
	 */
	public string $orderCodeFormat = 'X%2$s%1$05d';

	/**
	 * Počáteční číslo kódu objednávky
	 * @column
	 */
	public int $orderCodeStartNumber = 1;
	
	/**
	 * Úroveň DPH pro dopravy
	 * @column{"type":"enum","length":"'standard','reduced-high','reduced-low','zero'"}
	 */
	public string $deliveryVatRate = 'standard';
	
	/**
	 * Úroveň DPH pro platby
	 * @column{"type":"enum","length":"'standard','reduced-high','reduced-low','zero'"}
	 */
	public string $paymentVatRate = 'standard';
	
	/**
	 * Výše DPH
	 * @relation
	 * @var \StORM\RelationCollection<\Eshop\DB\VatRate>
	 */
	public RelationCollection $vatRates;
}
