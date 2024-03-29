<?php

declare(strict_types=1);

namespace Eshop\DB;

/**
 * Produktový pahýl dodavatele
 * @table
 * @index{"name":"supplier_product","unique":true,"columns":["fk_product","fk_supplier"]}
 * @index{"name":"supplier_product_external_code","unique":true,"columns":["code","fk_supplier"]}
 * @index{"name":"supplier_product_ean","unique":true,"columns":["ean","fk_supplier"]}
 * @index{"name":"supplier_product_code","unique":true,"columns":["productCode","productSubCode","fk_supplier"]}
 */
class SupplierProduct extends \StORM\Entity
{
	/**
	 * Kód produktu k napárování
	 * @column
	 */
	public ?string $productCode;
	
	/**
	 * Podkód produktu k napárování
	 * @column
	 */
	public string $productSubCode = '';
	
	/**
	 * EAN
	 * @column
	 */
	public ?string $ean;
	
	/**
	 * Kód produktu
	 * @column
	 */
	public ?string $code;

	/**
	 * Kód výrobce - Manufacturer Part Number
	 * @column
	 */
	public ?string $mpn;
	
	/**
	 * Název
	 * @column
	 */
	public string $name;
	
	/**
	 * Perex
	 * @column{"type":"longtext"}
	 */
	public ?string $perex;
	
	/**
	 * Popis
	 * @column{"type":"longtext"}
	 */
	public ?string $content;
	
	/**
	 * Jednotka
	 * @column
	 */
	public ?string $unit;
	
	/**
	 * DPH
	 * @column
	 */
	public ?float $vatRate;
	
	/**
	 * Cena A bez DPH
	 * @column
	 */
	public ?float $price;
	
	/**
	 * Cena B s DPH
	 * @column
	 */
	public ?float $priceVat;
	
	/**
	 * Nákupní cena
	 * @column
	 */
	public ?float $purchasePrice;
	
	/**
	 * Nákupní cena s DPH
	 * @column
	 */
	public ?float $purchasePriceVat;
	
	/**
	 * Množství
	 * @column
	 */
	public ?int $amount;
	
	/**
	 * Datum nejblišího naskladnění
	 * @column
	 */
	public ?string $storageDate;
	
	/**
	 * Předdefinované množství ke koupi
	 * @column
	 */
	public int $defaultBuyCount = 1;
	
	/**
	 * Minimální prodejní množství
	 * @column
	 */
	public int $minBuyCount = 1;

	/**
	 * Krokové prodejní množství
	 * @column
	 */
	public int $buyStep = 1;
	
	/**
	 * Počet v balení
	 * @column
	 */
	public ?int $inPackage;
	
	/**
	 * Počet v kartónu
	 * @column
	 */
	public ?int $inCarton;
	
	/**
	 * Počet v paletě
	 * @column
	 */
	public ?int $inPalett;
	
	/**
	 * Váha
	 * @column
	 */
	public ?float $weight;
	
	/**
	 * Obrázek
	 * @column
	 */
	public ?string $fileName;
	
	/**
	 * Zkopírovat / spárovat s produkty
	 * @column
	 */
	public bool $active = true;
	
	/**
	 * Je ve feedu smazaný
	 * @column
	 */
	public bool $deleted = false;
	
	/**
	 * Nedostupné
	 * @column
	 */
	public bool $unavailable = false;

	/**
	 * Recyklační poplatek
	 * @column
	 */
	public ?float $recyclingFee;

	/**
	 * Autorský poplatek
	 * @column
	 */
	public ?float $copyrightFee;
	
	/**
	 * Výrobce
	 * @relation
	 * @constraint{"onUpdate":"CASCADE","onDelete":"SET NULL"}
	 */
	public ?SupplierProducer $producer;
	
	/**
	 * Kategorie
	 * @relation
	 * @constraint{"onUpdate":"CASCADE","onDelete":"SET NULL"}
	 */
	public ?SupplierCategory $category;
	
	/**
	 * Dostupnost
	 * @relation
	 * @constraint{"onUpdate":"CASCADE","onDelete":"SET NULL"}
	 */
	public ?SupplierDisplayAmount $displayAmount;
	
	/**
	 * Dodavatel
	 * @relation
	 * @constraint{"onUpdate":"CASCADE","onDelete":"CASCADE"}
	 */
	public Supplier $supplier;
	
	/**
	 * Produkt
	 * @relation
	 * @constraint{"onUpdate":"CASCADE","onDelete":"SET NULL"}
	 */
	public ?Product $product;
	
	/**
	 * Aktualizován
	 * @column{"type":"timestamp","default":"CURRENT_TIMESTAMP","extra":"on update CURRENT_TIMESTAMP"}
	 */
	public string $updateTs;
	
	/**
	 * Vytvořen
	 * @column{"type":"timestamp","default":"CURRENT_TIMESTAMP"}
	 */
	public string $createdTs;

	public function getProductFullCode(): ?string
	{
		//@TODO code-subcode delimeter (tečka) by mel jit nastavit
		return $this->productSubCode ? $this->productCode . '.' . $this->productSubCode : $this->productCode;
	}
}
