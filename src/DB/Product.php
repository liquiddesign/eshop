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

	public const SUPPLIER_CONTENT_MODE_NONE = 'none';
	public const SUPPLIER_CONTENT_MODE_PRIORITY = 'priority';
	public const SUPPLIER_CONTENT_MODE_LENGTH = 'length';
	public const SUPPLIER_CONTENT_MODE_SUPPLIER = 'supplier';
	public const SUPPLIER_CONTENT_MODE_CUSTOM_CONTENT = 'content';

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
	 * Externí ID
	 * @column
	 */
	public ?string $externalId;
	
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
	 * Režim přebírání obsahu, platí pouze pokud supplierContentLock === false
	 * @column{"type":"enum","length":"'none','priority','length','supplier'"}
	 */
	public string $supplierContentMode = 'none';

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
	 * @deprecated
	 */
	public RelationCollection $upsells;

	/**
	 * Věrnostní programy
	 * @relation
	 * @var \StORM\RelationCollection<\Eshop\DB\LoyaltyProgramProduct>|\Eshop\DB\LoyaltyProgramProduct[]
	 */
	public RelationCollection $loyaltyPrograms;

	/**
	 * @return \Eshop\DB\Ribbon[]|\StORM\Entity[]
	 */
	public function getImageRibbons(): array
	{
		$ids = $this->getValue('ribbonsIds');

		/** @var \Eshop\DB\RibbonRepository $ribbonRepository */
		$ribbonRepository = $this->getConnection()->findRepository(Ribbon::class);

		return \array_intersect_key($ribbonRepository->getImageRibbons()->toArray(), \array_flip(\explode(',', $ids ?? '')));
	}

	/**
	 * @return \Eshop\DB\Ribbon[]|\StORM\Entity[]
	 */
	public function getTextRibbons(): array
	{
		$ids = $this->getValue('ribbonsIds');

		/** @var \Eshop\DB\RibbonRepository $ribbonRepository */
		$ribbonRepository = $this->getConnection()->findRepository(Ribbon::class);

		return \array_intersect_key($ribbonRepository->getTextRibbons()->toArray(), \array_flip(\explode(',', $ids ?? '')));
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
		$code = null;

		if ($type === 'fullCode') {
			//@TODO code-subcode delimeter (tečka) by mel jit nastavit
			$code = $this->subCode ? $this->code . '.' . $this->subCode : $this->code;
		}

		if ($type === 'code') {
			$code = $this->code;
		}

		if ($type === 'supplierCode') {
			$code = $this->supplierCode;
		}

		if ($type === 'externalCode') {
			$code = $this->externalCode;
		}

		if ($this->supplierSource && $this->supplierSource->productCodePrefix && !$this->supplierSource->showCodeWithPrefix) {
			$prefixLength = \strlen($this->supplierSource->productCodePrefix);

			$code = \substr($code, $prefixLength, \strlen($code) - $prefixLength);
		}

		return $code;
	}

	/**
	 * @return string[]
	 */
	public function getStoreAmounts(): array
	{
		return $this->getConnection()->findRepository(Amount::class)->many()->where('fk_product', $this->getPK())->setIndex('fk_store')->toArrayOf('inStock');
	}

	/**
	 * @return string[]
	 */
	public function getSupplierStoreAmounts(): array
	{
		return $this->getConnection()->findRepository(Amount::class)->many()
			->where('store.fk_supplier IS NOT NULL')
			->where('fk_product', $this->getPK())
			->setIndex('store.fk_supplier')
			->toArrayOf('inStock');
	}

	/**
	 * @param string $property
	 * @return string[]
	 */
	public function getSupplierPrices(string $property = 'price'): array
	{
		return $this->getConnection()->findRepository(Price::class)->many()
			->where('pricelist.fk_supplier IS NOT NULL')
			->where('fk_product', $this->getPK())
			->setIndex('pricelist.fk_supplier')
			->toArrayOf($property);
	}

	/**
	 * @deprecated use property primaryCategory instead
	 */
	public function getPrimaryCategory(): ?Category
	{
		return $this->primaryCategory;
	}

	/**
	 * @param string $property
	 * @param bool $reversed
	 * @return string[]
	 */
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

		/** @phpstan-ignore-next-line */
		return $categoryRepository->many()->where('path LIKE :path', ['path' => $this->primaryCategoryPath])->orderBy(['LENGTH(path)' => $reversed ? 'DESC' : 'ASC'])->toArrayOf($tree, [], true);
	}

	public function inStock(): bool
	{
		return $this->displayAmount === null || !$this->displayAmount->isSold;
	}

	public function getPrice(int $amount = 1): float
	{
		if ($amount === 1) {
			return (float)$this->getValue('price');
		}

		return (float)($this->getQuantityPrice($amount, 'price') ?: $this->getValue('price'));
	}

	public function getPriceVat(int $amount = 1): float
	{
		if ($amount === 1) {
			return (float)$this->getValue('priceVat');
		}

		return (float)($this->getQuantityPrice($amount, 'priceVat') ?: $this->getValue('priceVat'));
	}

	public function getPriceBefore(): ?float
	{
		return $this->getValue('priceBefore') !== null ? (float)$this->getValue('priceBefore') : null;
	}

	public function getPriceVatBefore(): ?float
	{
		return $this->getValue('priceVatBefore') !== null ? (float)$this->getValue('priceVatBefore') : null;
	}

	/**
	 * @return \Eshop\DB\QuantityPrice[]|\StORM\Entity[]
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

		$image = $this->imageFileName ?: ($this->__isset('fallbackImage') ? $this->getValue('fallbackImage') : null);
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

	public function getLoyaltyProgramPointsGain(LoyaltyProgram $loyaltyProgram): float
	{
		/** @var \Eshop\DB\LoyaltyProgramProduct|null $loyaltyProduct */
		$loyaltyProduct = $this->loyaltyPrograms->match(['fk_loyaltyProgram' => $loyaltyProgram->getPK()])->first();

		if ($loyaltyProduct) {
			return $loyaltyProduct->points;
		}

		return 0;
	}

	/**
	 * @return array<string|array>
	 */
	public function getFrontendData(): array
	{
		/** @var \Eshop\DB\ProductRepository $repository */
		$repository = $this->getRepository();

		$attributes = $repository->getActiveProductAttributes($this);

		$productAttributesByCode = [];

		foreach ($attributes as $attributeArray) {
			/** @var \Eshop\DB\Attribute $attribute */
			$attribute = $attributeArray['attribute'];

			$tmp = '';

			/** @var \Eshop\DB\AttributeValue $value */
			foreach ($attributeArray['values'] as $value) {
				$tmp .= $value->label . ', ';
			}

			if (\strlen($tmp) > 0) {
				$tmp = \substr($tmp, 0, -2);
			}

			$productAttributesByCode[$attribute->code] = $tmp;
		}

		return [
			'name' => $this->name,
			'producer' => $this->producer ? $this->producer->name : null,
			'code' => $this->code,
			'ean' => $this->ean,
			'attributes' => $productAttributesByCode,
		];
	}

	private function getQuantityPrice(int $amount, string $property): ?float
	{
		/** @var float|null $price */
		$price = $this->getConnection()->findRepository(QuantityPrice::class)->many()
			->match(['fk_product' => $this->getPK(), 'fk_pricelist' => $this->getValue('pricelist')])
			->where('validFrom <= :amount', ['amount' => $amount])
			->orderBy(['validFrom' => 'DESC'])
			->firstValue($property);

		return $price ? (float)$price : null;
	}
}
