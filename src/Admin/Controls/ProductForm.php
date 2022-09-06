<?php

declare(strict_types=1);

namespace Eshop\Admin\Controls;

use Admin\Controls\AdminForm;
use Admin\Controls\AdminFormFactory;
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
use Eshop\DB\ProductRepository;
use Eshop\DB\ProductTabRepository;
use Eshop\DB\ProductTabTextRepository;
use Eshop\DB\RelatedRepository;
use Eshop\DB\RelatedTypeRepository;
use Eshop\DB\RibbonRepository;
use Eshop\DB\StoreRepository;
use Eshop\DB\SupplierProductRepository;
use Eshop\DB\SupplierRepository;
use Eshop\DB\TaxRepository;
use Eshop\DB\VatRateRepository;
use Eshop\FormValidators;
use Eshop\Shopper;
use Nette\Application\UI\Control;
use Nette\Application\UI\Presenter;
use Nette\Utils\Arrays;
use StORM\ICollection;
use Web\DB\PageRepository;
use Web\DB\SettingRepository;

class ProductForm extends Control
{
	public const RELATION_MAX_ITEMS_COUNT = 10;

	/** @persistent */
	public string $tab = 'menu0';

	public ProductTabRepository $productTabRepository;

	public ProductTabTextRepository $productTabTextRepository;

	private ?Product $product;

	private ProductRepository $productRepository;

	private PricelistRepository $pricelistRepository;

	private PriceRepository $priceRepository;

	private SupplierRepository $supplierRepository;

	private SupplierProductRepository $supplierProductRepository;

	private PageRepository $pageRepository;

	private Shopper $shopper;

	private VatRateRepository $vatRateRepository;

	private CategoryTypeRepository $categoryTypeRepository;

	private LoyaltyProgramRepository $loyaltyProgramRepository;

	private LoyaltyProgramProductRepository $loyaltyProgramProductRepository;

	private RelatedTypeRepository $relatedTypeRepository;

	private RelatedRepository $relatedRepository;

	private StoreRepository $storeRepository;

	private AmountRepository $amountRepository;

	private int $relationMaxItemsCount;

	/**
	 * @var \Eshop\DB\RelatedType[]
	 */
	private array $relatedTypes;

	/** @var string[] */
	private array $configuration;

	public function __construct(
		AdminFormFactory $adminFormFactory,
		PageRepository $pageRepository,
		ProductRepository $productRepository,
		PricelistRepository $pricelistRepository,
		ProductTabRepository $productTabRepository,
		ProductTabTextRepository $productTabTextRepository,
		PriceRepository $priceRepository,
		SupplierRepository $supplierRepository,
		SupplierProductRepository $supplierProductRepository,
		CategoryRepository $categoryRepository,
		RibbonRepository $ribbonRepository,
		InternalRibbonRepository $internalRibbonRepository,
		ProducerRepository $producerRepository,
		VatRateRepository $vatRateRepository,
		DisplayAmountRepository $displayAmountRepository,
		DisplayDeliveryRepository $displayDeliveryRepository,
		TaxRepository $taxRepository,
		Shopper $shopper,
		CategoryTypeRepository $categoryTypeRepository,
		LoyaltyProgramRepository $loyaltyProgramRepository,
		LoyaltyProgramProductRepository $loyaltyProgramProductRepository,
		RelatedTypeRepository $relatedTypeRepository,
		RelatedRepository $relatedRepository,
		StoreRepository $storeRepository,
		AmountRepository $amountRepository,
		SettingRepository $settingRepository,
		$product = null,
		array $configuration = []
	) {
		$this->product = $product = $productRepository->get($product);
		$this->productRepository = $productRepository;
		$this->pricelistRepository = $pricelistRepository;
		$this->productTabRepository = $productTabRepository;
		$this->productTabTextRepository = $productTabTextRepository;
		$this->priceRepository = $priceRepository;
		$this->supplierRepository = $supplierRepository;
		$this->supplierProductRepository = $supplierProductRepository;
		$this->pageRepository = $pageRepository;
		$this->configuration = $configuration;
		$this->shopper = $shopper;
		$this->vatRateRepository = $vatRateRepository;
		$this->categoryTypeRepository = $categoryTypeRepository;
		$this->loyaltyProgramRepository = $loyaltyProgramRepository;
		$this->loyaltyProgramProductRepository = $loyaltyProgramProductRepository;
		$this->relatedTypeRepository = $relatedTypeRepository;
		$this->relatedRepository = $relatedRepository;
		$this->storeRepository = $storeRepository;
		$this->amountRepository = $amountRepository;

		$this->relationMaxItemsCount = (int) ($settingRepository->getValueByName('relationMaxItemsCount') ?? $this::RELATION_MAX_ITEMS_COUNT);

		$form = $adminFormFactory->create(true);

		$this->monitor(Presenter::class, function (ProductPresenter $productPresenter) use ($form): void {
			$form->addHidden('editTab')->setDefaultValue($productPresenter->editTab);
		});

		$form->addGroup('Hlavní atributy');

		$form->addText('code', 'Kód a podsklad')->setRequired();
		$form->addText('subCode', 'Kód podskladu');
		$form->addText('ean', 'EAN')->setNullable();
		$form->addText('mpn', 'P/N')->setNullable();

		if (isset($configuration['externalCode']) && $configuration['externalCode']) {
			$form->addText('externalCode', 'Externí kód')->setNullable();
			$form->addText('externalId', 'Externí id')->setNullable();
		}

		$form->addLocaleText('extendedName', 'Rozšířený název');
		$nameInput = $form->addLocaleText('name', 'Název');

		$form->addSelect('vatRate', 'Úroveň DPH (%)', $vatRateRepository->getDefaultVatRates());

		/** @var \Eshop\DB\CategoryType[] $categoryTypes */
		$categoryTypes = $this->categoryTypeRepository->getCollection(true)->toArray();

		$categoriesContainer = $form->addContainer('categories');
		$allCategories = [];

		foreach ($categoryTypes as $categoryType) {
			$categories = $categoryRepository->getTreeArrayForSelect(true, $categoryType->getPK());
			$allCategories = \array_merge($allCategories, $categories);

			$categoriesContainer->addMultiSelect2($categoryType->getPK(), 'Kategorie: ' . $categoryType->name, $categories);
		}

		$productCategories = [];

		if ($this->product) {
			$assignedProductCategories = $this->product->categories->toArray();

			$productCategories = \array_filter($allCategories, function ($key) use ($assignedProductCategories) {
				return isset($assignedProductCategories[$key]);
			}, \ARRAY_FILTER_USE_KEY);
		}

		$form->addDataSelect('primaryCategory', 'Primární kategorie', $productCategories)->setPrompt('Automaticky')->checkDefaultValue(false)
			->setHtmlAttribute('data-info', 'Primární kategorie je důležitá pro zobrazování drobečkovky produktu, výchozímu obsahu a obrázku a dalších. 
		V případě zvolení kategorie do které již nepatří, se zvolí automaticky jedna z přiřazených.');

		$form->addSelect2('producer', 'Výrobce', $producerRepository->getArrayForSelect())->setPrompt('Nepřiřazeno');

		$form->addDataMultiSelect('ribbons', 'Veřejné štítky', $ribbonRepository->getArrayForSelect());
		$form->addDataMultiSelect('internalRibbons', 'Interní štítky', $internalRibbonRepository->getArrayForSelect());

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
		$form->addLocalePerexEdit('perex', 'Popisek');
		$form->addLocaleRichEdit('content', 'Obsah');

		if (isset($configuration['suppliers']) && $configuration['suppliers'] && $this->supplierRepository->many()->count() > 0) {
			$locks = [];
			$locks[Product::SUPPLIER_CONTENT_MODE_LENGTH] = 'S nejdelším obsahem';

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
S nejdelším obsahem: Převezme se obsah, který je nejdelší ze všech zdrojů<br>
Nikdy nepřebírat: Obsah nebude nikdy přebírán<br>
Ostatní: Přebírání ze zvoleného zdroje
');

			$form->addCheckbox('supplierDisplayAmountLock', 'Nepřebírat skladovost');
		}

		$form->addText('storageDate', 'Nejbližší datum naskladnění')->setNullable(true)->setHtmlType('date');
		$form->addInteger('priority', 'Priorita')->setDefaultValue(10);
		$form->addCheckbox('hidden', 'Skryto');
		$form->addCheckbox('recommended', 'Doporučeno')
			->setHtmlAttribute('data-info', 'Zobrazí se na hlavní stránce.');

		$form->addGroup('Nákup');
		$form->addText('unit', 'Prodejní jednotka')
			->setHtmlAttribute('data-info', 'Např.: ks, ml, ...');

		if (isset($configuration['discountLevel']) && $configuration['discountLevel']) {
			$form->addInteger('discountLevelPct', 'Procentuální sleva (%)')
				->setHtmlAttribute(
					'data-info',
					'Aplikuje se vždy největší z čtveřice: procentuální slevy produktu, procentuální slevy zákazníka, slevy věrnostního programu zákazníka nebo slevového kupónu.<br>
Platí jen pokud má ceník povoleno "Povolit procentuální slevy".',
				)
				->setRequired()
				->setDefaultValue(0);
		}

		$form->addInteger('defaultBuyCount', 'Předdefinované množství')->setRequired()->setDefaultValue(1);
		$form->addInteger('minBuyCount', 'Minimální množství')->setRequired()->setDefaultValue(1);
		$form->addIntegerNullable('maxBuyCount', 'Maximální množství');
		$form->addInteger('buyStep', 'Krokové množství')->setDefaultValue(1);
		$form->addIntegerNullable('inPackage', 'Počet v balení');
		$form->addIntegerNullable('inCarton', 'Počet v kartónu');
		$form->addIntegerNullable('inPalett', 'Počet v paletě');

		$defaultReviewsCount = $form->addIntegerNullable('defaultReviewsCount', 'Výchozí počet recenzí');

		$defaultReviewsScore = $form->addText('defaultReviewsScore', 'Výchozí hodnocení recenzí')->setNullable()
		->setHtmlAttribute(
			'data-info',
			'Zobrazované hodnocení produktu se počítá jako průměr výchozích hodnocení (počet výchozích recenzí * výchozí hodnocení recenzí) ve spojení se skutečnými recenzemi.<br>
Vyplňujte celá nebo desetinná čísla v intervalu ' . $this->shopper->getReviewsMinScore() . ' - ' . $this->shopper->getReviewsMaxScore() . ' (včetně)',
		);

		$defaultReviewsScore->addConditionOn($defaultReviewsCount, $form::FILLED)
			->addRule($form::REQUIRED)
			->addCondition($form::FILLED)
			->addRule($form::FLOAT);

		$defaultReviewsCount->addCondition($form::FILLED)
			->toggle($defaultReviewsScore->getHtmlId() . '-toogle');

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
		$form->addCheckbox('unavailable', 'Neprodejné')->setHtmlAttribute('data-info', 'Znemožňuje nákup produktu.');

		if (isset($configuration['weightAndDimension']) && $configuration['weightAndDimension']) {
			$form->addText('weight', 'Váha')
				->setHtmlAttribute('data-info', 'Celková váha produktu. Na jednotce nezáleží.')
				->setNullable()
				->addCondition($form::FILLED)
				->addRule($form::FLOAT);
			$form->addText('dimension', 'Rozměr')
				->setHtmlAttribute('data-info', 'Celkový rozměr objednávky. Na jednotce nezáleží.')
				->setNullable()
				->addCondition($form::FILLED)
				->addRule($form::FLOAT);
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

		// Relations
		$this->monitor(Presenter::class, function ($presenter) use ($form): void {

			$this->relatedTypes = $this->template->relatedTypes = $this->relatedTypeRepository->many()->toArray();

			foreach ($this->relatedTypes as $relatedType) {
				$relationsContainer = $form->addContainer('relatedType_' . $relatedType->getPK());

				for ($i = 0; $i < $this->relationMaxItemsCount; $i++) {
					$relationsContainer->addSelect2Ajax("product_$i", $this->getPresenter()->link('getProductsForSelect2!'), null, [], 'Zvolte produkt');
					$relationsContainer->addInteger("amount_$i")->setDefaultValue($relatedType->defaultAmount)->setNullable();
					$relationsContainer->addInteger("priority_$i")->setDefaultValue(10)->setNullable();
					$relationsContainer->addCheckbox("hidden_$i");

					if ($relatedType->defaultDiscountPct) {
						$relationsContainer->addText("discountPct_$i")->setDefaultValue($relatedType->defaultDiscountPct)->setNullable()->addCondition($form::FILLED)->addRule($form::FLOAT);
					}

					if (!$relatedType->defaultMasterPct) {
						continue;
					}

					$relationsContainer->addText("masterPct_$i")->setDefaultValue($relatedType->defaultMasterPct)->setNullable()->addCondition($form::FILLED)->addRule($form::FLOAT);
				}

				if (!$this->product) {
					continue;
				}

				$relations = $this->relatedRepository->many()
					->where('fk_master', $this->product->getPK())
					->where('fk_type', $relatedType->getPK())
					->orderBy(['uuid' => 'asc'])
					->toArray();

				$i = 0;

				/** @var \Eshop\DB\Related $relation
				 */
				foreach ($relations as $relation) {
					/** @var \Nette\Forms\Controls\SelectBox $productInput */
					$productInput = $relationsContainer["product_$i"];
					/** @var \Nette\Forms\Controls\TextInput $amountInput */
					$amountInput = $relationsContainer["amount_$i"];
					/** @var \Nette\Forms\Controls\TextInput $priorityInput */
					$priorityInput = $relationsContainer["priority_$i"];
					/** @var \Nette\Forms\Controls\Checkbox $hiddenInput */
					$hiddenInput = $relationsContainer["hidden_$i"];

					$presenter->template->select2AjaxDefaults[$productInput->getHtmlId()] = [$relation->getValue('slave') => $relation->slave->name];
					$amountInput->setDefaultValue($relation->amount);
					$priorityInput->setDefaultValue($relation->priority);
					$hiddenInput->setDefaultValue($relation->hidden);

					if ($relatedType->defaultDiscountPct) {
						/** @var \Nette\Forms\Controls\TextInput $discountPctInput */
						$discountPctInput = $relationsContainer["discountPct_$i"];
						$discountPctInput->setDefaultValue($relation->discountPct);
					}

					if ($relatedType->defaultMasterPct) {
						/** @var \Nette\Forms\Controls\TextInput $masterPctInput */
						$masterPctInput = $relationsContainer["masterPct_$i"];
						$masterPctInput->setDefaultValue($relation->masterPct);
					}

					$i++;

					if ($i === $this->relationMaxItemsCount) {
						break;
					}
				}
			}
		});
		// /Relations

		/** @deprecated */
		if (isset($configuration['upsells']) && $configuration['upsells']) {
			$this->monitor(Presenter::class, function () use ($form): void {
				$form->addMultiSelect2('upsells', 'Upsell produkty', [], [
					'ajax' => [
						'url' => $this->getPresenter()->link('getProductsForSelect2!'),
					],
					'placeholder' => 'Zvolte produkty',
				])->checkDefaultValue(false);
			});
		}

		/** @var \Eshop\DB\ProductTab $productTab */
		foreach ($productTabRepository->many() as $productTab) {
			$form->addLocalePerexEdit('productTab' . $productTab->getPk(), $productTab->name);
		}

		$this->monitor(Presenter::class, function (BackendPresenter $presenter) use ($form, $pricelistRepository, $storeRepository): void {
			$prices = $form->addContainer('prices');

			$pricesPermission = $presenter->admin->isAllowed(':Eshop:Admin:Pricelists:default');

			/** @var \Eshop\DB\Price $prc */
			foreach ($pricelistRepository->many() as $prc) {
				$pricelist = $prices->addContainer($prc->getPK());
				$pricelist->addText('price')->setNullable()->setDisabled(!$pricesPermission)->addCondition($form::FILLED)->addRule($form::FLOAT);
				$pricelist->addText('priceVat')->setNullable()->setDisabled(!$pricesPermission)->addCondition($form::FILLED)->addRule($form::FLOAT);
				$pricelist->addText('priceBefore')->setNullable()->setDisabled(!$pricesPermission)->addCondition($form::FILLED)->addRule($form::FLOAT);
				$pricelist->addText('priceVatBefore')->setNullable()->setDisabled(!$pricesPermission)->addCondition($form::FILLED)->addRule($form::FLOAT);
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
			false,
			true,
		);

		$form->addSubmits(!$product);

		$form->onValidate[] = [$this, 'validate'];
		$form->onSuccess[] = [$this, 'submit'];

		$this->addComponent($form, 'form');
	}

	public function validate(AdminForm $form): void
	{
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
		$data = $this->getPresenter()->getHttpRequest()->getPost();
		$values = $form->getValues('array');
		$editTab = Arrays::pick($values, 'editTab', null);

		$oldValues = $this->product ? $this->product->toArray() : [];

		if (!$values['uuid']) {
			$values['uuid'] = ProductRepository::generateUuid(
				$values['ean'],
				$values['subCode'] ? $values['code'] . '.' . $values['subCode'] : $values['code'],
			);
		}

		if (isset($values['supplierContent'])) {
			if ($values['supplierContent'] === 'none') {
				$values['supplierContent'] = null;
				$values['supplierContentLock'] = true;
				$values['supplierContentMode'] = Product::SUPPLIER_CONTENT_MODE_NONE;
			} elseif ($values['supplierContent'] === 'length') {
				$values['supplierContent'] = null;
				$values['supplierContentLock'] = false;
				$values['supplierContentMode'] = Product::SUPPLIER_CONTENT_MODE_LENGTH;
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
		$newCategories = [];

		if (\count($pickedCategories) > 0) {
			foreach ($pickedCategories as $categories) {
				foreach ($categories as $category) {
					$newCategories[] = $category;
				}
			}
		}

		$values['primaryCategory'] = Arrays::contains($newCategories, $values['primaryCategory']) ? $values['primaryCategory'] : (\count($newCategories) > 0 ? Arrays::first($newCategories) : null);

		/** @var \Eshop\DB\Product $product */
		$product = $this->productRepository->syncOne($values, null, true);

		$product->categories->unrelateAll();

		if (\count($newCategories) > 0) {
			$product->categories->relate($newCategories);
		}

		if ($this->product) {
			foreach ($this->relatedTypes as $relatedType) {
				$this->relatedRepository->many()->where(
					'this.uuid',
					\array_values($this->relatedRepository->many()
						->setSelect(['uuid' => 'this.uuid'])
						->where('fk_master', $this->product->getPK())
						->where('fk_type', $relatedType->getPK())
						->orderBy(['uuid' => 'asc'])
						->setTake($this->relationMaxItemsCount)
						->toArrayOf('uuid')),
				)->delete();
			}
		}

		// Relations
		foreach ($this->relatedTypes as $relatedType) {
			$relatedTypeValues = $values['relatedType_' . $relatedType->getPK()];

			for ($i = 0; $i < $this->relationMaxItemsCount; $i++) {
				if (!isset($data['relatedType_' . $relatedType->getPK()]["product_$i"])) {
					continue;
				}

				if ($relatedType->defaultDiscountPct) {
					$relatedTypeValues["discountPct_$i"] ??= $relatedType->defaultDiscountPct;
				}

				if ($relatedType->defaultMasterPct) {
					$relatedTypeValues["masterPct_$i"] ??= $relatedType->defaultMasterPct;
				}

				$this->relatedRepository->syncOne([
					'type' => $relatedType->getPK(),
					'master' => $product->getPK(),
					'slave' => $data['relatedType_' . $relatedType->getPK()]["product_$i"],
					'amount' => $relatedTypeValues["amount_$i"] ?? $relatedType->defaultAmount,
					'priority' => $relatedTypeValues["priority_$i"] ?? 10,
					'hidden' => $relatedTypeValues["hidden_$i"] ?? false,
					'discountPct' => $relatedType->defaultDiscountPct ? ($relatedTypeValues["discountPct_$i"] ?? $relatedType->defaultDiscountPct) : null,
					'masterPct' => $relatedType->defaultMasterPct ? ($relatedTypeValues["masterPct_$i"] ?? $relatedType->defaultMasterPct) : null,
				]);
			}
		}

		// /Relations

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

		// /Loyalty programs

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
				$conditions = [
					'fk_pricelist' => $pricelistId,
					'fk_product' => $values['uuid'],
				];

				if ($prices['price'] === null) {
					$this->priceRepository->many()->match($conditions)->delete();

					continue;
				}

				$prices['priceVat'] = $prices['priceVat'] ? \floatval(\str_replace(',', '.', \strval($prices['priceVat']))) :
					$prices['price'] + ($prices['price'] * \fdiv(\floatval($this->vatRateRepository->getDefaultVatRates()[$product->vatRate]), 100));

				$conditions = [
					'pricelist' => $pricelistId,
					'product' => $values['uuid'],
				];

				$this->priceRepository->syncOne($conditions + $prices);
			}
		}

		unset($values['prices']);

		foreach ($this->productTabRepository->many() as $productTab) {
			$conditions = [
				'tab' => $productTab->getPK(),
				'product' => $values['uuid'],
			];
			
			$conditions['content'] = $values['productTab' . $productTab->getPK()];

			$this->productTabTextRepository->many()
				->where('fk_product=:product AND fk_tab=:tab', ['product' => $product->getPK(), 'tab' => $productTab->getPK()])
				->delete();

			$this->productTabTextRepository->syncOne($conditions);

			unset($values['productTab' . $productTab->getPK()]);
		}

		foreach ($values['stores'] as $storeId => $amount) {
			if ($amount['inStock'] === null) {
				$this->amountRepository->many()->where('fk_product', $product->getPK())->where('fk_store', $storeId)->delete();

				continue;
			}

			$this->amountRepository->syncOne(['product' => $product->getPK(), 'store' => $storeId] + $amount);
		}

		unset($values['stores']);

		$form->syncPages(function () use ($product, $values): void {
			$this->pageRepository->syncPage($values['page'], ['product' => $product->getPK()]);
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
		$this->template->supplierProducts = $this->product ? $this->supplierProductRepository->many()
			->join(['supplier' => 'eshop_supplier'], 'this.fk_supplier = supplier.uuid')
			->orderBy(['supplier.importPriority'])
			->where('this.fk_product', $this->product->getPK())
			->toArray() : [];

		$this->template->relationMaxItemsCount = $this->relationMaxItemsCount;
		$this->template->product = $this->getPresenter()->getParameter('product');
		$this->template->pricelists = $this->pricelistRepository->many()->orderBy(['this.priority']);
		$this->template->productTabs = $this->productTabRepository->many()->orderBy(['this.priority']);
		$this->template->stores = $this->storeRepository->many()->orderBy(['this.name' . $this->storeRepository->getConnection()->getMutationSuffix()]);
		$this->template->configuration = $this->configuration;
		$this->template->shopper = $this->shopper;
		$this->template->primaryCategory = $this->product && $this->product->primaryCategory ?
			($this->product->primaryCategory->ancestor ?
				\implode(' -> ', $this->product->primaryCategory->ancestor->getFamilyTree()->toArrayOf('name')) . ' -> ' . $this->product->primaryCategory->name :
				$this->product->primaryCategory->name)
			: '-';

		$this->template->modals = [
			'name' => 'frm-productForm-form-name-cs',
			'perex' => 'frm-perex-cs',
			'content' => 'frm-content-cs',
		];

		/** @var \Nette\Bridges\ApplicationLatte\Template $template */
		$template = $this->template;
		$template->render(__DIR__ . '/productForm.latte');
	}

	public function handleClearPrice(string $productPK, string $pricelistPK): void
	{
		$this->priceRepository->many()
			->where('this.fk_product', $productPK)
			->where('this.fk_pricelist', $pricelistPK)
			->delete();

		$this->getPresenter()->flashMessage('Provedeno', 'success');
		$this->getPresenter()->redirect('this');
	}

	public function handleUnlinkSupplierProduct(string $supplierProduct): void
	{
		$supplierProduct = $this->supplierProductRepository->one($supplierProduct, true);

		$supplierProduct->update(['product' => null,]);

		$this->getPresenter()->flashMessage('Provedeno', 'success');
		$this->getPresenter()->redirect('this');
	}
}
