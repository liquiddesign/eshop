<?php

declare(strict_types=1);

namespace Eshop\DB;

use Eshop\Admin\SettingsPresenter;
use Nette\Application\ApplicationException;
use Nette\Utils\Arrays;
use Nette\Utils\Strings;
use StORM\Collection;
use StORM\IEntityParent;
use StORM\RelationCollection;
use Web\DB\Setting;

/**
 * Produkt
 * @table
 * @index{"name":"code_subcode","unique":true,"columns":["code","subCode"]}
 * @index{"name":"ean","unique":true,"columns":["ean"]}
 */
class Product extends \StORM\Entity
{
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
	 * Název
	 * @column{"mutations":true}
	 */
	public ?string $extendedName;

	/**
	 * Náhledový obrázek
	 * @column{"type":"longtext"}
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
	 * Kód výrobce - Manufacturer Part Number
	 * @column
	 */
	public ?string $mpn;

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
	 * @column{"type":"date"}
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
	 * Šířka
	 * @column
	 */
	public ?int $width;
	
	/**
	 * Délka
	 * @column
	 */
	public ?int $length;
	
	/**
	 * Hloubka
	 * @column
	 */
	public ?int $depth;
	
	/**
	 * Při přepravě nechat naplacato
	 * @column
	 */
	public bool $keepFlat = false;

	/**
	 * Rozměr
	 * @deprecated use length, depth, width instead
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
	 * Priorita
	 * @column
	 */
	public int $algoliaPriority = 10;

	/**
	 * @column{"type":"timestamp"}
	 */
	public ?string $karsaExportTs;

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
	 * Skryto v menu a vyhledávání, dostupné přes URL
	 * @column
	 */
	public bool $hiddenInMenu = false;

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
	 * Výchozí průměrné sk=re recenzí
	 * @column
	 */
	public ?float $defaultReviewsScore;

	/**
	 * Výchozí počet recenzí
	 * @column
	 */
	public ?int $defaultReviewsCount;

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
	 * Nepřebírat display amount
	 * @column
	 */
	public bool $supplierDisplayAmountLock = false;

	/**
	 * Nepřebírat display amount od sloučených produktů
	 * @column
	 */
	public bool $supplierDisplayAmountMergedLock = false;

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
	 * @constraint{"onUpdate":"CASCADE","onDelete":"SET NULL"}
	 */
	public ?Supplier $supplierSource;

	/**
	 * @column
	 */
	public bool $karsaAllowRepricing = true;

	/**
	 * Alternativní produkt k
	 * @relation
	 * @constraint{"onUpdate":"CASCADE","onDelete":"SET NULL"}
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
	 * Exportní název kategori pro Google
	 * @column
	 */
	public ?string $exportGoogleCategory;
	
	/**
	 * Exportní ID kategorie Google
	 * @column
	 */
	public ?string $exportGoogleCategoryId;
	
	/**
	 * Kategorie pro Heuréku
	 * @relation
	 * @constraint{"onUpdate":"CASCADE","onDelete":"SET NULL"}
	 */
	public ?Category $exportHeurekaCategory;
	
	/**
	 * Kategorie pro Zboží.cz
	 * @relation
	 * @constraint{"onUpdate":"CASCADE","onDelete":"SET NULL"}
	 */
	public ?Category $exportZboziCategory;

	/**
	 * Watcher pro aktualniho uživatele jinak nedáva smysl
	 * @relation
	 */
	public ?Watcher $watcher;

	/**
	 * Sloučené produkty
	 * @relation
	 * @constraint{"onUpdate":"CASCADE","onDelete":"SET NULL"}
	 */
	public ?Product $masterProduct;

	/**
	 * Sloučené produkty
	 * @relation{"targetKey":"fk_masterProduct"}
	 * @var \StORM\RelationCollection<\Eshop\DB\Product>
	 */
	public RelationCollection $slaveProducts;

	/**
	 * Kategorie
	 * @relationNxN
	 * @var \StORM\RelationCollection<\Eshop\DB\Category>
	 */
	public RelationCollection $categories;

	/**
	 * Dodvatelské produkty
	 * @relation
	 * @var \StORM\RelationCollection<\Eshop\DB\SupplierProduct>
	 */
	public RelationCollection $supplierProducts;

	/**
	 * Stužky
	 * @relationNxN
	 * @var \StORM\RelationCollection<\Eshop\DB\Ribbon>
	 */
	public RelationCollection $ribbons;

	/**
	 * Interní stužky
	 * @relationNxN
	 * @var \StORM\RelationCollection<\Eshop\DB\InternalRibbon>
	 */
	public RelationCollection $internalRibbons;

	/**
	 * Varianty
	 * @relation
	 * @var \StORM\RelationCollection<\Eshop\DB\Variant>
	 */
	public RelationCollection $variants;

	/**
	 * Obrázky galerie
	 * @relation
	 * @var \StORM\RelationCollection<\Eshop\DB\Photo>
	 */
	public RelationCollection $galleryImages;

	/**
	 * Soubory
	 * @relation
	 * @var \StORM\RelationCollection<\Eshop\DB\File>
	 */
	public RelationCollection $files;

	/**
	 * Množstevní slevy
	 * @relation
	 * @var \StORM\RelationCollection<\Eshop\DB\QuantityPrice>
	 */
	public RelationCollection $quantityPrices;

	/**
	 * Poplatky a daně
	 * @relationNxN
	 * @var \StORM\RelationCollection<\Eshop\DB\Tax>
	 */
	public RelationCollection $taxes;

	/**
	 * Věrnostní programy
	 * @relation
	 * @var \StORM\RelationCollection<\Eshop\DB\LoyaltyProgramProduct>
	 */
	public RelationCollection $loyaltyPrograms;

	/**
	 * @relation
	 * @var \StORM\RelationCollection<\Eshop\DB\Review>
	 */
	public RelationCollection $reviews;

	/**
	 * Galerie
	 * @relation
	 * @var \StORM\RelationCollection<\Eshop\DB\Photo>
	 */
	public RelationCollection $photos;

	private ProductRepository $productRepository;

	public function __construct(array $vars, ?IEntityParent $parent = null, array $mutations = [], ?string $mutation = null)
	{
		parent::__construct($vars, $parent, $mutations, $mutation);

		$this->productRepository = $this->getConnection()->findRepository(Product::class);
	}

	/**
	 * @return array<\Eshop\DB\Product>
	 */
	public function getAllMergedProducts(bool $onlyDescendants = true): array
	{
		$down = $this->doGetAllMergedProducts($this);

		if ($onlyDescendants) {
			return $down;
		}

		$up = [];
		$product = $this;

		while ($masterProduct = $product->masterProduct) {
			$up[$masterProduct->getPK()] = $masterProduct;

			$product = $masterProduct;
		}

		return \array_merge(\array_reverse($up), $down);
	}

	/**
	 * @return array<\Eshop\DB\Ribbon>|array<\StORM\Entity>
	 */
	public function getImageRibbons(): array
	{
		$ids = $this->getValue('ribbonsIds');

		/** @var \Eshop\DB\RibbonRepository $ribbonRepository */
		$ribbonRepository = $this->getConnection()->findRepository(Ribbon::class);

		return \array_intersect_key($ribbonRepository->getImageRibbons()->toArray(), \array_flip(\explode(',', $ids ?? '')));
	}

	/**
	 * @return array<\Eshop\DB\Ribbon>|array<\StORM\Entity>
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
	public function getPreviewAtttributes(): array
	{
		if (!$this->getValue('parameters')) {
			return [];
		}

		$attributePriorities = [];
		$parameters = [];

		foreach (\explode(';', $this->getValue('parameters')) as $parameterSerialized) {
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
				'attributeName' => $parameter[4] ?? null,
				'imageFileName' => $parameter[5] ?? null,
				'number' => $parameter[6] ?? null,
				'note' => $parameter[7] ?? null,
				'attributeNote' => $parameter[8] ?? null,
				'attributePriority' => $parameter[9] ?? null,
				'valuePriority' => $parameter[10] ?? null,
			];

			\usort($parameters[$parameter[1]], function ($a, $b) {
				if ($a['valuePriority'] === $b['valuePriority']) {
					return 0;
				}

				return $a['valuePriority'] < $b['valuePriority'] ? -1 : 1;
			});

			if (!isset($parameter[9])) {
				continue;
			}

			$attributePriorities[$parameter[1]] = $parameter[9];
		}

		\uksort($parameters, function ($a, $b) use ($attributePriorities) {
			if (!isset($attributePriorities[$a]) || !isset($attributePriorities[$b])) {
				return 0;
			}

			$a = (int) $attributePriorities[$a];
			$b = (int) $attributePriorities[$b];

			if ($a === $b) {
				return 0;
			}

			return $a < $b ? -1 : 1;
		});

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
			$prefixLength = Strings::length($this->supplierSource->productCodePrefix);

			$code = Strings::substring($code, $prefixLength, Strings::length($code) - $prefixLength);
		}

		return $code;
	}

	/**
	 * @return array<string>
	 */
	public function getStoreAmounts(): array
	{
		return $this->getConnection()->findRepository(Amount::class)->many()->where('fk_product', $this->getPK())->setIndex('fk_store')->toArrayOf('inStock');
	}

	/**
	 * @return array<string>
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
	 * @return array<string>
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
	 * @return array<string>
	 */
	public function getCategoryTree(string $property, bool $reversed = false): array
	{
		/** @var \Eshop\DB\Product|\stdClass $product */
		$product = $this;

		if (!isset($product->primaryCategoryPath) || !$product->primaryCategoryPath) {
			return [];
		}

		/** @var \Eshop\DB\CategoryRepository $categoryRepository */
		$categoryRepository = $this->getConnection()->findRepository(Category::class);

		$tree = [];
		$type = 'main';

		if ($categoryRepository->isTreeBuild($type)) {
			for ($i = 4; $i <= Strings::length($product->primaryCategoryPath); $i += 4) {
				if ($category = $categoryRepository->getCategoryByPath($type, Strings::substring($product->primaryCategoryPath, 0, $i))) {
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
		$displayAmount = $this->getDisplayAmount();

		return $displayAmount === null || !$displayAmount->isSold;
	}

	public function getDisplayAmount(): ?DisplayAmount
	{
		/** @var \Web\DB\SettingRepository $settingRepository */
		$settingRepository = $this->getConnection()->findRepository(Setting::class);

		$defaultDisplayAmount = $settingRepository->getValueByName(SettingsPresenter::DEFAULT_DISPLAY_AMOUNT);
		$defaultUnavailableDisplayAmount = $settingRepository->getValueByName(SettingsPresenter::DEFAULT_UNAVAILABLE_DISPLAY_AMOUNT);

		/** @var \Eshop\DB\DisplayAmountRepository $displayAmountRepository */
		$displayAmountRepository = $this->getConnection()->findRepository(DisplayAmount::class);

		if ($defaultDisplayAmount && $defaultUnavailableDisplayAmount) {
			$defaultDisplayAmount = $displayAmountRepository->one($defaultDisplayAmount);
			$defaultUnavailableDisplayAmount = $displayAmountRepository->one($defaultUnavailableDisplayAmount);

			return $this->unavailable === false ? $defaultDisplayAmount : $defaultUnavailableDisplayAmount;
		}

		if ($defaultDisplayAmount) {
			$defaultDisplayAmount = $displayAmountRepository->one($defaultDisplayAmount);

			return $this->unavailable === false ? $defaultDisplayAmount : $this->displayAmount;
		}

		if ($defaultUnavailableDisplayAmount) {
			$defaultUnavailableDisplayAmount = $displayAmountRepository->one($defaultUnavailableDisplayAmount);

			return $this->unavailable === false ? $this->displayAmount : $defaultUnavailableDisplayAmount;
		}

		return $this->displayAmount;
	}

	public function getPrice(int $amount = 1): float
	{
		if ($amount === 1) {
			return (float) $this->getValue('price');
		}

		return (float) ($this->getQuantityPrice($amount, 'price') ?: $this->getValue('price'));
	}

	public function getPriceVat(int $amount = 1): float
	{
		if ($amount === 1) {
			return (float) $this->getValue('priceVat');
		}

		return (float) ($this->getQuantityPrice($amount, 'priceVat') ?: $this->getValue('priceVat'));
	}

	public function getPriceBefore(): ?float
	{
		return (float) $this->getValue('priceBefore') > 0 ? (float) $this->getValue('priceBefore') : null;
	}

	public function getPriceVatBefore(): ?float
	{
		return (float) $this->getValue('priceVatBefore') > 0 ? (float) $this->getValue('priceVatBefore') : null;
	}

	public function getDiscountPercent(): ?float
	{
		if (!$beforePrice = $this->getPriceBefore()) {
			return null;
		}

		return 100 - ($this->getPrice() / $beforePrice * 100);
	}

	public function getDiscountPercentVat(): ?float
	{
		if (!$beforePrice = $this->getPriceVatBefore()) {
			return null;
		}

		return 100 - ($this->getPriceVat() / $beforePrice * 100);
	}

	/**
	 * @return array<\Eshop\DB\QuantityPrice>|array<\StORM\Entity>
	 */
	public function getQuantityPrices(): array
	{
		return $this->quantityPrices->where('validFrom IS NOT NULL')->orderBy(['validFrom' => 'ASC'])->toArray();
	}

	/**
	 * @param string $basePath
	 * @param string $size
	 * @param bool $fallbackImageSupplied If true, it is expected that property fallbackImage is set on object, otherwise it is selected manually
	 * @throws \Nette\Application\ApplicationException
	 */
	public function getPreviewImage(string $basePath, string $size = 'detail', bool $fallbackImageSupplied = true): string
	{
		if (!Arrays::contains(['origin', 'detail', 'thumb'], $size)) {
			throw new ApplicationException('Invalid product image size: ' . $size);
		}

		$fallbackImage = $fallbackImageSupplied ?
			($this->__isset('fallbackImage') ? $this->getValue('fallbackImage') : null) :
			($this->primaryCategory ? $this->primaryCategory->productFallbackImageFileName : null);

		$image = $this->imageFileName ?: $fallbackImage;
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

		$nowThresholdTime = \Carbon\Carbon::createFromFormat('G:i', $displayDelivery->timeThreshold);

		return $nowThresholdTime > (new \Carbon\Carbon()) ? $displayDelivery->beforeTimeThresholdLabel : $displayDelivery->afterTimeThresholdLabel;
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
	 * @return array<string|array<string>>
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

			if (Strings::length($tmp) > 0) {
				$tmp = Strings::substring($tmp, 0, -2);
			}

			$productAttributesByCode[$attribute->code] = $tmp;
		}

		return \array_merge($this->getSimpleFrontendData(), [
			'producer' => $this->producer ? $this->producer->name : null,
			'attributes' => $productAttributesByCode,
		]);
	}

	/**
	 * @return array<string|array<string>>
	 */
	public function getSimpleFrontendData(): array
	{
		return [
			'name' => $this->name,
			'code' => $this->getFullCode(),
			'ean' => $this->ean,
		];
	}

	/**
	 * @param string|null $supplierCode If null, take supplier with the highest priority
	 * @throws \StORM\Exception\NotFoundException
	 */
	public function getSupplierProduct(?string $supplierCode = null): ?SupplierProduct
	{
		/** @var \Eshop\DB\SupplierProductRepository $supplierProductRepository */
		$supplierProductRepository = $this->getConnection()->findRepository(SupplierProduct::class);

		if ($supplierCode === null) {
			return $supplierProductRepository->many()
				->where('fk_product', $this->getPK())
				->select(['importPriority' => 'supplier.importPriority'])
				->setOrderBy(['importPriority'])
				->first();
		}

		return $supplierProductRepository->many()
			->where('fk_product', $this->getPK())
			->where('supplier.code', $supplierCode)
			->first();
	}

	/**
	 * @return \StORM\Collection<\Eshop\DB\Review>
	 */
	public function getReviews(): Collection
	{
		/** @var \Eshop\DB\ReviewRepository $reviewRepository */
		$reviewRepository = $this->getConnection()->findRepository(Review::class);

		$reviews = $this->reviews;

		$reviewRepository->filterReviewedReviews($reviews);

		return $reviews;
	}

	public function getReviewsRating(): float
	{
		/** @var \Eshop\DB\ReviewRepository $reviewRepository */
		$reviewRepository = $this->getConnection()->findRepository(Review::class);

		$count = $this->defaultReviewsCount ?? 0;
		$score = $count > 0 ? $count * $this->defaultReviewsScore : 0.0;

		try {
			$reviews = $reviewRepository->getReviewedReviews()
				->where('this.fk_product', $this->getPK())
				->select(['sumOfScore' => 'SUM(this.score)'])
				->select(['countOfScore' => 'COUNT(this.score)'])
				->first();

			$score += $reviews->getValue('sumOfScore');
			$count += $reviews->getValue('countOfScore');
		} catch (\Throwable $e) {
		}

		return $count > 0 ? (float) $score / $count : 0;
	}

	public function getReviewsCount(): int
	{
		/** @var \Eshop\DB\ReviewRepository $reviewRepository */
		$reviewRepository = $this->getConnection()->findRepository(Review::class);

		try {
			return $this->defaultReviewsCount + ((int) $reviewRepository->getReviewedReviews()
				->where('this.fk_product', $this->getPK())
				->select(['countOfScore' => 'COUNT(this.score)'])
				->firstValue('countOfScore'));
		} catch (\Throwable $e) {
			return 0;
		}
	}
	
	public function getGoogleExportCategory(): ?string
	{
		if ($this->exportGoogleCategory) {
			return $this->exportGoogleCategory;
		}
		
		if ($this->primaryCategory) {
			$category = $this->primaryCategory;
			$exportGoogleCategory = $this->primaryCategory->exportGoogleCategory;
			
			while ($exportGoogleCategory === null && $category->ancestor !== null) {
				$category = $category->ancestor;
				$exportGoogleCategory = $category->exportGoogleCategory;
			}
			
			return $exportGoogleCategory;
		}
		
		return null;
	}

	private function getQuantityPrice(int $amount, string $property): ?float
	{
		return $this->productRepository->getQuantityPrice($this, $amount, $property);
	}

	/**
	 * @return array<\Eshop\DB\Product>
	 */
	private function doGetAllMergedProducts(Product $product): array
	{
		$products = [];

		foreach ($product->slaveProducts as $mergedProduct) {
			$products[$mergedProduct->getPK()] = $mergedProduct;

			$products = \array_merge($products, $this->doGetAllMergedProducts($mergedProduct));
		}

		return $products;
	}
}
