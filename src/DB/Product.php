<?php

declare(strict_types=1);

namespace Eshop\DB;

use Nette\Application\ApplicationException;
use Nette\Utils\DateTime;
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

	public const CODE_TYPES = [
		'fullCode',
		'code',
		'externalCode',
		'supplierCode',
	];

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
	 * Obrázek má malé rozlišení, je poškozený nebo neexistuje
	 * @column
	 */
	public bool $imageNeedFix = false;

	/**
	 * Hlavní kód
	 * @column
	 */
	public ?string $code;

	/**
	 * Kód podskladu
	 * @column
	 */
	public string $subCode = '';

	/**
	 * EAN
	 * @column
	 */
	public ?string $ean;

	/**
	 * Externí kód
	 * @column
	 */
	public ?string $externalCode;

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
	 * Rozměr
	 * @column
	 */
	public ?float $dimension;

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
	 * Neprodejné
	 * @column
	 */
	public bool $unavailable = false;

	/**
	 * Set produktů
	 * @column
	 */
	public bool $productsSet = false;

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
	public ?int $buyCount;

	/**
	 * Zařazeno do katalogu
	 * @column{"type":"date"}
	 */
	public ?string $published;

	/**
	 * Hodnota v % na upsell produktu
	 * @column
	 */
	public ?float $dependedValue = null;

	/**
	 * Hodnocení
	 * @column
	 */
	public ?float $rating = null;

	/**
	 * Nejvyšší priorita dodavatele
	 * @column
	 */
	public ?int $supplierLock = null;

	/**
	 * Nepřebírat obsah
	 * @column
	 */
	public bool $supplierContentLock = false;

	/**
	 * Přebírat obsah
	 * @relation
	 * @constraint{"onUpdate":"SET NULL","onDelete":"SET NULL"}
	 */
	public ?Supplier $supplierContent;

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
	 * Interní stužky
	 * @relationNxN
	 * @var \StORM\RelationCollection<\Eshop\DB\InternalRibbon>|\Eshop\DB\InternalRibbon[]
	 */
	public RelationCollection $internalRibbons;

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
	 * Poplatky a daně
	 * @relationNxN
	 * @var \StORM\RelationCollection<\Eshop\DB\Tax>|\Eshop\DB\Tax[]
	 */
	public RelationCollection $taxes;

	/**
	 * Upsell produkty
	 * @relationNxN{"sourceViaKey":"fk_root","targetViaKey":"fk_upsell"}
	 * @var \StORM\RelationCollection<\Eshop\DB\Product>|\Eshop\DB\Product[]
	 */
	public RelationCollection $upsells;

	/**
	 * @return \Eshop\DB\Ribbon[]
	 */
	public function getImageRibbons(): array
	{
		$ids = $this->getValue('ribbonsIds');

		$riboons = $this->getConnection()->findRepository(Ribbon::class)->getImageRibbons();

		return \array_intersect_key($riboons->toArray(), \array_flip(\explode(',', $ids ?? '')));
	}

	/**
	 * @return \Eshop\DB\Ribbon[]
	 */
	public function getTextRibbons(): array
	{
		$ids = $this->getValue('ribbonsIds');

		$riboons = $this->getConnection()->findRepository(Ribbon::class)->getTextRibbons();

		return \array_intersect_key($riboons->toArray(), \array_flip(\explode(',', $ids ?? '')));
	}

	/**
	 * @return array<string, array<int, array<string, string>>>
	 */
	public function getPreviewParameters(): array
	{
		if (!$this->getValue('parameters')) {
			return [];
		}

		$parameters = [];

		foreach (\explode(',', $this->getValue('parameters')) as $parameterSerialized) {
			$parameter = \explode('|', $parameterSerialized);

			if (!isset($parameter[3])) {
				continue;
			}

			if (!isset($parameters[$parameter[1]])) {
				$parameters[$parameter[1]] = [];
			}

			$parameters[$parameter[1]][] = [
				'uuid' => $parameter[0],
				'fk_parameter' => $parameter[1],
				'label' => $parameter[2],
				'metaValue' => $parameter[3],
			];
		}

		return $parameters;
	}

	public function getFullCode(string $type = 'fullCode'): ?string
	{
		if ($type === 'fullCode') {
			//@TODO code-subcode delimeter (tečka) by mel jit nastavit
			return $this->subCode ? $this->code . '.' . $this->subCode : $this->code;
		}

		if ($type === 'code') {
			return $this->code;
		}

		if ($type === 'supplierCode') {
			return $this->supplierCode;
		}

		if ($type === 'externalCode') {
			return $this->externalCode;
		}

		return null;
	}

	public function getStoreAmounts()
	{
		return $this->getConnection()->findRepository(Amount::class)->many()->where('fk_product', $this->getPK())->setIndex('fk_store')->toArrayOf('inStock');
	}

	public function getSupplierPrices(string $property = 'price')
	{
		return $this->getConnection()->findRepository(Price::class)->many()->where('pricelist.fk_supplier IS NOT NULL')->where('fk_product', $this->getPK())->setIndex('pricelist.fk_supplier')->toArrayOf($property);
	}

	/**
	 * @deprecated use property primaryCategory instead
	 */
	public function getPrimaryCategory(): ?Category
	{
		return $this->primaryCategory;
	}

	public function getCategoryTree(string $property, bool $reversed = false): array
	{
		if (!isset($this->primaryCategoryPath) || !$this->primaryCategoryPath) {
			return [];
		}

		/** @var \Eshop\DB\CategoryRepository $categoryRepository */
		$categoryRepository = $this->getConnection()->findRepository(Category::class);

		$tree = [];
		$type = 'main';

		if ($categoryRepository->isTreeBuild($type)) {
			for ($i = 4; $i <= \strlen($this->primaryCategoryPath); $i += 4) {
				if ($category = $categoryRepository->getCategoryByPath($type, \substr($this->primaryCategoryPath, 0, $i))) {
					$tree[] = $category->$property;
				}
			}

			if ($reversed) {
				$tree = \array_reverse($tree);
			}

			return $tree;
		}

		return $categoryRepository->many()->where('path LIKE :path', ['path' => $this->primaryCategoryPath])->orderBy(['LENGTH(path)' => $reversed ? 'DESC' : 'ASC'])->toArrayOf($tree, [], true);
	}

	public function inStock(): bool
	{
		return $this->displayAmount === null || !$this->displayAmount->isSold;
	}

	public function getPrice(int $amount = 1): float
	{
		if ($amount === 1) {
			return (float)$this->price;
		}

		return (float)($this->getQuantityPrice($amount, 'price') ?: $this->price);
	}

	private function getQuantityPrice(int $amount, string $property): ?float
	{
		return (float)$this->getConnection()->findRepository(QuantityPrice::class)->many()
			->match(['fk_product' => $this->getPK(), 'fk_pricelist' => $this->pricelist])
			->where('validFrom <= :amount', ['amount' => $amount])
			->orderBy(['validFrom' => 'DESC'])
			->firstValue($property);
	}

	public function getPriceVat(int $amount = 1): float
	{
		if ($amount === 1) {
			return (float)$this->priceVat;
		}

		return (float)($this->getQuantityPrice($amount, 'priceVat') ?: $this->priceVat);
	}

	public function getPriceBefore(): ?float
	{
		return $this->priceBefore !== null ? (float)$this->priceBefore : null;
	}

	public function getPriceVatBefore(): ?float
	{
		return $this->priceVatBefore !== null ? (float)$this->priceVatBefore : null;
	}

	/**
	 * @return \Eshop\DB\QuantityPrice[]
	 */
	public function getQuantityPrices(): array
	{
		return $this->quantityPrices->where('validFrom IS NOT NULL')->orderBy(['validFrom' => 'ASC'])->toArray();
	}

	public function getPreviewImage(string $basePath, string $size = 'detail'): string
	{
		if (!\in_array($size, ['origin', 'detail', 'thumb'])) {
			throw new ApplicationException('Invalid product image size: ' . $size);
		}

		$image = $this->imageFileName ?: ($this->__isset('fallbackImage') ? $this->fallbackImage : null);
		$dir = $this->imageFileName ? Product::GALLERY_DIR : Category::IMAGE_DIR;

		return $image ? "$basePath/userfiles/$dir/$size/$image" : "$basePath/public/img/no-image.png";
	}

	public function getDynamicDelivery(): ?string
	{
		if ($this->displayDelivery !== null) {
			return $this->displayDelivery->label;
		}

		if ($this->displayAmount === null || $this->displayAmount->displayDelivery === null) {
			return null;
		}

		$displayDelivery = $this->displayAmount->displayDelivery;

		if ($displayDelivery->timeThreshold === null) {
			return $displayDelivery->label;
		}
		
		$nowThresholdTime = DateTime::createFromFormat('G:i', $displayDelivery->timeThreshold);

		return $nowThresholdTime > (new DateTime()) ? $displayDelivery->beforeTimeThresholdLabel : $displayDelivery->afterTimeThresholdLabel;
	}
}
