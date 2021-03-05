<?php

declare(strict_types=1);

namespace Eshop\DB;

use User\DB\Watcher;
use Nette\Application\ApplicationException;
use StORM\RelationCollection;

/**
 * Produkt
 * @table
 * @index{"name":"code_subcode","unique":true,"columns":["code","subCode"]}
 * @index{"name":"ean","unique":true,"columns":["ean"]}
 */
class Product extends \StORM\Entity
{
	public const IMAGE_DIR = 'product_images';
	
	public const GALLERY_DIR = 'product_gallery_images';
	
	public const FILE_DIR = 'product_files';
	
	/**
	 * Název
	 * @column{"mutations":true}
	 */
	public ?string $name;
	
	/**
	 * Náhledový obrázek
	 * @column
	 */
	public ?string $imageFileName;
	
	/**
	 * Hlavní kód
	 * @column
	 */
	public ?string $code;
	
	/**
	 * Kód podskladu
	 * @column
	 */
	public ?string $subCode;
	
	/**
	 * EAN
	 * @column
	 */
	public ?string $ean;
	
	/**
	 * Dovataleský kód
	 * @column
	 */
	public ?string $supplierCode;
	
	/**
	 * Prodejní jednotka (kus)
	 * @column
	 */
	public ?string $unit;
	
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
	 * Maximální prodejní množství
	 * @column
	 */
	public ?int $maxBuyCount;
	
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
	 * Úroveň DPH
	 * @column{"type":"enum","length":"'standard','reduced-high','reduced-low','zero'"}
	 */
	public string $vatRate;
	
	/**
	 * Zokrouhlení od procent na balení
	 * @column
	 */
	public ?int $roundingPackagePct;
	
	/**
	 * Zokrouhlení od procent na karton
	 * @column
	 */
	public ?int $roundingCartonPct;
	
	/**
	 * Zokrouhlení od procent na paletu
	 * @column
	 */
	public ?int $roundingPalletPct;
	
	/**
	 * Datum nejblišího naskladnění
	 * @column
	 */
	public ?string $storageDate;
	
	/**
	 * Počet dní k zákazníkovi
	 * @column
	 */
	public ?int $deliveryDays;
	
	/**
	 * Body
	 * @column
	 */
	public ?int $points;
	
	/**
	 * Váha
	 * @column
	 */
	public ?float $weight;
	
	/**
	 * Slevová hladina
	 * @column
	 */
	public int $discountLevelPct = 0;
	
	/**
	 * Perex
	 * @column{"type":"text","mutations":true}
	 */
	public ?string $perex;
	
	/**
	 * Obsah
	 * @column{"type":"longtext","mutations":true}
	 */
	public ?string $content;
	
	/**
	 * Priorita
	 * @column
	 */
	public int $priority = 10;
	
	/**
	 * Je to produkt, dárek nebo set
	 * @column{"type":"enum","length":"'product','gift','set'"}
	 */
	public string $type = 'product';
	
	/**
	 * Neprodejné
	 * @column
	 */
	public bool $unavailable = false;
	
	/**
	 * Skryto
	 * @column
	 */
	public bool $hidden = false;
	
	/**
	 * Doporučené
	 * @column
	 */
	public bool $recommended = false;
	
	/**
	 * Koncept
	 * @column{"mutations":true}
	 */
	public bool $draft = false;
	
	/**
	 * Kolik bylo koupeno
	 * @column
	 */
	public int $buyCount = 0;
	
	/**
	 * Zařazeno do katalogu
	 * @column{"type":"date"}
	 */
	public ?string $published;
	
	/**
	 * Zamknuto pro prioritu dodavatele
	 * @column
	 */
	public int $supplierLock = 0;
	
	/**
	 * Zdrojový dodavatel
	 * @relation
	 * @constraint{"onUpdate":"CASCADE","onDelete":"CASCADE"}
	 */
	public ?Supplier $supplierSource;
	
	/**
	 * Alternativní produkt k
	 * @relation
	 * @constraint
	 */
	public ?Product $alternative;
	
	/**
	 * Výrobce / značka
	 * @relation
	 * @constraint{"onUpdate":"SET NULL","onDelete":"SET NULL"}
	 */
	public ?Producer $producer;
	
	/**
	 * Hlavní kategorie
	 * @relation
	 * @constraint{"onUpdate":"SET NULL","onDelete":"SET NULL"}
	 */
	public ?Category $primaryCategory;
	
	/**
	 * Skladem (agregovaná hodnota)
	 * @relation
	 * @constraint{"onUpdate":"SET NULL","onDelete":"SET NULL"}
	 */
	public ?DisplayAmount $displayAmount;
	
	/**
	 * Doručení (agregovaná hodnota)
	 * @relation
	 * @constraint{"onUpdate":"SET NULL","onDelete":"SET NULL"}
	 */
	public ?DisplayDelivery $displayDelivery;
	
	/**
	 * Watcher pro aktualniho uživatele jinak nedáva smysl
	 * @relation
	 */
	public ?Watcher $watcher;
	
	/**
	 * Skupina parametrů
	 * @relationNxN
	 * @var \StORM\RelationCollection<\Eshop\DB\ParameterGroup>|\Eshop\DB\ParameterGroup[]
	 */
	public RelationCollection $parameterGroups;
	
	/**
	 * Kategorie
	 * @relationNxN
	 * @var \StORM\RelationCollection<\Eshop\DB\Category>|\Eshop\DB\Category[]
	 */
	public RelationCollection $categories;
	
	/**
	 * Dodvatelské produkty
	 * @relation
	 * @var \StORM\RelationCollection<\Eshop\DB\SupplierProduct>|\Eshop\DB\SupplierProduct[]
	 */
	public RelationCollection $supplierProducts;
	
	/**
	 * Tagy
	 * @relationNxN
	 * @var \StORM\RelationCollection<\Eshop\DB\Tag>|\Eshop\DB\Tag[]
	 */
	public RelationCollection $tags;
	
	/**
	 * Stužky
	 * @relationNxN
	 * @var \StORM\RelationCollection<\Eshop\DB\Ribbon>|\Eshop\DB\Ribbon[]
	 */
	public RelationCollection $ribbons;
	
	/**
	 * Varianty
	 * @relation
	 * @var \StORM\RelationCollection<\Eshop\DB\Variant>|\Eshop\DB\Variant[]
	 */
	public RelationCollection $variants;
	
	/**
	 * Obrázky galerie
	 * @relation
	 * @var \StORM\RelationCollection<\Eshop\DB\Photo>|\Eshop\DB\Photo[]
	 */
	public RelationCollection $galleryImages;
	
	/**
	 * Soubory
	 * @relation
	 * @var \StORM\RelationCollection<\Eshop\DB\File>|\Eshop\DB\File[]
	 */
	public RelationCollection $files;
	
	/**
	 * Množstevní slevy
	 * @relation
	 * @var \StORM\RelationCollection<\Eshop\DB\QuantityPrice>|\Eshop\DB\QuantityPrice[]
	 */
	public RelationCollection $quantityPrices;
	
	/**
	 * @return \Eshop\DB\Ribbon[]
	 */
	public function getRibbons(): array
	{
		$ids = $this->getValue('ribbonsIds');
		$riboons = $this->getConnection()->findRepository(Ribbon::class)->getRibbons();
		
		return \array_intersect_key($riboons->toArray(), \array_flip(\explode(',', $ids ?? '')));
	}
	
	public function getPreviewParameters(): array
	{
		if (!$this->getValue('parameters')) {
			return [];
		}
		
		$parameters = [];
		
		foreach (\explode(',', $this->getValue('parameters')) as $parameterSerialized) {
			$parameter = \explode('|', $parameterSerialized);
			
			$parameters[] = new ParameterValue([
				'uuid' => $parameter[0],
				'value' => $parameter[1],
				'metaValue' => $parameter[2],
				'fk_parameter' => $parameter[3],
			], $this->getConnection()->findRepository(ParameterValue::class));
		}
		
		return $parameters;
	}
	
	public function getFullCode(): ?string
	{
		//@TODO code-subcode delimeter (tečka) by mel jit nastavit
		return $this->subCode ? $this->code . '.' . $this->subCode : $this->code;
	}
	
	public function getPrimaryCategory(): ?Category
	{
		//@TODO: monza nacashovat
		
		return $this->primaryCategory ?: $this->categories->first();
	}
	
	public function inStock(): bool
	{
		return !$this->displayAmount || !$this->displayAmount->isSold;
	}
	
	public function getPreviewImage(string $basePath, string $size = 'detail'): string
	{
		if (!\in_array($size, ['origin', 'detail', 'thumb'])) {
			throw new ApplicationException('Invalid product image size: ' . $size);
		}
		
		return $this->imageFileName ? $basePath . '/userfiles/' . self::IMAGE_DIR . '/' . $size . '/' . $this->imageFileName : $basePath . '/public/img/no-image.png';
	}
}
