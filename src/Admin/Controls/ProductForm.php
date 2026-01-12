<?php

declare(strict_types=1);

namespace Eshop\Admin\Controls;

use Admin\Controls\AdminForm;
use Admin\Controls\AdminFormFactory;
use Base\DB\Shop;
use Base\ShopsConfig;
use Eshop\Admin\Configs\ProductFormAutoPriceConfig;
use Eshop\Admin\Configs\ProductFormConfig;
use Eshop\Admin\ProductPresenter;
use Eshop\BackendPresenter;
use Eshop\DB\AmountRepository;
use Eshop\DB\CategoryRepository;
use Eshop\DB\CategoryTypeRepository;
use Eshop\DB\DisplayAmountRepository;
use Eshop\DB\DisplayDeliveryRepository;
use Eshop\DB\InternalRibbonRepository;
use Eshop\DB\LoyaltyProgramProductRepository;
use Eshop\DB\LoyaltyProgramRepository;
use Eshop\DB\PricelistRepository;
use Eshop\DB\PriceRepository;
use Eshop\DB\ProducerRepository;
use Eshop\DB\Product;
use Eshop\DB\ProductContentRepository;
use Eshop\DB\ProductPrimaryCategoryRepository;
use Eshop\DB\ProductRepository;
use Eshop\DB\RelatedRepository;
use Eshop\DB\RelatedTypeRepository;
use Eshop\DB\RibbonRepository;
use Eshop\DB\StoreRepository;
use Eshop\DB\SupplierProductRepository;
use Eshop\DB\SupplierRepository;
use Eshop\DB\TaxRepository;
use Eshop\DB\VatRateRepository;
use Eshop\DB\VisibilityListItemRepository;
use Eshop\DB\VisibilityListRepository;
use Eshop\FormValidators;
use Eshop\Integration\Integrations;
use Eshop\ShopperUser;
use Nette\Application\UI\Control;
use Nette\Application\UI\Presenter;
use Nette\Forms\Form;
use Nette\Utils\Arrays;
use Nette\Utils\Strings;
use StORM\DIConnection;
use StORM\ICollection;
use Tracy\Debugger;
use Web\DB\PageRepository;
use Web\DB\SettingRepository;

class ProductForm extends Control
{
	public const RELATION_EXTRA_ITEMS_COUNT = 5;

	/** @persistent */
	public string $tab = 'menu0';

	/** @var callable(\Eshop\DB\Product|null $product): array<\Eshop\DB\Pricelist>|null */
	public $onRenderGetPriceLists;

	/** @var array<\Eshop\DB\Pricelist> */
	protected array $priceLists;

	private ?Product $product;

	private int $relationExtraItemsCount;

	/**
	 * @var array<\Eshop\DB\RelatedType>
	 */
	private array $relatedTypes;

	public function __construct(
		AdminFormFactory $adminFormFactory,
		private readonly PageRepository $pageRepository,
		private readonly ProductRepository $productRepository,
		private readonly PricelistRepository $pricelistRepository,
		private readonly ProductContentRepository $productContentRepository,
		private readonly PriceRepository $priceRepository,
		private readonly SupplierRepository $supplierRepository,
		private readonly SupplierProductRepository $supplierProductRepository,
		CategoryRepository $categoryRepository,
		RibbonRepository $ribbonRepository,
		InternalRibbonRepository $internalRibbonRepository,
		private readonly ProducerRepository $producerRepository,
		private readonly VatRateRepository $vatRateRepository,
		DisplayAmountRepository $displayAmountRepository,
		DisplayDeliveryRepository $displayDeliveryRepository,
		private readonly ShopsConfig $shopsConfig,
		TaxRepository $taxRepository,
		private readonly ShopperUser $shopperUser,
		private readonly CategoryTypeRepository $categoryTypeRepository,
		private readonly LoyaltyProgramRepository $loyaltyProgramRepository,
		private readonly LoyaltyProgramProductRepository $loyaltyProgramProductRepository,
		private readonly RelatedTypeRepository $relatedTypeRepository,
		private readonly RelatedRepository $relatedRepository,
		private readonly StoreRepository $storeRepository,
		private readonly AmountRepository $amountRepository,
		private readonly ProductPrimaryCategoryRepository $productPrimaryCategoryRepository,
		private readonly VisibilityListRepository $visibilityListRepository,
		private readonly VisibilityListItemRepository $visibilityListItemRepository,
		SettingRepository $settingRepository,
		Integrations $integrations,
		private readonly \Base\Application $application,
		private readonly \Nette\Caching\Storage $storage,
		$product = null,
		$onRenderGetPriceLists = null,
		private readonly array $configuration = []
	) {
		$this->product = $product = $productRepository->get($product);
		$this->onRenderGetPriceLists = $onRenderGetPriceLists;
		$this->priceLists = $this->onRenderGetPriceLists ? \call_user_func($this->onRenderGetPriceLists, $this->product) : $this->pricelistRepository->many()->orderBy(['this.priority'])->toArray();
		$this->relationExtraItemsCount = (int) ($settingRepository->getValueByName('relationMaxItemsCount') ?? $this::RELATION_EXTRA_ITEMS_COUNT);

		$form = $adminFormFactory->create(true);

		$this->monitor(Presenter::class, function (ProductPresenter $productPresenter) use ($form): void {
			$form->addHidden('editTab')->setDefaultValue($productPresenter->editTab);
		});

		$form->addGroup('Hlavní atributy');

		$form->addText('code', 'Kód a podsklad')->setRequired();
		$form->addText('subCode', 'Kód podskladu');
		$form->addText('ean', 'Hlavní EAN (unikátní)')->setNullable();

		$minimalRecommendedPrice = $form->addText('minimalRecommendedPrice', 'Minimální doporučená cena pro schválení managerem')
			->setNullable()
			->setHtmlType('number')
			->setHtmlAttribute('step', 'any');
		$minimalRecommendedPrice->addRule(Form::Float);

		if (isset($this->configuration['secondaryEan']) && $this->configuration['secondaryEan']) {
			$form->addText('secondaryEan', 'Sekundární EAN')->setNullable();
		}

		$form->addText('mpn', 'P/N')->setNullable();

		if (isset($configuration['externalCode']) && $configuration['externalCode']) {
			$form->addText('externalCode', 'Externí kód')->setNullable();
			$form->addText('externalId', 'Externí id')->setNullable();
		}

		$form->addLocaleText('extendedName', 'Rozšířený název');
		$nameInput = $form->addLocaleText('name', 'Název');

		$form->addText('deletedTs', 'Čas smazání')
			->setHtmlAttribute('data-info', 'Čas vyřazení produktu. Vyplňuje se automaticky.')
			->setNullable();
		$form->addSelect('vatRate', 'Úroveň DPH (%)', $vatRateRepository->getDefaultVatRates());

		/** @var array<\Eshop\DB\CategoryType> $categoryTypes */
		$categoryTypes = $this->categoryTypeRepository->getCollection(true)->toArray();

		$categoriesContainer = $form->addContainer('categories');
		$allCategories = [];

		foreach ($categoryTypes as $categoryType) {
			$categories = $categoryRepository->getTreeArrayForSelect(true, $categoryType->getPK());
			$allCategories = Arrays::mergeTree($allCategories, $categories);

			$categoriesContainer->addMultiSelect2($categoryType->getPK(), 'Kategorie: ' . $categoryType->name . ($categoryType->shop ? " (O:{$categoryType->shop->name})" : ''), $categories);
		}

		$primaryCategoriesContainer = $form->addContainer('primaryCategories');
		$primaryCategories = $product ? $product->primaryCategories
			->select(['categoryPK' => 'this.fk_category'])
			->setIndex('this.fk_categoryType')
			->toArrayOf('categoryPK') : [];

		foreach ($categoryTypes as $categoryType) {
			$productCategories = [];

			if ($this->product) {
				$assignedProductCategories = $this->product->getCategories()->where('this.fk_type', $categoryType->getPK())->toArray();

				$productCategories = \array_filter($allCategories, function ($key) use ($assignedProductCategories) {
					return isset($assignedProductCategories[$key]);
				}, \ARRAY_FILTER_USE_KEY);
			}

			$primaryCategoriesContainer->addDataSelect(
				'primaryCategory_' . $categoryType->getPK(),
				'Primární kategorie: ' . $categoryType->name . ($categoryType->shop ? " (O:{$categoryType->shop->name})" : ''),
				$productCategories,
			)
				->setPrompt('Automaticky')
				->checkDefaultValue(false)
				->setDefaultValue($primaryCategories[$categoryType->getPK()] ?? null)
				->setHtmlAttribute('data-info', 'Primární kategorie je důležitá pro zobrazování drobečkovky produktu, výchozímu obsahu a obrázku a dalších. 
	V případě zvolení kategorie do které již nepatří, se zvolí automaticky jedna z přiřazených.');
		}

		$form->addSelect2('producer', 'Výrobce', $this->producerRepository->getArrayForSelect())->setPrompt('Nepřiřazeno');

		$form->addDataMultiSelect('ribbons', 'Veřejné štítky', $ribbonRepository->getArrayForSelect());
		$form->addDataMultiSelect('internalRibbons', 'Interní štítky', $internalRibbonRepository->getArrayForSelect(type: 'product'));

		$form->addTextArea('relatedTags', 'Tagy pro související produkty')
			->setNullable()
			->setHtmlAttribute('placeholder', 'HP LaserJet Pro M15, HP LaserJet Pro M28')
			->setHtmlAttribute('rows', 3)
			->setHtmlAttribute('data-info', 'Čárkou oddělené hodnoty. Porovnávají se se slaveName v Related záznamech pro matching tonerů k tiskárnám.');

		$form->addSelect(
			'displayAmount',
			'Dostupnost',
			$displayAmountRepository->getArrayForSelect(),
		)->setPrompt('Nepřiřazeno');
		$form->addSelect(
			'displayDelivery',
			'Doručení',
			$displayDeliveryRepository->getArrayForSelect(),
		)->setPrompt('Automaticky');

		if (isset($configuration['suppliers']) && $configuration['suppliers'] && $this->supplierRepository->many()->count() > 0) {
			$locks = [];

			if ($product) {
				/** @var \Eshop\DB\Supplier $supplier */
				foreach ($supplierRepository->many()->join(['products' => 'eshop_supplierproduct'], 'products.fk_supplier=this.uuid')->where('products.fk_product', $product) as $supplier) {
					$locks[$supplier->getPK()] = $supplier->name;
				}
			}

			$locks[Product::SUPPLIER_CONTENT_MODE_NONE] = '! Nikdy nepřebírat';

			$form->addSelect('supplierContent', 'Přebírat obsah', $locks)->setPrompt('S nejvyšší prioritou')
				->setHtmlAttribute('data-info', 'Nastavení přebírání obsahu (jméno, perex, obsah) ze zdrojů.<br><br>
S nejvyšší prioritou: Zdroj s nejvyšší prioritou<br>
Nikdy nepřebírat: Obsah nebude nikdy přebírán<br>
Ostatní: Přebírání ze zvoleného zdroje
');

			$supplierDisplayAmountMergedLockInput = $form->addCheckbox('supplierDisplayAmountMergedLock', 'Nepřebírat skladovost od sloučených produktů');
			$supplierDisplayAmountLockInput = $form->addCheckbox('supplierDisplayAmountLock', 'Nepřebírat žádnou skladovost');

			$supplierDisplayAmountLockInput->addConditionOn($supplierDisplayAmountMergedLockInput, $form::EQUAL, true)->toggle($supplierDisplayAmountLockInput->getHtmlId() . '-toogle');

			$form->addCheckbox('importSupplierImages', 'Importovat fotky od dodavatelů');
		}

		$form->addText('storageDate', 'Nejbližší datum naskladnění')->setNullable(true)->setHtmlType('date');

		$form->addGroup('Nákup');

		$manualPurchasePrice = $form->addText('manualPurchasePrice', 'Manuálně zadaná nákupní cena')
			->setNullable()
			->setHtmlType('number')
			->setHtmlAttribute('step', 'any')
			->setHtmlAttribute('data-info', 'Nákupní cena pro výpočet marže v nabídce, pokud neexistuje dodavatelský produkt.');
		$manualPurchasePrice->addCondition($form::Filled)->addRule($form::Float);

		$form->addLocaleText('unit', 'Jednotka');
		//	->setHtmlAttribute('data-info', 'Např.: ks, ml, ...');

		$this->monitor(BackendPresenter::class, function (BackendPresenter $backendPresenter) use ($form, $configuration): void {
			if (isset($configuration['discountLevel']) && $configuration['discountLevel'] && $backendPresenter->isManager) {
				$form->addInteger('discountLevelPct', 'Procentuální sleva (%)')
					->setHtmlAttribute(
						'data-info',
						'Aplikuje se vždy největší z čtveřice: procentuální slevy produktu, procentuální slevy zákazníka, slevy věrnostního programu zákazníka nebo slevového kupónu.<br>
Platí jen pokud má ceník povoleno "Povolit procentuální slevy".',
					)
					->setRequired()
					->setDefaultValue(0);
			}
		});

		$form->addInteger('defaultBuyCount', 'Předdefinované množství')->setRequired()->setDefaultValue(1);
		$form->addInteger('minBuyCount', 'Minimální množství')->setRequired()->setDefaultValue(1);
		$form->addIntegerNullable('maxBuyCount', 'Maximální množství');
		$form->addInteger('buyStep', 'Krokové množství')->setDefaultValue(1);
		$form->addIntegerNullable('inPackage', 'Počet v balení');
		$form->addIntegerNullable('inCarton', 'Počet v kartónu');
		$form->addIntegerNullable('inPalett', 'Počet v paletě');

		$exportNoneInput = $form->addCheckbox('exportNone', 'Skrýt všude');
		$exportHeurekaInput = $form->addCheckbox('exportHeureka', 'Exportovat do Heureky');
		$exportGoogleInput = $form->addCheckbox('exportGoogle', 'Exportovat do Google');
		$exportZboziInput = $form->addCheckbox('exportZbozi', 'Exportovat do Zboží');

		$exportHeurekaInput->addConditionOn($exportNoneInput, $form::EQUAL, false)->toggle($exportHeurekaInput->getHtmlId() . '-toogle');
		$exportGoogleInput->addConditionOn($exportNoneInput, $form::EQUAL, false)->toggle($exportGoogleInput->getHtmlId() . '-toogle');
		$exportZboziInput->addConditionOn($exportNoneInput, $form::EQUAL, false)->toggle($exportZboziInput->getHtmlId() . '-toogle');


		$defaultReviewsCount = $form->addIntegerNullable('defaultReviewsCount', 'Výchozí počet recenzí');

		$defaultReviewsScore = $form->addText('defaultReviewsScore', 'Výchozí hodnocení recenzí')->setNullable()
		->setHtmlAttribute(
			'data-info',
			'Zobrazované hodnocení produktu se počítá jako průměr výchozích hodnocení (počet výchozích recenzí * výchozí hodnocení recenzí) ve spojení se skutečnými recenzemi.<br>
Vyplňujte celá nebo desetinná čísla v intervalu ' . $this->shopperUser->getReviewsMinScore() . ' - ' . $this->shopperUser->getReviewsMaxScore() . ' (včetně)',
		);

		$defaultReviewsScore->addConditionOn($defaultReviewsCount, $form::FILLED)
			->addRule($form::REQUIRED)
			->addCondition($form::FILLED)
			->addRule($form::FLOAT);

		$defaultReviewsCount->addCondition($form::FILLED)
			->toggle($defaultReviewsScore->getHtmlId() . '-toogle');

		if (isset($configuration['karsa']) && $configuration['karsa']) {
			$form->addCheckbox('karsaAllowRepricing', 'Povolit přecenění')->setDefaultValue(true);
		}

		if ($integrations->getService(Integrations::ALGOLIA)) {
			$form->addInteger('algoliaPriority', 'Priorita')->setDefaultValue(10);
		}

		if (isset($configuration['rounding']) && $configuration['rounding']) {
			$form->addIntegerNullable('roundingPackagePct', 'Zokrouhlení balení (%)');
			$form->addIntegerNullable('roundingCartonPct', 'Zokrouhlení karton (%)');
			$form->addIntegerNullable('roundingPalletPct', 'Zokrouhlení paletu (%)');
		}

		$form->addText('dependedValue', 'Závislá cena (%)')
			->setNullable()
			->addCondition($form::FILLED)
			->addRule($form::FLOAT)
			->addRule([FormValidators::class, 'isPercentNoMax'], 'Neplatná hodnota!');

		if (isset($configuration['weightAndDimension']) && $configuration['weightAndDimension']) {
			$form->addFloat('weight', 'Váha')
				->setHtmlAttribute('data-info', 'Celková váha produktu.')
				->setNullable()
				->addCondition($form::FILLED)
				->addRule($form::FLOAT);
			$form->addFloat('width', 'Šířka')->setNullable();
			$form->addFloat('length', 'Délka')->setNullable();
			$form->addFloat('depth', 'Hloubka')->setNullable();
			$form->addCheckbox('keepFlat', 'Přepravovat naležato');
		}

		if (isset($configuration['loyaltyProgram']) && $configuration['loyaltyProgram']) {
			$loyaltyProgramContainer = $form->addContainer('loyaltyProgram');

			if ($this->product !== null) {
				$productLoyaltyPrograms = $this->loyaltyProgramProductRepository->many()->setIndex('fk_loyaltyProgram')->where('fk_product', $this->product->getPK())->toArrayOf('points');
			}

			/** @var \Eshop\DB\LoyaltyProgram $loyaltyProgram */
			foreach ($this->loyaltyProgramRepository->many() as $loyaltyProgram) {
				$input = $loyaltyProgramContainer->addText($loyaltyProgram->getPK(), $loyaltyProgram->name)->setNullable();
				$input->addCondition($form::FILLED)->addRule($form::FLOAT)->endCondition();

				if (!isset($productLoyaltyPrograms[$loyaltyProgram->getPK()])) {
					continue;
				}

				$input->setDefaultValue($productLoyaltyPrograms[$loyaltyProgram->getPK()]);
			}
		}

		if (isset($configuration['taxes']) && $configuration['taxes']) {
			$form->addDataMultiSelect('taxes', 'Poplatky a daně', $taxRepository->getArrayForSelect());
		}

		$form->addText('lastInStockTs', 'Čas posledního naskladnění')
			->setHtmlAttribute('data-info', 'Čas posledního naskladnění u jakéhokoliv dodavatele. Vyplňuje se automaticky.')
			->setNullable();

		// Relations - nyní spravováno Alpine.js komponentou v productRelations.latte
		// Inicializace relatedTypes pro template
		$this->monitor(Presenter::class, function ($presenter): void {
			$this->relatedTypes = $this->template->relatedTypes = $this->relatedTypeRepository->many()->toArray();
		});

		$contentContainer = $form->addContainer('content');

		if (!$this->shopsConfig->getAvailableShops()) {
			$contentContainer->addLocalePerexEdit('perex', 'Popisek');
			$contentContainer->addLocaleRichEdit('content', 'Obsah');
		}

		foreach ($this->shopsConfig->getAvailableShops() as $shop) {
			$contentContainer->addLocalePerexEdit('perex_' . $shop->getPK(), 'Popisek');
			$contentContainer->addLocaleRichEdit('content_' . $shop->getPK(), 'Obsah');
		}

		$visibilityContainer = $form->addContainer('visibility');
		$productVisibilityListItems = $this->product ? $this->product->visibilityListItems->setIndex('fk_visibilityList')->toArray() : [];

		foreach ($this->visibilityListRepository->many() as $visibilityList) {
			$itemContainer = $visibilityContainer->addContainer('list_' . $visibilityList->getPK());

			$activeInput = $itemContainer->addCheckbox('active');
			$hiddenInput = $itemContainer->addCheckbox('hidden');
			$hiddenInMenuInput = $itemContainer->addCheckbox('hiddenInMenu');
			$unavailableInput = $itemContainer->addCheckbox('unavailable');
			$recommendedInput = $itemContainer->addCheckbox('recommended');
			$priorityInput = $itemContainer->addInteger('priority')->setRequired()->setDefaultValue(10);

			$activeInput->addCondition($form::Filled)
				->toggle($hiddenInput->getHtmlId() . '-toogle')
				->toggle($hiddenInMenuInput->getHtmlId() . '-toogle')
				->toggle($unavailableInput->getHtmlId() . '-toogle')
				->toggle($recommendedInput->getHtmlId() . '-toogle')
				->toggle($priorityInput->getHtmlId() . '-toogle');

			if (!isset($productVisibilityListItems[$visibilityList->getPK()])) {
				continue;
			}

			$item = $productVisibilityListItems[$visibilityList->getPK()];

			$itemContainer->setDefaults([
				'active' => true,
				'hidden' => $item->hidden,
				'hiddenInMenu' => $item->hiddenInMenu,
				'unavailable' => $item->unavailable,
				'recommended' => $item->recommended,
				'priority' => $item->priority,
			]);
		}

		$this->monitor(Presenter::class, function (BackendPresenter $presenter) use ($form, $storeRepository): void {
			$prices = $form->addContainer('prices');

			$pricesPermission = $presenter->admin->isAllowed(':Eshop:Admin:Pricelists:default');
			/** @var null|string $autoPriceConfig */
			$autoPriceConfig = $this->configuration[ProductFormConfig::class][ProductFormAutoPriceConfig::class] ?? null;

			foreach ($this->priceLists as $prc) {
				$pricelist = $prices->addContainer($prc->getPK());
				$pricelist->addText('price')
					->setNullable()
					->setDisabled(!$pricesPermission || $autoPriceConfig === ProductFormAutoPriceConfig::WITHOUT_VAT || $prc->isReadonly)
					->addCondition($form::FILLED)
					->addRule($form::FLOAT);
				$pricelist->addText('priceVat')
					->setNullable()
					->setDisabled(!$pricesPermission || $autoPriceConfig === ProductFormAutoPriceConfig::WITH_VAT || $prc->isReadonly)
					->addCondition($form::FILLED)
					->addRule($form::FLOAT);
				$pricelist->addText('priceBefore')
					->setNullable()
					->setDisabled(!$pricesPermission || $autoPriceConfig === ProductFormAutoPriceConfig::WITHOUT_VAT || $prc->isReadonly)
					->addCondition($form::FILLED)
					->addRule($form::FLOAT);
				$pricelist->addText('priceVatBefore')
					->setNullable()
					->setDisabled(!$pricesPermission || $autoPriceConfig === ProductFormAutoPriceConfig::WITH_VAT || $prc->isReadonly)
					->addCondition($form::FILLED)
					->addRule($form::FLOAT);
				$pricelist->addCheckbox('hidden')
					->setDisabled(!$pricesPermission || $prc->isReadonly);
			}

			$stores = $form->addContainer('stores');

			/** @var \Eshop\DB\Store $store */
			foreach ($storeRepository->many() as $store) {
				$storeContainer = $stores->addContainer($store->getPK());
				$storeContainer->addInteger('inStock')->setNullable();
				$storeContainer->addInteger('reserved')->setNullable();
				$storeContainer->addInteger('ordered')->setNullable();
			}
		});

		if (isset($configuration['buyCount']) && $configuration['buyCount']) {
			$form->addIntegerNullable('buyCount', 'Počet prodaných')
				->setHtmlAttribute(
					'data-info',
					'Pokud necháte prázdné tak se bude vypočítávat ze skutečných nákupů. Pokud chcete vygenerovat náhodné hodnoty, použijte tlačítko "Generovat zakoupení" na seznamu produktů.',
				)
				->addFilter('intval')->addCondition($form::FILLED)->addRule($form::MIN, 'Zadejte číslo rovné nebo větší než 0!', 0);
		}

		$form->addPageContainer(
			'product_detail',
			['product' => $product],
			$nameInput,
			false,
			true,
			false,
			'URL a SEO',
			isset($configuration['showPageOgImage']) && $configuration['showPageOgImage'],
			true,
		);

		$form->addSubmits(!$product);

		$form->onValidate[] = [$this, 'validate'];
		$form->onSuccess[] = [$this, 'submit'];

		$this->addComponent($form, 'form');
	}

	public function validate(AdminForm $form): void
	{
		Debugger::barDump($form->getErrors());

		if (!$form->isValid()) {
			return;
		}

		$values = $form->getValues('array');

		if ($values['ean']) {
			/** @var \Nette\Forms\Controls\TextInput $eanInput */
			$eanInput = $form['ean'];

			if ($product = $this->productRepository->many()->where('ean', $values['ean'])->first()) {
				if ($this->product) {
					if ($product->getPK() !== $this->product->getPK()) {
						$eanInput->addError('Již existuje produkt s tímto EAN');
					}
				} else {
					$eanInput->addError('Již existuje produkt s tímto EAN');
				}
			}
		}

		$product = $this->productRepository->many();

		if ($values['code']) {
			$product = $product->where('code', $values['code']);
		}

		if ($values['subCode']) {
			$product = $product->where('subCode', $values['subCode']);
		}

		$product = $product->first();

		if ((!$values['code'] && !$values['subCode']) || !$product) {
			return;
		}

		/** @var \Nette\Forms\Controls\TextInput $codeInput */
		$codeInput = $form['code'];

		if ($this->product) {
			if ($product->getPK() !== $this->product->getPK()) {
				$codeInput->addError('Již existuje produkt s touto kombinací kódu a subkódu');
			}
		} else {
			$codeInput->addError('Již existuje produkt s touto kombinací kódu a subkódu');
		}
	}

	public function submit(AdminForm $form): void
	{
		$values = $form->getValues('array');
		$editTab = Arrays::pick($values, 'editTab', null);

		$oldValues = $this->product ? $this->product->toArray() : [];

		if (!$values['uuid']) {
			$values['uuid'] = DIConnection::generateUuid();
		}

		if (isset($values['supplierContent'])) {
			if ($values['supplierContent'] === 'none') {
				$values['supplierContent'] = null;
				$values['supplierContentLock'] = true;
				$values['supplierContentMode'] = Product::SUPPLIER_CONTENT_MODE_NONE;
			} else {
				$values['supplierContentLock'] = false;
				$values['supplierContentMode'] = Product::SUPPLIER_CONTENT_MODE_SUPPLIER;
			}
		} else {
			$values['supplierContent'] = null;
			$values['supplierContentLock'] = false;
			$values['supplierContentMode'] = Product::SUPPLIER_CONTENT_MODE_PRIORITY;
		}

		/** @var array $pickedCategories */
		$pickedCategories = Arrays::pick($values, 'categories');
		/** @var array<string> $newCategories */
		$newCategories = [];

		if (\count($pickedCategories) > 0) {
			foreach ($pickedCategories as $categories) {
				foreach ($categories as $category) {
					$newCategories[] = $category;
				}
			}
		}

		/** @var array<mixed> $primaryCategories */
		$primaryCategories = Arrays::pick($values, 'primaryCategories', []);

		/** @var array<mixed> $content */
		$content = Arrays::pick($values, 'content', []);

		/** @var array<mixed> $visibility */
		$visibility = Arrays::pick($values, 'visibility', []);

		if ($values['exportNone']) {
			$values['exportHeureka'] = false;
			$values['exportGoogle'] = false;
			$values['exportZbozi'] = false;
		}

		if (isset($values['customContainer'])) {
			$customContainer = $values['customContainer'];
			unset($values['customContainer']);

			$values = \array_merge($values, $customContainer);
		}

		/** @var \Eshop\DB\Product $product */
		$product = $this->productRepository->syncOne($values, null, true);

		$product->categories->unrelateAll();

		if (\count($newCategories) > 0) {
			$product->categories->relate($newCategories);
		}

		foreach ($primaryCategories as $categoryTypePK => $primaryCategory) {
			$categoryTypePK = \explode('_', $categoryTypePK)[1];

			if (!Arrays::contains($newCategories, $primaryCategory)) {
				$newCategory = $product->getCategories()->where('this.fk_type', $primaryCategory)->first();

				if ($newCategory) {
					$primaryCategory = $newCategory->getPK();
				}
			}

			$this->productPrimaryCategoryRepository->syncOne([
				'product' => $product->getPK(),
				'categoryType' => $categoryTypePK,
				'category' => $primaryCategory,
			]);
		}

		// Relations - nyní spravováno Alpine.js komponentou přes API handlery
		// handleGetProductRelations a handleSaveProductRelations v ProductPresenter

		// Loyalty programs
		$this->loyaltyProgramProductRepository->many()->where('fk_product', $product->getPK())->delete();

		/** @var array<string, float|null> $loyaltyPrograms */
		$loyaltyPrograms = Arrays::pick($values, 'loyaltyProgram', []);

		if (\count($loyaltyPrograms) > 0) {
			foreach ($loyaltyPrograms as $loyaltyProgram => $points) {
				if ($points === null) {
					continue;
				}

				$this->loyaltyProgramProductRepository->createOne([
					'points' => $points,
					'product' => $product->getPK(),
					'loyaltyProgram' => $loyaltyProgram,
				]);
			}
		}

		$changeColumns = ['name', 'perex', 'content'];

		if ($product->getParent() instanceof ICollection && $product->getParent()->getAffectedNumber() > 0) {
			foreach ($form->getMutations() as $mutation) {
				foreach ($changeColumns as $column) {
					if (isset($oldValues[$column][$mutation]) && $oldValues[$column][$mutation] !== $values[$column][$mutation]) {
						$product->update(['supplierContentLock' => true]);

						break 2;
					}
				}
			}
		}

		/** @var \Eshop\BackendPresenter $presenter */
		$presenter = $this->getPresenter();

		$pricesPermission = $presenter->admin->isAllowed(':Eshop:Admin:Pricelists:default');

		if ($pricesPermission) {
			foreach ($values['prices'] as $pricelistId => $prices) {
				$pricelist = $this->pricelistRepository->one($pricelistId);

				if ($pricelist->isReadonly) {
					continue;
				}

				/** @var null|string $autoPriceConfig */
				$autoPriceConfig = $this->configuration[ProductFormConfig::class][ProductFormAutoPriceConfig::class] ?? null;

				// Delete price record if both price and priceVat are null (for bidirectional mode)
				// or based on config-specific requirements
				$shouldDelete = false;

				if ($autoPriceConfig === null || $autoPriceConfig === ProductFormAutoPriceConfig::NONE) {
					// Bidirectional mode: delete only if BOTH prices are null
					$shouldDelete = $prices['price'] === null && $prices['priceVat'] === null;
				} elseif ($autoPriceConfig === ProductFormAutoPriceConfig::WITH_VAT) {
					// WITH_VAT mode: user enters price, delete if price is null
					$shouldDelete = $prices['price'] === null;
				} elseif ($autoPriceConfig === ProductFormAutoPriceConfig::WITHOUT_VAT) {
					// WITHOUT_VAT mode: user enters priceVat, delete if priceVat is null
					$shouldDelete = $prices['priceVat'] === null;
				}

				if ($shouldDelete) {
					$this->priceRepository->many()
						->where('this.fk_pricelist', $pricelistId)
						->where('this.fk_product', $values['uuid'])
						->delete();

					continue;
				}

				$vatRate = $this->vatRateRepository->getDefaultVatRates()[$product->vatRate];

				if ($autoPriceConfig === ProductFormAutoPriceConfig::WITHOUT_VAT) {
					$prices['price'] = \round($prices['priceVat'] * \fdiv(100, 100 + $vatRate), ShopperUser::PRICE_PRECISSION);
					$prices['priceBefore'] = isset($prices['priceVatBefore']) ?
						\round($prices['priceVatBefore'] * \fdiv(100, 100 + $vatRate), ShopperUser::PRICE_PRECISSION) :
						null;
				}

				if ($autoPriceConfig === ProductFormAutoPriceConfig::WITH_VAT) {
					$prices['priceVat'] = \round($prices['price'] * \fdiv(100 + $vatRate, 100), ShopperUser::PRICE_PRECISSION);
					$prices['priceVatBefore'] = isset($prices['priceBefore']) ?
						\round($prices['priceBefore'] * \fdiv(100 + $vatRate, 100), ShopperUser::PRICE_PRECISSION) :
						null;
				}

				// Bidirectional auto-calculation when config is NONE or not set
				if ($autoPriceConfig === null || $autoPriceConfig === ProductFormAutoPriceConfig::NONE) {
					// Calculate priceVat from price
					if ($prices['price'] !== null && $prices['priceVat'] === null) {
						$prices['priceVat'] = \round($prices['price'] * \fdiv(100 + $vatRate, 100), ShopperUser::PRICE_PRECISSION);
					}

					// Calculate price from priceVat
					if ($prices['priceVat'] !== null && $prices['price'] === null) {
						$prices['price'] = \round($prices['priceVat'] * \fdiv(100, 100 + $vatRate), ShopperUser::PRICE_PRECISSION);
					}

					// Same for "before" prices
					if ($prices['priceBefore'] !== null && $prices['priceVatBefore'] === null) {
						$prices['priceVatBefore'] = \round($prices['priceBefore'] * \fdiv(100 + $vatRate, 100), ShopperUser::PRICE_PRECISSION);
					}

					if ($prices['priceVatBefore'] !== null && $prices['priceBefore'] === null) {
						$prices['priceBefore'] = \round($prices['priceVatBefore'] * \fdiv(100, 100 + $vatRate), ShopperUser::PRICE_PRECISSION);
					}
				}

				$conditions = [
					'pricelist' => $pricelistId,
					'product' => $values['uuid'],
				];

				$this->priceRepository->syncOne($conditions + $prices);
			}
		}

		unset($values['prices']);

		if (!$this->shopsConfig->getAvailableShops()) {
			$conditions = [
				'product' => $product->getPK(),
			];

			$conditions['perex'] = $content['perex'];
			$conditions['content'] = $content['content'];

			$productContent = $this->productContentRepository->many()->where('fk_product', $product->getPK())->first();

			if ($productContent) {
				$productContent->update($conditions);
			} else {
				$this->productContentRepository->createOne($conditions);
			}
		}

		foreach ($this->shopsConfig->getAvailableShops() as $shop) {
			$conditions = [
				'shop' => $shop->getPK(),
				'product' => $product->getPK(),
			];

			$conditions['perex'] = $content['perex_' . $shop->getPK()];
			$conditions['content'] = $content['content_' . $shop->getPK()];

			$this->productContentRepository->syncOne($conditions);
		}

		$product->visibilityListItems->delete();

		foreach ($visibility as $itemId => $item) {
			if (!$item['active']) {
				continue;
			}

			$this->visibilityListItemRepository->syncOne([
				'product' => $product->getPK(),
				'visibilityList' => Strings::after($itemId, 'list_'),
				'priority' => $item['priority'],
				'hidden' => $item['hidden'],
				'hiddenInMenu' => $item['hiddenInMenu'],
				'unavailable' => $item['unavailable'],
				'recommended' => $item['recommended'],
			]);
		}

		foreach ($values['stores'] as $storeId => $amount) {
			if ($amount['inStock'] === null) {
				$this->amountRepository->many()->where('fk_product', $product->getPK())->where('fk_store', $storeId)->delete();

				continue;
			}

			$this->amountRepository->syncOne(['product' => $product->getPK(), 'store' => $storeId] + $amount);
		}

		unset($values['stores']);

		$form->syncPages(function (array $values, Shop|null $shop) use ($product, $form): void {
			$form->uploadOpenGraphImage($form, $values, $shop);
			$this->pageRepository->syncPage($values, ['product' => $product->getPK()]);
		});

		$presenter = $this->getPresenter();

		if ($presenter instanceof ProductPresenter) {
			Arrays::invoke($presenter->onProductFormSuccess, $product, $values);
		}

		$this->productRepository->clearCache();

		$this->getPresenter()->flashMessage('Uloženo', 'success');
		$form->processRedirect('edit', 'default', ['product' => $product, 'editTab' => $editTab]);
	}

	public function render(): void
	{
		$mergedProducts = $this->product ? $this->product->getAllMergedProducts(false) : [];

		if ($this->product) {
			$mergedProducts[$this->product->getPK()] = $this->product;
		}

		$this->template->supplierProducts = $this->product ? $this->supplierProductRepository->many()
			->join(['supplier' => 'eshop_supplier'], 'this.fk_supplier = supplier.uuid')
			->orderBy(['supplier.importPriority'])
			->where('this.fk_product', \array_keys($mergedProducts))
			->toArray() : [];

		$this->template->relationMaxItemsCount = $this->relationExtraItemsCount;
		$this->template->product = $this->getPresenter()->getParameter('product');
		$this->template->pricelists = $this->priceLists;
		$this->template->visibilityLists = $this->shopsConfig->selectFullNameInShopEntityCollection($this->visibilityListRepository->many());
		$this->template->stores = $this->storeRepository->many()->orderBy(['this.name' . $this->storeRepository->getConnection()->getMutationSuffix()]);
		$this->template->configuration = $this->configuration;
		$this->template->shopper = $this->shopperUser;
		$this->template->shops = $this->shopsConfig->getAvailableShops();

		$this->template->productFullTree = $this->product ? $this->productRepository->getProductFullTree($this->product) : [];

		if ($this->product) {
			// Relation types counts
			foreach ($this->relatedTypes as $relatedType) {
				$this->template->masterRelationsCount[$relatedType->getPK()] = $this->relatedRepository->many()
					->where('this.fk_type', $relatedType->getPK())
					->where('this.fk_master', $this->product->getPK())
					->count();

				$this->template->slaveRelationsCount[$relatedType->getPK()] = $this->relatedRepository->many()
					->where('this.fk_type', $relatedType->getPK())
					->where('this.fk_slave', $this->product->getPK())
					->count();
			}
		}

		$this->template->modals = [
			'name' => 'frm-productForm-form-name-cs',
			'perex' => 'frm-perex-cs',
			'content' => 'frm-content-cs',
		];

		// Timestamp pro cache busting JS souborů
		$this->template->ts = $this->application->getEnvironment() === 'production'
			? (new \Nette\Caching\Cache($this->storage))->call('time')
			: \time();

		/** @var \Nette\Bridges\ApplicationLatte\Template $template */
		$template = $this->template;
		$template->render(__DIR__ . '/productForm.latte');
	}

	public function handleClearPrice(string $productPK, string $pricelistPK): void
	{
		$pricelist = $this->pricelistRepository->one($pricelistPK);

		if ($pricelist !== null && $pricelist->isReadonly) {
			$this->getPresenter()->flashMessage('Ceník je pouze pro čtení', 'error');
			$this->getPresenter()->redirect('this');
		}

		$this->priceRepository->many()
			->where('this.fk_product', $productPK)
			->where('this.fk_pricelist', $pricelistPK)
			->delete();

		$this->getPresenter()->flashMessage('Provedeno', 'success');
		$this->getPresenter()->redirect('this');
	}

	public function handleClearVisibilityList(string $productPK, string $visibilityListPK): void
	{
		$this->visibilityListItemRepository->many()
			->where('this.fk_product', $productPK)
			->where('this.fk_visibilityList', $visibilityListPK)
			->delete();

		$this->getPresenter()->flashMessage('Provedeno', 'success');
		$this->getPresenter()->redirect('this');
	}

	public function handleUnlinkSupplierProduct(string $supplierProduct): void
	{
		$supplierProduct = $this->supplierProductRepository->one($supplierProduct, true);

		$supplierProduct->update(['product' => null, 'active' => false,]);

		$this->getPresenter()->flashMessage('Provedeno', 'success');
		$this->getPresenter()->redirect('this');
	}

	public function handleUnmergeProduct(string $product): void
	{
		$product = $this->productRepository->one($product, true);

		$product->update(['masterProduct' => null]);

		$this->getPresenter()->flashMessage('Provedeno', 'success');
		$this->getPresenter()->redirect('this');
	}
}
