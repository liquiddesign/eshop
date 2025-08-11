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
	 * @constraint{"onUpdate":"CASCADE","onDelete":"CASCADE"}
	 * @relation
	 */
	public ?Customer $customer;

	/**
	 * ID
	 * @column{"type":"enum","length":"'Yes','No'"}
	 */
	public string $id = 'No';

	/**
	 * SKU
	 * @column{"type":"enum","length":"'Yes','No'"}
	 */
	public string $sku = 'Yes';

	/**
	 * EAN
	 * @column{"type":"enum","length":"'Yes','No'"}
	 */
	public string $ean = 'Yes';

	/**
	 * ID skup. zbo
	 * @column{"type":"enum","length":"'Yes','No'"}
	 */
	public string $skupZboId = 'No';

	/**
	 * Skup. zbo
	 * @column{"type":"enum","length":"'Yes','No'"}
	 */
	public string $skupZboName = 'No';

	/**
	 * Hmotnost
	 * @column{"type":"enum","length":"'Yes','No'"}
	 */
	public string $pckgWeight = 'No';

	/**
	 * Výška
	 * @column{"type":"enum","length":"'Yes','No'"}
	 */
	public string $pckgHeight = 'No';

	/**
	 * šířka
	 * @column{"type":"enum","length":"'Yes','No'"}
	 */
	public string $pckgWidth = 'No';

	/**
	 * Hloubka
	 * @column{"type":"enum","length":"'Yes','No'"}
	 */
	public string $pckgDepth = 'No';

	/**
	 * Množství skladem
	 * @column{"type":"enum","length":"'Yes','No'"}
	 */
	public string $qtyFree = 'Yes';

	/**
	 * Termín dodávky
	 * @column{"type":"enum","length":"'Yes','No'"}
	 */
	public string $stockOnRoad = 'No';

	/**
	 * Vaše nákupní cena bez DPH
	 * @column{"type":"enum","length":"'YesRoundWhole', 'YesRoundTwo', 'No'"}
	 */
	public string $priceExclVat = 'YesRoundWhole';

	/**
	 * Vaše nákupní cena s DPH
	 * @column{"type":"enum","length":"'YesRoundWhole', 'YesRoundTwo', 'No'"}
	 */
	public string $priceInclVat = 'YesRoundWhole';

	/**
	 * Sazba DPH %
	 * @column{"type":"enum","length":"'Yes','No'"}
	 */
	public string $vatRate = 'No';

	/**
	 * Doporučená cena MO
	 * @column{"type":"enum","length":"'Yes','No'"}
	 */
	public string $priceRetail = 'No';

	/**
	 * Ceníková cena
	 * @column{"type":"enum","length":"'Yes','No'"}
	 */
	public string $priceList = 'No';

	/**
	 * Zákaznická sleva %
	 * @column{"type":"enum","length":"'Yes','No'"}
	 */
	public string $priceDiscountOrg = 'No';

	/**
	 * Celková sleva %
	 * @column{"type":"enum","length":"'Yes','No'"}
	 */
	public string $priceDiscountTotal = 'No';

	/**
	 * Název produktu
	 * @column{"type":"enum","length":"'Yes','No'"}
	 */
	public string $productTitle = 'Yes';

	/**
	 * Popis produktu
	 * @column{"type":"enum","length":"'YesWithoutHTML', 'YesWithHTML', 'No'"}
	 */
	public string $productDescription = 'YesWithoutHTML';

	/**
	 * Obrázky
	 * @column{"type":"enum","length":"'No', 'v1', 'v2', 'v3', 'v4', 'v5'"}
	 */
	public string $productImages = 'No';

	/**
	 * Obrázky bez komprese
	 * @column{"type":"enum","length":"'No', 'v1', 'v2', 'v3', 'v4', 'v5', 'v6'"}
	 */
	public string $productImagesUc = 'No';

	/**
	 * Kategorie
	 * @column{"type":"enum","length":"'No', 'v1', 'v2', 'v3', 'v4'"}
	 */
	public string $productCategories = 'No';

	/**
	 * ID variant
	 * @column{"type":"enum","length":"'Yes','No'"}
	 */
	public string $productGroupId = 'No';

	/**
	 * Příznak novinka
	 * @column{"type":"enum","length":"'Yes','No'"}
	 */
	public string $markNew = 'No';

	/**
	 * Příznak výprodej
	 * @column{"type":"enum","length":"'Yes','No'"}
	 */
	public string $markStockClearance = 'No';

	/**
	 * Příznak akce
	 * @column{"type":"enum","length":"'Yes','No'"}
	 */
	public string $markDiscount = 'No';

	/**
	 * YouTube URL
	 * @column{"type":"enum","length":"'No', 'v1', 'v2'"}
	 */
	public string $youtubeUrl = 'No';

	/**
	 * GPSR
	 * @column{"type":"enum","length":"'Yes','No'"}
	 */
	public string $gpsr = 'No';
}
