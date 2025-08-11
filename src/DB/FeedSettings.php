<?php

declare(strict_types=1);

namespace Eshop\DB;

/**
 * Nastavení feedu
 * @table
 */
class FeedSettings extends \StORM\Entity
{
	/**
	 * ID zákazníka
	 * @column
	 */
	public string $customerId;

	/**
	 * ID
	 * @column{"type":"enum","length":"'Ano','Ne'"}
	 */
	public string $id = 'Ne';

	/**
	 * SKU
	 * @column{"type":"enum","length":"'Ano','Ne'"}
	 */
	public string $sku = 'Ano';

	/**
	 * EAN
	 * @column{"type":"enum","length":"'Ano','Ne'"}
	 */
	public string $ean = 'Ano';

	/**
	 * ID skup. zbo
	 * @column{"type":"enum","length":"'Ano','Ne'"}
	 */
	public string $skupZboId = 'Ne';

	/**
	 * Skup. zbo
	 * @column{"type":"enum","length":"'Ano','Ne'"}
	 */
	public string $skupZboName = 'Ne';

	/**
	 * Hmotnost
	 * @column{"type":"enum","length":"'Ano','Ne'"}
	 */
	public string $pckgWeight = 'Ne';

	/**
	 * Výška
	 * @column{"type":"enum","length":"'Ano','Ne'"}
	 */
	public string $pckgHeight = 'Ne';

	/**
	 * šířka
	 * @column{"type":"enum","length":"'Ano','Ne'"}
	 */
	public string $pckgWidth = 'Ne';

	/**
	 * Hloubka
	 * @column{"type":"enum","length":"'Ano','Ne'"}
	 */
	public string $pckgDepth = 'Ne';

	/**
	 * Množství skladem
	 * @column{"type":"enum","length":"'Ano','Ne'"}
	 */
	public string $qtyFree = 'Ano';

	/**
	 * Termín dodávky
	 * @column{"type":"enum","length":"'Ano','Ne'"}
	 */
	public string $stockOnRoad = 'Ne';

	/**
	 * Vaše nákupní cena bez DPH
	 * @column{"type":"enum","length":"'Ano, zaokrouhleno na celé čísla', 'Ano, zaokrouhleno na dvě desetiny', 'Ne'"}
	 */
	public string $priceExclVat = 'Ano, zaokrouhleno na celé čísla';

	/**
	 * Vaše nákupní cena s DPH
	 * @column{"type":"enum","length":"'Ano, zaokrouhleno na celé čísla', 'Ano, zaokrouhleno na dvě desetiny', 'Ne'"}
	 */
	public string $priceInclVat = 'Ano, zaokrouhleno na celé čísla';

	/**
	 * Sazba DPH %
	 * @column{"type":"enum","length":"'Ano','Ne'"}
	 */
	public string $vatRate = 'Ne';

	/**
	 * Doporučená cena MO
	 * @column{"type":"enum","length":"'Ano','Ne'"}
	 */
	public string $priceRetail = 'Ne';

	/**
	 * Ceníková cena
	 * @column{"type":"enum","length":"'Ano','Ne'"}
	 */
	public string $priceList = 'Ne';

	/**
	 * Zákaznická sleva %
	 * @column{"type":"enum","length":"'Ano','Ne'"}
	 */
	public string $priceDiscountOrg = 'Ne';

	/**
	 * Celková sleva %
	 * @column{"type":"enum","length":"'Ano','Ne'"}
	 */
	public string $priceDiscountTotal = 'Ne';

	/**
	 * Název produktu
	 * @column{"type":"enum","length":"'Ano','Ne'"}
	 */
	public string $productTitle = 'Ano';

	/**
	 * Popis produktu
	 * @column{"type":"enum","length":"'Ano - čistý text bez HTML', 'Ano - verze s HTML', 'Ne'"}
	 */
	public string $productDescription = 'Ano - čistý text bez HTML';

	/**
	 * Obrázky
	 * @column{"type":"enum","length":"'Ne', 'Varianta 1', 'Varianta 2', 'Varianta 3', 'Varianta 4', 'Varianta 5'"}
	 */
	public string $productImages = 'Ne';

	/**
	 * Obrázky bez komprese
	 * @column{"type":"enum","length":"'Ne', 'Varianta 1', 'Varianta 2', 'Varianta 3', 'Varianta 4', 'Varianta 5', 'Varianta 6'"}
	 */
	public string $productImagesUc = 'Ne';

	/**
	 * Kategorie
	 * @column{"type":"enum","length":"'Ne', 'Varianta 1', 'Varianta 2', 'Varianta 3', 'Varianta 4'"}
	 */
	public string $productCategories = 'Ne';

	/**
	 * ID variant
	 * @column{"type":"enum","length":"'Ano','Ne'"}
	 */
	public string $productGroupId = 'Ne';

	/**
	 * Příznak novinka
	 * @column{"type":"enum","length":"'Ano','Ne'"}
	 */
	public string $markNew = 'Ne';

	/**
	 * Příznak výprodej
	 * @column{"type":"enum","length":"'Ano','Ne'"}
	 */
	public string $markStockClearance = 'Ne';

	/**
	 * Příznak akce
	 * @column{"type":"enum","length":"'Ano','Ne'"}
	 */
	public string $markDiscount = 'Ne';

	/**
	 * YouTube URL
	 * @column{"type":"enum","length":"'Ne', 'Varianta 1', 'Varianta 2'"}
	 */
	public string $youtubeUrl = 'Ne';

	/**
	 * GPSR
	 * @column{"type":"enum","length":"'Ano','Ne'"}
	 */
	public string $gpsr = 'Ne';
}
