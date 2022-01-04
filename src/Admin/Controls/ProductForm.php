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
use Nette\DI\Container;
use Nette\Utils\Arrays;
use Nette\Utils\FileSystem;
use StORM\ICollection;
use Web\DB\PageRepository;

class ProductForm extends Control
{
	protected const RELATION_MAX_ITEMS_COUNT = 10;

	/** @persistent */
	public string $tab = 'menu0';

	private ?Product $product;

	private ProductRepository $productRepository;

	private Container $container;

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

	/**
	 * @var \Eshop\DB\RelatedType[]
	 */
	private array $relatedTypes;

	/** @var string[] */
	private array $configuration;

	public function __construct(
		Container $container,
		AdminFormFactory $adminFormFactory,
		PageRepository $pageRepository,
		ProductRepository $productRepository,
		PricelistRepository $pricelistRepository,
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
		$product = null,
		array $configuration = []
	) {
		$this->product = $product = $productRepository->get($product);
		$this->productRepository = $productRepository;
		$this->container = $container;
		$this->pricelistRepository = $pricelistRepository;
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

		$form = $adminFormFactory->create(true);

		$this->monitor(Presenter::class, function (ProductPresenter $productPresenter) use ($form): void {
			$form->addHidden('editTab')->setDefaultValue($productPresenter->editTab);
		});

		$form->addGroup('Hlavní atributy');

		$form->addText('code', 'Kód a podsklad')->setRequired();
		$form->addText('subCode', 'Kód podskladu');
		$form->addText('ean', 'EAN')->setNullable();
		$nameInput = $form->addLocaleText('name', 'Název');

		$form->addSelect('vatRate', 'Úroveň DPH (%)', $vatRateRepository->getDefaultVatRates());

		/** @var \Eshop\DB\CategoryType[] $categoryTypes */
		$categoryTypes = $this->categoryTypeRepository->getCollection(true)->toArray();

		$categoriesContainer = $form->addContainer('categories');

		foreach ($categoryTypes as $categoryType) {
			$categoriesContainer->addDataMultiSelect($categoryType->getPK(), 'Kategorie: ' . $categoryType->name, $categoryRepository->getTreeArrayForSelect(true, $categoryType->getPK()));
		}

		$form->addSelect2('producer', 'Výrobce', $producerRepository->getArrayForSelect())->setPrompt('Nepřiřazeno');

		$form->addDataMultiSelect('ribbons', 'Veřejné štítky', $ribbonRepository->getArrayForSelect());
		$form->addDataMultiSelect('internalRibbons', 'Interní štítky', $internalRibbonRepository->getArrayForSelect());

		$form->addSelect2(
			'displayAmount',
			'Dostupnost',
			$displayAmountRepository->getArrayForSelect(),
		)->setPrompt('Nepřiřazeno');
		$form->addSelect2(
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
				->setHtmlAttribute('data-info', 'Nastavení přebírání obsahu ze zdrojů.<br><br>
S nejvyšší prioritou: Zdroj s nejvyšší prioritou<br>
S nejdelším obsahem: Převezme se obsah, který je nejdelší ze všech zdrojů<br>
Nikdy nepřebírat: Obsah nebude nikdy přebírán<br>
Ostatní: Přebírání ze zvoleného zdroje
');
		}

		$form->addInteger('priority', 'Priorita')->setDefaultValue(10);
		$form->addCheckbox('hidden', 'Skryto');
		$form->addCheckbox('recommended', 'Doporučeno')
			->setHtmlAttribute('data-info', 'Zobrazí se na hlavní stránce.');

		$form->addGroup('Nákup');
		$form->addText('unit', 'Prodejní jednotka')
			->setHtmlAttribute('data-info', 'Např.: ks, ml, ...');

		if (isset($configuration['discountLevel']) && $configuration['discountLevel']) {
			$form->addInteger('discountLevelPct', 'Slevová hladina (%)')->setDefaultValue(0);
		}

		$form->addInteger('defaultBuyCount', 'Předdefinované množství')->setRequired()->setDefaultValue(1);
		$form->addInteger('minBuyCount', 'Minimální množství')->setRequired()->setDefaultValue(1);
		$form->addIntegerNullable('maxBuyCount', 'Maximální množství');
		$form->addInteger('buyStep', 'Krokové množství')->setDefaultValue(1);
		$form->addIntegerNullable('inPackage', 'Počet v balení');
		$form->addIntegerNullable('inCarton', 'Počet v kartónu');
		$form->addIntegerNullable('inPalett', 'Počet v paletě');

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

				for ($i = 0; $i < $this::RELATION_MAX_ITEMS_COUNT; $i++) {
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

					if ($i === $this::RELATION_MAX_ITEMS_COUNT) {
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

		$form->addPageContainer('product_detail', ['product' => $product], $nameInput);

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

		$this->createImageDirs();

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

		$values['primaryCategory'] = \count($newCategories) > 0 ? Arrays::first($newCategories) : null;

		/** @var \Eshop\DB\Product $product */
		$product = $this->productRepository->syncOne($values, null, true);

		$product->categories->unrelateAll();

		if (\count($newCategories) > 0) {
			$product->categories->relate($newCategories);
		}

		foreach ($this->relatedTypes as $relatedType) {
			$this->relatedRepository->many()->where(
				'this.uuid',
				\array_values($this->relatedRepository->many()
					->setSelect(['uuid' => 'this.uuid'])
					->where('fk_master', $this->product->getPK())
					->where('fk_type', $relatedType->getPK())
					->orderBy(['uuid' => 'asc'])
					->setTake($this::RELATION_MAX_ITEMS_COUNT)
					->toArrayOf('uuid')),
			)->delete();
		}

		// Relations
		foreach ($this->relatedTypes as $relatedType) {
			$relatedTypeValues = $values['relatedType_' . $relatedType->getPK()];

			for ($i = 0; $i < $this::RELATION_MAX_ITEMS_COUNT; $i++) {
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
					if ($this->product->getValue($column, $mutation) !== $values[$column][$mutation]) {
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

		$this->getPresenter()->flashMessage('Uloženo', 'success');
		$form->processRedirect('edit', 'default', ['product' => $product, 'editTab' => $editTab]);
	}

	public function render(): void
	{
		$this->template->relationMaxItemsCount = $this::RELATION_MAX_ITEMS_COUNT;
		$this->template->product = $this->getPresenter()->getParameter('product');
		$this->template->pricelists = $this->pricelistRepository->many()->orderBy(['this.priority']);
		$this->template->stores = $this->storeRepository->many()->orderBy(["this.name" . $this->storeRepository->getConnection()->getMutationSuffix()]);
		$this->template->supplierProducts = [];
		$this->template->configuration = $this->configuration;
		$this->template->shopper = $this->shopper;
		$this->template->primaryCategory = $this->product && $this->product->primaryCategory ?
			($this->product->primaryCategory->ancestor ?
				\implode(' -> ', $this->product->primaryCategory->ancestor->getFamilyTree()->toArrayOf('name')) . " -> " . $this->product->primaryCategory->name :
				$this->product->primaryCategory->name)
			: '-';

		$this->template->modals = [
			'name' => 'frm-productForm-form-name-cs',
			'perex' => 'frm-perex-cs',
			'content' => 'frm-content-cs',
		];

		$this->template->supplierProducts = $this->getPresenter()->getParameter('product') ? $this->supplierProductRepository->many()->where(
			'fk_product',
			$this->getPresenter()->getParameter('product'),
		)->toArray() : [];

		/** @var \Nette\Bridges\ApplicationLatte\Template $template */
		$template = $this->template;
		$template->render(__DIR__ . '/productForm.latte');
	}

	protected function deleteImages(): void
	{
		$product = $this->getPresenter()->getParameter('product');

		if (!$product->imageFileName) {
			return;
		}

		$subDirs = ['origin', 'detail', 'thumb'];
		$dir = Product::IMAGE_DIR;

		foreach ($subDirs as $subDir) {
			$rootDir = $this->container->parameters['wwwDir'] . \DIRECTORY_SEPARATOR . 'userfiles' . \DIRECTORY_SEPARATOR . $dir;
			FileSystem::delete($rootDir . \DIRECTORY_SEPARATOR . $subDir . \DIRECTORY_SEPARATOR . $product->imageFileName);
		}

		$product->update(['imageFileName' => null]);
	}

	private function createImageDirs(): void
	{
		$subDirs = ['origin', 'detail', 'thumb'];
		$dir = Product::IMAGE_DIR;
		$rootDir = $this->container->parameters['wwwDir'] . \DIRECTORY_SEPARATOR . 'userfiles' . \DIRECTORY_SEPARATOR . $dir;
		FileSystem::createDir($rootDir);

		foreach ($subDirs as $subDir) {
			FileSystem::createDir($rootDir . \DIRECTORY_SEPARATOR . $subDir);
		}
	}
}
