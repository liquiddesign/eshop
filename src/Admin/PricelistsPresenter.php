<?php

declare(strict_types=1);

namespace Eshop\Admin;

use Admin\Controls\AdminForm;
use Admin\Controls\AdminGrid;
use Eshop\Admin\Configs\ProductFormAutoPriceConfig;
use Eshop\Admin\Configs\ProductFormConfig;
use Eshop\BackendPresenter;
use Eshop\Common\Helpers;
use Eshop\DB\CategoryRepository;
use Eshop\DB\CountryRepository;
use Eshop\DB\CurrencyRepository;
use Eshop\DB\CustomerRepository;
use Eshop\DB\DiscountRepository;
use Eshop\DB\DisplayAmountRepository;
use Eshop\DB\InternalRibbon;
use Eshop\DB\InternalRibbonRepository;
use Eshop\DB\Price;
use Eshop\DB\Pricelist;
use Eshop\DB\PricelistRepository;
use Eshop\DB\PriceRepository;
use Eshop\DB\ProducerRepository;
use Eshop\DB\ProductRepository;
use Eshop\DB\QuantityPrice;
use Eshop\DB\QuantityPriceRepository;
use Eshop\DB\RibbonRepository;
use Eshop\DB\SupplierProductRepository;
use Eshop\DB\SupplierRepository;
use Eshop\DB\VatRateRepository;
use Eshop\FormValidators;
use Eshop\ShopperUser;
use Forms\Form;
use Grid\Datagrid;
use League\Csv\Reader;
use League\Csv\Writer;
use Nette\Application\Attributes\Persistent;
use Nette\Application\Responses\FileResponse;
use Nette\Caching\Cache;
use Nette\Caching\Storage;
use Nette\DI\Attributes\Inject;
use StORM\Collection;
use StORM\Connection;
use StORM\Expression;
use StORM\ICollection;
use Tracy\Debugger;
use Tracy\ILogger;

class PricelistsPresenter extends BackendPresenter
{
	protected const TABS = [
		'priceLists' => 'Ceníky',
		'prices' => 'Ceny',
	];

	protected const CONFIGURATION = [
		'aggregate' => true,
		'customLabel' => false,
		ProductFormConfig::class => [
			ProductFormAutoPriceConfig::class => ProductFormAutoPriceConfig::NONE,
		],
	];

	protected const SHOW_SUPPLIER_NAMES = [];

	protected const SHOW_PRICE_HIDDEN = false;

	#[Inject]
	public SupplierProductRepository $supplierProductRepository;

	#[Inject]
	public PricelistRepository $priceListRepository;

	#[Inject]
	public CurrencyRepository $currencyRepo;

	#[Inject]
	public PriceRepository $priceRepository;

	#[Inject]
	public CurrencyRepository $currencyRepository;

	#[Inject]
	public ProductRepository $productRepository;

	#[Inject]
	public CustomerRepository $customerRepository;

	#[Inject]
	public CategoryRepository $categoryRepository;

	#[Inject]
	public ProducerRepository $producerRepository;

	#[Inject]
	public SupplierRepository $supplierRepository;

	#[Inject]
	public Connection $storm;

	#[Inject]
	public DiscountRepository $discountRepo;

	#[Inject]
	public QuantityPriceRepository $quantityPriceRepo;

	#[Inject]
	public CountryRepository $countryRepo;

	#[Inject]
	public ShopperUser $shopperUser;

	#[Inject]
	public VatRateRepository $vatRateRepository;

	#[Inject]
	public RibbonRepository $ribbonRepository;

	#[Inject]
	public InternalRibbonRepository $internalRibbonRepository;

	#[Inject]
	public Storage $storage;

	#[Inject]
	public DisplayAmountRepository $displayAmountRepository;

	#[Persistent]
	public string $tab = 'priceLists';

	public function createComponentPriceLists(): AdminGrid
	{
		$grid = $this->gridFactory->create($this->priceListRepository->many(), 20, 'priority', 'ASC', filterShops: false);
		$grid->addColumnSelector();

		$grid->addColumnText('Kód', 'code', '%s', 'code', ['class' => 'fit'])->onRenderCell[] = [
			$grid,
			'decoratorNowrap',
		];
		$grid->addColumnText('Měna', "currency.code'", '%s', null, ['class' => 'fit'])->onRenderCell[] = [
			$grid,
			'decoratorNowrap',
		];
		$grid->addColumn('Název', function (Pricelist $pricelist): array {
			$ribbons = null;

			foreach ($pricelist->internalRibbons as $ribbon) {
				$ribbons .= "<div class=\"badge\" style=\"font-weight: normal; font-style: italic; background-color: $ribbon->backgroundColor; color: $ribbon->color\">$ribbon->name</div> ";
			}

			return [$pricelist->name, $ribbons];
		}, '%s&nbsp;%s', 'name');
		$grid->addColumnText('Popis', 'description', '%s');
		$grid->addColumn('Akce', function (Pricelist $object) {
			$link = $this->admin->isAllowed(':Eshop:Admin:Discount:detail') && $object->discount ? $this->link(
				':Eshop:Admin:Discount:detail',
				[$object->discount, 'backLink' => $this->storeRequest()],
			) : '#';

			return $object->discount ? "<a href='" . $link . "'>" . $object->discount->name . '</a>' : '';
		}, '%s');

		$grid->addColumnText(
			'Akce od',
			"discount.validFrom|date:'d.m.Y G:i'",
			'%s',
			null,
			['class' => 'fit'],
		)->onRenderCell[] = [$grid, 'decoratorNowrap'];
		$grid->addColumnText(
			'Akce do',
			"discount.validTo|date:'d.m.Y G:i'",
			'%s',
			null,
			['class' => 'fit'],
		)->onRenderCell[] = [$grid, 'decoratorNowrap'];

		$grid->addColumn('Zdroj', function (Pricelist $object) {
			$link = $this->admin->isAllowed(':Eshop:Admin:Supplier:detail') && $object->supplier ? $this->link(
				':Eshop:Admin:Supplier:detail',
				[$object->supplier, 'backLink' => $this->storeRequest()],
			) : '#';

			return $object->supplier ? "<a href='" . $link . "'>" . $object->supplier->name . '</a>' : '';
		}, '%s');

		$grid->addColumnInputInteger('Priorita', 'priority', '', '', 'priority', [], true);
		$grid->addColumnInputCheckbox('Aktivní', 'isActive', '', '', 'isActive');

		$grid->addColumnLink('priceListItems', 'Ceny');
		$grid->addColumnLink('quantityPrices', 'Množstevní ceny');

		$grid->addColumnLinkDetail('priceListDetail');
		$grid->addColumnActionDeleteSystemic();

		$grid->addButtonSaveAll();
		$grid->addButtonDeleteSelected(null, false, function (?Pricelist $object) {
			if ($object) {
				return !$object->isSystemic();
			}

			return false;
		}, 'this.uuid');

		$grid->addFilterTextInput('search', ['name'], null, 'Název');
		$grid->addFilterSelectInput(
			'search2',
			'fk_currency = :s',
			null,
			'- Měna -',
			null,
			$this->currencyRepo->getArrayForSelect(),
			's',
		);

		if ($suppliers = $this->supplierRepository->getArrayForSelect()) {
			$grid->addFilterDataSelect(function (Collection $source, $value): void {
				$source->where('this.fk_supplier', $value);
			}, '', 'supplier', null, $suppliers)->setPrompt('- Zdroj -');
		}

		if ($ribbons = $this->internalRibbonRepository->getArrayForSelect(type: InternalRibbon::TYPE_PRICE_LIST)) {
			$ribbons += ['0' => 'X - bez štítků'];
			$grid->addFilterDataMultiSelect(function (Collection $source, $value): void {
				$source->filter(['internalRibbon' => \Eshop\Common\Helpers::replaceArrayValue($value, '0', null)]);
			}, '', 'internalRibbon', null, $ribbons, ['placeholder' => '- Int. štítky -']);
		}

		$this->gridFactory->addShopsFilterSelect($grid);
		$grid->addFilterButtons();

		if (isset($this::CONFIGURATION['aggregate']) && $this::CONFIGURATION['aggregate']) {
			$submit = $grid->getForm()->addSubmit('aggregate', 'Agregovat ...')->setHtmlAttribute('class', 'btn btn-outline-primary btn-sm');

			$submit->onClick[] = function ($button) use ($grid): void {
				$grid->getPresenter()->redirect('aggregate', [$grid->getSelectedIds()]);
			};
		}

		return $grid;
	}

	public function createComponentPricesGrid(): AdminGrid
	{
		$grid = $this->gridFactory->create(
			$this->priceRepository->many()
				->select(['rate' => 'rates.rate'])
				->join(['products' => 'eshop_product'], 'products.uuid=this.fk_product')
				->join(['rates' => 'eshop_vatrate'], 'rates.uuid = products.vatRate AND rates.fk_country=pricelist.fk_country'),
			20,
			'product.code',
			'ASC',
		);

		$grid->setItemCountCallback(function (Collection $collection): int {
			return $collection->setOrderBy([])->count();
		});

		$grid->addColumnSelector();

		$grid->addColumnText('Vytvořeno', "createdTs|date:'d.m.Y G:i'", '%s', 'createdTs', ['class' => 'fit']);
		$grid->addColumn('Ceník', function (Price $price): array {
			$ribbons = null;

			foreach ($price->pricelist->internalRibbons as $ribbon) {
				$ribbons .= "<div class=\"badge\" style=\"font-weight: normal; font-style: italic; background-color: $ribbon->backgroundColor; color: $ribbon->color\">$ribbon->name</div> ";
			}

			return [$price->pricelist->code, $price->pricelist->name, $ribbons];
		}, '%s<br>%s<br>%s', 'pricelist.name');
		$grid->addColumnText('Kód', 'product.code', '%s', 'product.code', ['class' => 'fit']);

		$grid->addColumn('Produkt', function (Price $price, Datagrid $datagrid) {
			$link = $this->admin->isAllowed(':Eshop:Admin:Product:edit') ? $datagrid->getPresenter()?->link(
				':Eshop:Admin:Product:edit',
				[$price->product, 'backLink' => $this->storeRequest()],
			) : '#';

			return '<a href="' . $link . '">' . $price->product->name . '</a>';
		}, '%s');

		$cache = new Cache($this->storage);

		foreach ($this::SHOW_SUPPLIER_NAMES as $supplierId => $supplierName) {
			$supplierNames = $cache->load("ADMIN-SHOW_SUPPLIER_NAMES-$supplierId", function () use ($supplierId) {
				return $this->supplierProductRepository->many()
					->where('this.fk_supplier', $supplierId)
					->setSelect(['this.fk_product', 'this.name'])
					->setIndex('this.fk_product')
					->toArrayOf('name');
			}, [
				$cache::Expire => '20 minutes',
			]);

			$grid->addColumn("Název ($supplierName)", function (Price $price, Datagrid $datagrid) use ($supplierNames): string|null {
				return $supplierNames[$price->getValue('product')] ?? null;
			}, '%s');
		}

		/** @var null|string $autoPriceConfig */
		$autoPriceConfig = $this::CONFIGURATION[ProductFormConfig::class][ProductFormAutoPriceConfig::class] ?? null;

		if ($autoPriceConfig === ProductFormAutoPriceConfig::WITHOUT_VAT) {
			$grid->addColumnText('Cena', 'price', '%s');
		} else {
			$grid->addColumnInputPrice('Cena', 'price');
		}

		if ($this->shopperUser->getShowVat()) {
			if ($autoPriceConfig === ProductFormAutoPriceConfig::WITH_VAT) {
				$grid->addColumnText('Cena s DPH', 'priceVat', '%s');
			} else {
				$grid->addColumnInputPrice('Cena s DPH', 'priceVat');
			}
		}

		if ($autoPriceConfig === ProductFormAutoPriceConfig::WITHOUT_VAT) {
			$grid->addColumnText('Cena před slevou', 'priceBefore', '%s');
		} else {
			$grid->addColumnInputPrice('Cena před slevou', 'priceBefore');
		}

		if ($this->shopperUser->getShowVat()) {
			if ($autoPriceConfig === ProductFormAutoPriceConfig::WITH_VAT) {
				$grid->addColumnText('Cena s DPH před slevou', 'priceVatBefore', '%s');
			} else {
				$grid->addColumnInputPrice('Cena s DPH před slevou', 'priceVatBefore');
			}
		}

		if ($this::SHOW_PRICE_HIDDEN) {
			$grid->addColumnInputCheckbox('<i title="Skryto" class="far fa-eye-slash"></i>', 'hidden', orderExpression: 'hidden');
		}

		$grid->addColumnActionDelete();

		/** @var null|string $autoPriceConfig */
		$autoPriceConfig = $this::CONFIGURATION[ProductFormConfig::class][ProductFormAutoPriceConfig::class] ?? null;

		$grid->addButtonSaveAll(onRowUpdate: function (string $id, array &$prices, Price $price) use ($autoPriceConfig): void {
			if ((!$autoPriceConfig || $autoPriceConfig === ProductFormAutoPriceConfig::NONE || $autoPriceConfig === ProductFormAutoPriceConfig::WITH_VAT) && !isset($prices['price']) ||
				($autoPriceConfig === ProductFormAutoPriceConfig::WITHOUT_VAT && !isset($prices['priceVat']))) {
				return;
			}

			if ($autoPriceConfig === ProductFormAutoPriceConfig::WITHOUT_VAT) {
				$prices['price'] = \round($prices['priceVat'] * \fdiv(100, 100 + $this->vatRateRepository->getDefaultVatRates()[$price->product->vatRate]), ShopperUser::PRICE_PRECISSION);
				$prices['priceBefore'] = isset($prices['priceVatBefore']) ?
					\round($prices['priceVatBefore'] * \fdiv(100, 100 + $this->vatRateRepository->getDefaultVatRates()[$price->product->vatRate]), ShopperUser::PRICE_PRECISSION) :
					null;
			}

			if ($autoPriceConfig === ProductFormAutoPriceConfig::WITH_VAT) {
				$prices['priceVat'] = \round($prices['price'] * \fdiv(100 + $this->vatRateRepository->getDefaultVatRates()[$price->product->vatRate], 100), ShopperUser::PRICE_PRECISSION);
				$prices['priceVatBefore'] = isset($prices['priceBefore']) ?
					\round($prices['priceBefore'] * \fdiv(100 + $this->vatRateRepository->getDefaultVatRates()[$price->product->vatRate], 100), ShopperUser::PRICE_PRECISSION) :
					null;
			}

			foreach (['price', 'priceVat', 'priceBefore', 'priceVatBefore'] as $priceKey) {
				if (isset($prices[$priceKey])) {
					continue;
				}

				$prices[$priceKey] = null;
			}
		}, diff: false);
		$grid->addButtonDeleteSelected(null, false, null, 'this.uuid');

		$grid->addFilterDataMultiSelect(function (ICollection $source, $value): void {
			$source->where('this.fk_pricelist', $value);
		}, '', 'pricelists', null, $this->priceListRepository->getArrayForSelect(), ['placeholder' => '- Ceníky -']);

		$grid->addFilterButtons();

		$grid->addFilterTextInput('code', ['products.code', 'products.ean', 'products.name_cs'], null, 'Název, EAN, kód', '', '%s%%');

		$grid->addFilterInteger(function (ICollection $source, $value): void {
			$source->where('this.price >= :price', ['price' => $value]);
		}, null, 'priceFrom', 'Cena od')
			->setHtmlAttribute('placeholder', 'Cena od')
			->setHtmlAttribute('class', 'form-control form-control-sm')
			->setHtmlAttribute('style', 'width: 100px');

		if ($categories = $this->categoryRepository->getTreeArrayForSelect()) {
			$grid->addFilterDataSelect(function (Collection $source, $value): void {
				$categoryPath = $this->categoryRepository->one($value, true)->path;
				$source->join(['eshop_product_nxn_eshop_category'], 'eshop_product_nxn_eshop_category.fk_product=products.uuid');
				$source->join(['categories' => 'eshop_category'], 'categories.uuid=eshop_product_nxn_eshop_category.fk_category');
				$source->where('categories.path LIKE :category', ['category' => "$categoryPath%"]);
			}, '', 'category', null, $categories)->setPrompt('- Kategorie -');
		}

		if ($ribbons = $this->ribbonRepository->getArrayForSelect()) {
			$ribbons += ['0' => 'X - bez štítků'];
			$grid->addFilterDataMultiSelect(function (Collection $source, $value): void {
				$source->filter(['ribbon' => Helpers::replaceArrayValue($value, '0', null)]);
			}, '', 'ribbons', null, $ribbons, ['placeholder' => '- Veř. štítky -']);
		}

		if ($ribbons = $this->internalRibbonRepository->getArrayForSelect(type: InternalRibbon::TYPE_PRODUCT)) {
			$ribbons += ['0' => 'X - bez štítků'];
			$grid->addFilterDataMultiSelect(function (Collection $source, $value): void {
				$source->filter(['internalRibbon' => Helpers::replaceArrayValue($value, '0', null)]);
			}, '', 'internalRibbon', null, $ribbons, ['placeholder' => '- Int. štítky produktů -']);
		}

		if ($ribbons = $this->internalRibbonRepository->getArrayForSelect(type: InternalRibbon::TYPE_PRICE_LIST)) {
			$ribbons += ['0' => 'X - bez štítků'];
			$grid->addFilterDataMultiSelect(function (Collection $source, $value): void {
				$source->filter(['internalRibbonPriceLists' => \Eshop\Common\Helpers::replaceArrayValue($value, '0', null)]);
			}, '', 'internalRibbonPriceLists', null, $ribbons, ['placeholder' => '- Int. štítky ceníků -']);
		}

		if ($suppliers = $this->supplierRepository->getArrayForSelect()) {
			$grid->addFilterDataMultiSelect(function (ICollection $source, $suppliers): void {
				$expression = new Expression();

				foreach ($suppliers as $supplier) {
					$expression->add('OR', 'product.fk_supplierSource=%1$s', [$supplier]);
				}

				$subSelect = $this->supplierProductRepository->getConnection()
					->rows(['eshop_supplierproduct']);

				$subSelect->setBinderName('eshop_supplierproductFilterDataMultiSelectSupplier');

				$subSelect
					->where('this.fk_product = eshop_supplierproduct.fk_product')
					->where('eshop_supplierproduct.fk_supplier', $suppliers);

				$source->where('EXISTS (' . $subSelect->getSql() . ') OR ' . $expression->getSql(), $subSelect->getVars() + $expression->getVars());
			}, '', 'suppliers', null, $suppliers, ['placeholder' => '- Zdroje -']);
		}

		if ($producers = $this->producerRepository->getArrayForSelect()) {
			$grid->addFilterDataMultiSelect(function (ICollection $source, $value): void {
				$source->where('products.fk_producer', $value);
			}, '', 'producers', null, $producers, ['placeholder' => '- Výrobci -']);
		}

		$grid->addFilterDataSelect(function (ICollection $source, $value): void {
			$source->where('this.hidden', (bool) $value);
		}, '', 'hidden', null, ['1' => 'Skryté', '0' => 'Viditelné'])->setPrompt('- Viditelnost -');

		if ($this::SHOW_PRICE_HIDDEN) {
			$grid->addButtonBulkEdit('priceForm', ['hidden'], 'pricesGrid');
		}

		$grid->addFilterDataSelect(function (ICollection $source, $value): void {
			if ($value === 'master') {
				$source->where('product.fk_masterProduct IS NULL');
			} elseif ($value === 'slave') {
				$source->where('product.fk_masterProduct IS NOT NULL');
			}
		}, '', 'merged', null, ['master' => 'Pouze master', 'slave' => 'Pouze slave'])->setPrompt('- Sloučení -');

		if ($displayAmounts = $this->displayAmountRepository->getArrayForSelect()) {
			$displayAmounts += ['0' => 'X - nepřiřazená'];
			$grid->addFilterDataMultiSelect(function (Collection $source, $value): void {
				$source->where('product.fk_displayAmount', Helpers::replaceArrayValue($value, '0', null));
			}, '', 'displayAmount', null, $displayAmounts, ['placeholder' => '- Dostupnost -']);
		}

		// @TODO add from old grid
//		$submit = $grid->getForm()->addSubmit('copyTo', 'Kopírovat do ...')->setHtmlAttribute('class', 'btn btn-outline-primary btn-sm');
//		$submit->onClick[] = function ($button) use ($grid): void {
//			$grid->getPresenter()->redirect('copyToPricelist', [$grid->getSelectedIds(), $this->getParameter('pricelist'), 'standard']);
//		};

		return $grid;
	}

	/**
	 * @deprecated Use createComponentPricesGrid
	 */
	public function createComponentPriceListItems(): AdminGrid
	{
		$pricelist = $this->getParameter('pricelist');

		if (\is_string($pricelist)) {
			$pricelist = $this->priceListRepository->one($pricelist, true);
		}

		$grid = $this->gridFactory->create(
			$this->priceRepository->getPricesByPriceList($pricelist),
			20,
			'product.code',
			'ASC',
		);

		$grid->setItemCountCallback(function (Collection $collection): int {
			return $collection->count();
		});

		$grid->addColumnSelector();

		$grid->addColumnText('Kód', 'product.code', '%s', 'product.code', ['class' => 'fit']);

		$grid->addColumn('Produkt', function (Price $price, Datagrid $datagrid) {
			$link = $this->admin->isAllowed(':Eshop:Admin:Product:edit') ? $datagrid->getPresenter()?->link(
				':Eshop:Admin:Product:edit',
				[$price->product, 'backLink' => $this->storeRequest()],
			) : '#';

			return '<a href="' . $link . '">' . $price->product->name . '</a>';
		}, '%s');

		foreach ($this::SHOW_SUPPLIER_NAMES as $supplierId => $supplierName) {
			$supplierNames = $this->supplierProductRepository->many()
				->where('this.fk_supplier', $supplierId)
				->setSelect(['this.fk_product', 'this.name'])
				->setIndex('this.fk_product')
				->toArrayOf('name');

			$grid->addColumn("Název ($supplierName)", function (Price $price, Datagrid $datagrid) use ($supplierNames): string|null {
				return $supplierNames[$price->getValue('product')] ?? null;
			}, '%s');
		}

		/** @var null|string $autoPriceConfig */
		$autoPriceConfig = $this::CONFIGURATION[ProductFormConfig::class][ProductFormAutoPriceConfig::class] ?? null;

		if ($autoPriceConfig === ProductFormAutoPriceConfig::WITHOUT_VAT) {
			$grid->addColumnText('Cena', 'price', '%s');
		} else {
			$grid->addColumnInputPrice('Cena', 'price');
		}

		if ($this->shopperUser->getShowVat()) {
			if ($autoPriceConfig === ProductFormAutoPriceConfig::WITH_VAT) {
				$grid->addColumnText('Cena s DPH', 'priceVat', '%s');
			} else {
				$grid->addColumnInputPrice('Cena s DPH', 'priceVat');
			}
		}

		if ($autoPriceConfig === ProductFormAutoPriceConfig::WITHOUT_VAT) {
			$grid->addColumnText('Cena před slevou', 'priceBefore', '%s');
		} else {
			$grid->addColumnInputPrice('Cena před slevou', 'priceBefore');
		}

		if ($this->shopperUser->getShowVat()) {
			if ($autoPriceConfig === ProductFormAutoPriceConfig::WITH_VAT) {
				$grid->addColumnText('Cena s DPH před slevou', 'priceVatBefore', '%s');
			} else {
				$grid->addColumnInputPrice('Cena s DPH před slevou', 'priceVatBefore');
			}
		}

		if ($this::SHOW_PRICE_HIDDEN) {
			$grid->addColumnInputCheckbox('<i title="Skryto" class="far fa-eye-slash"></i>', 'hidden', orderExpression: 'hidden');
		}

		$grid->addColumnActionDelete();

		/** @var null|string $autoPriceConfig */
		$autoPriceConfig = $this::CONFIGURATION[ProductFormConfig::class][ProductFormAutoPriceConfig::class] ?? null;

		$grid->addButtonSaveAll(onRowUpdate: function (string $id, array &$prices, Price $price) use ($autoPriceConfig): void {
			if ((!$autoPriceConfig || $autoPriceConfig === ProductFormAutoPriceConfig::NONE || $autoPriceConfig === ProductFormAutoPriceConfig::WITH_VAT) && !isset($prices['price']) ||
				($autoPriceConfig === ProductFormAutoPriceConfig::WITHOUT_VAT && !isset($prices['priceVat']))) {
				return;
			}

			if ($autoPriceConfig === ProductFormAutoPriceConfig::WITHOUT_VAT) {
				$prices['price'] = \round($prices['priceVat'] * \fdiv(100, 100 + $this->vatRateRepository->getDefaultVatRates()[$price->product->vatRate]), ShopperUser::PRICE_PRECISSION);
				$prices['priceBefore'] = isset($prices['priceVatBefore']) ?
					\round($prices['priceVatBefore'] * \fdiv(100, 100 + $this->vatRateRepository->getDefaultVatRates()[$price->product->vatRate]), ShopperUser::PRICE_PRECISSION) :
					null;
			}

			if ($autoPriceConfig === ProductFormAutoPriceConfig::WITH_VAT) {
				$prices['priceVat'] = \round($prices['price'] * \fdiv(100 + $this->vatRateRepository->getDefaultVatRates()[$price->product->vatRate], 100), ShopperUser::PRICE_PRECISSION);
				$prices['priceVatBefore'] = isset($prices['priceBefore']) ?
					\round($prices['priceBefore'] * \fdiv(100 + $this->vatRateRepository->getDefaultVatRates()[$price->product->vatRate], 100), ShopperUser::PRICE_PRECISSION) :
					null;
			}

			foreach (['price', 'priceVat', 'priceBefore', 'priceVatBefore'] as $priceKey) {
				if (isset($prices[$priceKey])) {
					continue;
				}

				$prices[$priceKey] = null;
			}
		}, diff: false);
		$grid->addButtonDeleteSelected(null, false, null, 'this.uuid');

		$grid->addFilterButtons(['priceListItems', $this->getParameter('pricelist')]);

		$grid->addFilterTextInput('code', ['products.code', 'products.ean', 'products.name_cs'], null, 'Název, EAN, kód', '', '%s%%');
		
		$grid->addFilterInteger(function (ICollection $source, $value): void {
			$source->where('this.price >= :price', ['price' => $value]);
		}, null, 'priceFrom', 'Cena od')
			->setHtmlAttribute('placeholder', 'Cena od')
			->setHtmlAttribute('class', 'form-control form-control-sm')
			->setHtmlAttribute('style', 'width: 100px');

		if ($categories = $this->categoryRepository->getTreeArrayForSelect()) {
			$grid->addFilterDataSelect(function (Collection $source, $value): void {
				$categoryPath = $this->categoryRepository->one($value, true)->path;
				$source->join(['eshop_product_nxn_eshop_category'], 'eshop_product_nxn_eshop_category.fk_product=products.uuid');
				$source->join(['categories' => 'eshop_category'], 'categories.uuid=eshop_product_nxn_eshop_category.fk_category');
				$source->where('categories.path LIKE :category', ['category' => "$categoryPath%"]);
			}, '', 'category', null, $categories)->setPrompt('- Kategorie -');
		}

		if ($producers = $this->producerRepository->getArrayForSelect()) {
			$grid->addFilterDataMultiSelect(function (ICollection $source, $value): void {
				$source->where('products.fk_producer', $value);
			}, '', 'producers', null, $producers, ['placeholder' => '- Výrobci -']);
		}

		$grid->addFilterDataSelect(function (ICollection $source, $value): void {
			$source->where('products.hidden', (bool) $value);
		}, '', 'hidden', null, ['1' => 'Skryté', '0' => 'Viditelné'])->setPrompt('- Viditelnost -');

		$grid->addFilterDataSelect(function (ICollection $source, $value): void {
			$source->where('products.unavailable', (bool) $value);
		}, '', 'unavailable', null, ['1' => 'Neprodejné', '0' => 'Prodejné'])->setPrompt('- Prodejnost -');

		$submit = $grid->getForm()->addSubmit('copyTo', 'Kopírovat do ...')->setHtmlAttribute('class', 'btn btn-outline-primary btn-sm');

		$submit->onClick[] = function ($button) use ($grid): void {
			$grid->getPresenter()->redirect('copyToPricelist', [$grid->getSelectedIds(), $this->getParameter('pricelist'), 'standard']);
		};

		return $grid;
	}

	public function createComponentPriceForm(): AdminForm
	{
		$form = $this->formFactory->create();

		$form->addCheckbox('hidden', 'Skryto');

		return $form;
	}

	public function createComponentQuantityPricesGrid(): AdminGrid
	{
		$pricelist = $this->getParameter('pricelist');

		if (\is_string($pricelist)) {
			$pricelist = $this->priceListRepository->one($pricelist, true);
		}

		$grid = $this->gridFactory->create(
			$this->quantityPriceRepo->getPricesByPriceList($pricelist),
			20,
			'price',
			'ASC',
		);
		$grid->addColumnSelector();

		$grid->addColumnText('Kód produktu', 'product.code', '%s', 'product.code', ['class' => 'fit']);
		$grid->addColumn('Produkt', function (QuantityPrice $price, Datagrid $datagrid) {
			$link = $this->admin->isAllowed(':Eshop:Admin:Product:edit') ? $datagrid->getPresenter()->link(
				':Eshop:Admin:Product:edit',
				[$price->product, 'backLink' => $this->storeRequest()],
			) : '#';

			return '<a href="' . $link . '">' . $price->product->name . '</a>';
		}, '%s');

		$grid->addColumnInputPrice('Cena', 'price');

		$processTypes = [
			'price' => 'float',
		];

		if ($this->shopperUser->getShowVat()) {
			$grid->addColumnInputPrice('Cena s daní', 'priceVat');

			$processTypes += ['priceVat' => 'float'];
		}

		$grid->addColumnInputInteger('Od jakého množství je cena', 'validFrom', '', '', 'validFrom', []);

		$grid->addColumnActionDelete();

		$grid->addButtonSaveAll($this->shopperUser->getShowVat() ? ['priceVat', 'validFrom'] : ['validFrom'], $processTypes, null, false, null, null, false);
		$grid->addButtonDeleteSelected(null, false, null, 'this.uuid');

		$grid->addFilterTextInput('search', ['product.code', 'product.name_cs'], null, 'Kód, název');
		$grid->addFilterButtons(['quantityPrices', $this->getParameter('pricelist')]);

		$submit = $grid->getForm()->addSubmit('copyTo', 'Kopírovat do ...')->setHtmlAttribute('class', 'btn btn-outline-primary btn-sm');

		$submit->onClick[] = function ($button) use ($grid): void {
			$grid->getPresenter()->redirect('copyToPricelist', [$grid->getSelectedIds(), $this->getParameter('pricelist'), 'quantity']);
		};

		return $grid;
	}

	public function createComponentPriceListDetail(): AdminForm
	{
		$form = $this->formFactory->create();

		$form->addText('code', 'Kód');
		$form->addText('name', 'Název');
		$form->addTextArea('description', 'Popis');

		$form->addDataSelect('currency', 'Měna', $this->currencyRepository->getArrayForSelect());
		$form->addDataSelect('country', 'Země DPH', $this->countryRepo->getArrayForSelect());

		$discountInput = $form->addDataSelect('discount', 'Akce', $this->discountRepo->getArrayForSelect())->setPrompt('Žádná')->setDisabled();
		$onlyCouponInput = $form->addCheckbox('activeOnlyWithCoupon', 'Platí pouze se slevovým kupónem')->setDisabled();
		$discountInput->addCondition($form::FILLED)->toggle($onlyCouponInput->getHtmlId() . '-toogle');

		$form->addText('priority', 'Priorita')->addRule($form::INTEGER)->setRequired()->setDefaultValue(10);
		$form->addCheckbox('allowDiscountLevel', 'Povolit procentuální slevy')
			->setHtmlAttribute(
				'data-info',
				'Aplikuje se vždy největší z čtveřice: procentuální slevy produktu, procentuální slevy zákazníka, slevy věrnostního programu zákazníka nebo slevového kupónu.<br>
Pokud je povoleno, aplikuje zmíněnou procentuální slevu. Jinak aplikuje pouze slevu v rámci cen v aktivních ceníkách.',
			);
		$form->addCheckbox('isActive', 'Aktivní');

		if (isset($this::CONFIGURATION['customLabel']) && $this::CONFIGURATION['customLabel']) {
			$form->addText('customLabel', 'Vlastní štítek')
				->setHtmlAttribute('data-info', 'Použitý při exportu XML produktů pro Google jako "custom_label_1".')
				->addCondition($form::FILLED)->addRule($form::MAX_LENGTH, 'Maximálně 100 znaků!', 100);
		}

		$form->addMultiSelect2('internalRibbons', 'Interní štítky', $this->internalRibbonRepository->getArrayForSelect(type: InternalRibbon::TYPE_PRICE_LIST));

		$this->formFactory->addShopsContainerToAdminForm($form, false);

		$form->addSubmits(!$this->getParameter('pricelist'));

		$form->onSuccess[] = function (AdminForm $form): void {
			$values = $form->getValues('array');

			$pricelist = $this->priceListRepository->syncOne($values, null, true);

			$this->flashMessage('Uloženo', 'success');
			$form->processRedirect('priceListDetail', 'default', [$pricelist]);
		};

		return $form;
	}

	public function createComponentImportPriceList(): AdminForm
	{
		$form = $this->formFactory->create();
		$form->addUpload('file', 'CSV soubor')->setRequired()->setHtmlAttribute('data-info', '<h5 class="mt-2">Nápověda</h5>
<b>Povinné sloupce:</b><br>
product - Kód produktu<br>price - Cena<br>priceVat - Cena s daní<br>priceBefore - Předchozí cena<br>priceVatBefore - Předchozí cena s daní<br>');

		$form->addSelect('delimiter', 'Oddělovač', [
			';' => 'Středník (;)',
			',' => 'Čárka (,)',
			'   ' => 'Tab (\t)',
			' ' => 'Mezera ( )',
			'|' => 'Pipe (|)',
		]);

		$form->addSubmit('submit', 'Uložit');

		$form->onSuccess[] = function (Form $form): void {
			$values = $form->getValues('array');

			/** @var \Nette\Http\FileUpload $file */
			$file = $form->getValues('array')['file'];

			$pricelist = $this->getParameter('pricelist');
			$quantity = $this->getParameter('type') === 'quantity';

			$this->priceListRepository->getConnection()->getLink()->beginTransaction();

			try {
				$this->priceListRepository->csvImport(
					$pricelist,
					Reader::createFromString($file->getContents()),
					$quantity,
					$values['delimiter'],
				);

				$this->priceListRepository->getConnection()->getLink()->commit();

				$form->getPresenter()->flashMessage('Uloženo', 'success');
			} catch (\Throwable $e) {
				Debugger::log($e, ILogger::WARNING);
				$this->priceListRepository->getConnection()->getLink()->rollBack();

				$this->flashMessage($e->getMessage() !== '' ? $e->getMessage() : 'Import dat se nezdařil!', 'error');
			}

			$form->getPresenter()->redirect('priceListItems', $this->getParameter('pricelist'));
		};

		return $form;
	}

	public function actionPriceListDetail(Pricelist $pricelist): void
	{
		/** @var \Admin\Controls\AdminForm $priceListForm */
		$priceListForm = $this->getComponent('priceListDetail');

		$priceListForm->setDefaults($pricelist->toArray(['internalRibbons']));
	}

	public function renderPriceListDetail(): void
	{
		$this->template->headerLabel = 'Detail';
		$this->template->headerTree = [
			['Ceníky', 'default'],
			['Detail'],
		];
		$this->template->displayButtons = [$this->createBackButton('default')];
		$this->template->displayControls = [$this->getComponent('priceListDetail')];
	}

	public function renderPriceListItems(Pricelist $pricelist): void
	{
		$this->template->headerLabel = 'Ceny ceníku - ' . $pricelist->name . ' (' . $pricelist->currency->code . ')';
		$this->template->headerTree = [
			['Ceníky', 'default'],
			['Ceny'],
		];
		$this->template->displayButtons = [
			$this->createBackButton('default'),
			$this->createButtonWithClass(
				'importPriceList',
				'<i class="fas fa-file-import"></i> Import',
				'btn btn-outline-primary btn-sm',
				$pricelist,
			),
			$this->createButtonWithClass(
				'priceListExport!',
				'<i class="fas fa-file-export"></i> Export',
				'btn btn-outline-primary btn-sm',
				$pricelist->getPK(),
			),
		];
		$this->template->displayControls = [$this->getComponent('priceListItems')];
	}

	public function renderPriceListNew(): void
	{
		$this->template->headerLabel = 'Nový ceník';
		$this->template->headerTree = [
			['Ceníky', 'default'],
			['Nový ceník'],
		];
		$this->template->displayButtons = [$this->createBackButton('default')];
		$this->template->displayControls = [$this->getComponent('priceListDetail')];
	}

	public function renderImportPriceList(Pricelist $pricelist, string $type = 'standard'): void
	{
		$this->template->headerLabel = 'Importovat ceny';
		$this->template->headerTree = [
			['Ceníky', 'default'],
			['Ceny', 'priceListItems', $pricelist],
			['Import'],
		];
		$this->template->displayButtons = [
			$this->createBackButton($type === 'standard' ? 'priceListItems' : 'quantityPrices', $pricelist),
		];
		$this->template->displayControls = [$this->getComponent('importPriceList')];
	}

	public function renderDefault(): void
	{
		$this->template->headerLabel = 'Ceníky';
		$this->template->headerTree = [
			['Ceníky'],
		];

		if ($this->tab === 'priceLists') {
			$this->template->displayButtons = [$this->createNewItemButton('priceListNew')];
			$this->template->displayControls = [$this->getComponent('priceLists')];
		} elseif ($this->tab === 'prices') {
			$this->template->displayControls = [$this->getComponent('pricesGrid')];
		}

		$this->template->tabs = $this::TABS;
	}

	public function handlePriceListExport(string $pricelistId, string $type = 'standard'): void
	{
		$tempFilename = \tempnam($this->tempDir, 'csv');

		$this->priceListRepository->csvExport(
			$this->priceListRepository->one($pricelistId),
			Writer::createFromPath($tempFilename, 'w+'),
			$type === 'quantity',
			$this->shopperUser->getShowVat(),
		);

		$response = new FileResponse($tempFilename, 'cenik.csv', 'text/csv');
		$this->sendResponse($response);
	}

	public function renderQuantityPrices(Pricelist $pricelist): void
	{
		$this->template->headerLabel = 'Množstevní ceny ceníku - ' . $pricelist->name . ' (' . $pricelist->currency->code . ')';
		$this->template->headerTree = [
			['Ceníky', 'default'],
			['Množstevní ceny'],
		];
		$this->template->displayButtons = [
			$this->createBackButton('default'),
			$this->createNewItemButton('quantityPricesNew', [$pricelist]),
			$this->createButtonWithClass(
				'importPriceList',
				'<i class="fas fa-file-import"></i> Import',
				'btn btn-outline-primary btn-sm',
				$pricelist,
				'quantity',
			),
			$this->createButtonWithClass(
				'priceListExport!',
				'<i class="fas fa-file-export"></i> Export',
				'btn btn-outline-primary btn-sm',
				$pricelist->getPK(),
			),
//			$this->createButtonWithClass('copyToPricelist', '<i class="far fa-copy"></i> Kopírovat do ...',
//				'btn btn-outline-primary btn-sm', $pricelist, 'quantity'),
		];
		$this->template->displayControls = [$this->getComponent('quantityPricesGrid')];
	}

	public function renderQuantityPricesNew(Pricelist $pricelist): void
	{
		$this->template->headerLabel = 'Nová množstevní cena - ' . $pricelist->name . ' (' . $pricelist->currency->code . ')';

		$this->template->headerTree = [
			['Ceníky', 'default'],
			['Množstevní ceny', 'quantityPrices', $pricelist],
			['Nová množstevní cena'],
		];
		$this->template->displayButtons = [$this->createBackButton('quantityPrices', $pricelist)];
		$this->template->displayControls = [$this->getComponent('quantityPricesForm')];
	}

	public function createComponentQuantityPricesForm(): AdminForm
	{
		$form = $this->formFactory->create();

		$productInput = $form->addSelect2('product', 'Produkt', [], [
			'ajax' => [
				'url' => $this->link('getProductsForSelect2!'),
			],
			'placeholder' => 'Zvolte produkt',
		]);

		$form->addText('price', 'Cena')->addRule($form::FLOAT)->setRequired();
		$form->addText('priceVat', 'Cena s daní')->addRule($form::FLOAT);
		$form->addText('validFrom', 'Od jakého množství')->addRule($form::INTEGER)->addFilter('intval')->setNullable();
		$form->addHidden('pricelist', $this->getParameter('pricelist')->getPK());

		$form->addSubmits();

		$form->onValidate[] = function (AdminForm $form) use ($productInput): void {
			$data = $this->getHttpRequest()->getPost();

			if (isset($data['product'])) {
				return;
			}

			$productInput->addError('Toto pole je povinné!');
		};

		$form->onSuccess[] = function (AdminForm $form): void {
			$values = $form->getValues();

			$values['priceVat'] = $values['priceVat'] !== '' ? $values['priceVat'] : 0;
			$values['product'] = $form->getHttpData(Form::DATA_TEXT, 'product');

			$this->quantityPriceRepo->syncOne($values, null, true);

			$this->flashMessage('Uloženo', 'success');
			$form->processRedirect(
				'this',
				'quantityPrices',
				[$this->getParameter('pricelist')],
				[$this->getParameter('pricelist')],
			);
		};

		return $form;
	}

	public function createComponentCopyToPricelistForm(): AdminForm
	{
		/** @var \Grid\Datagrid $grid */
		$grid = $this->getComponent($this->getParameter('type') === 'standard' ? 'priceListItems' : 'quantityPricesGrid');

		$ids = $this->getParameter('ids') ?: [];
		$totalNo = $grid->getFilteredSource()->enum();
		$selectedNo = \count($ids);

		$form = $this->formFactory->create();
		$form->setAction($this->link('this', ['selected' => $this->getParameter('selected')]));
		$form->addRadioList('bulkType', 'Upravit', [
			'selected' => "vybrané ($selectedNo)",
			'all' => "celý výsledek ($totalNo)",
		])->setDefaultValue('selected');

		/** @var \Eshop\DB\Pricelist $originalPricelist */
		$originalPricelist = $this->getParameter('pricelist');
		$pricelists = $this->priceListRepository->many()
			->whereNot('uuid', $originalPricelist->getPK())
			->where('fk_currency', $originalPricelist->currency->getPK())
			->toArrayOf('name');

		$form->addDataSelect('targetPricelist', 'Cílový ceník', $pricelists)->setRequired();
		$form->addText('percent', 'Procentuální změna')->addRule($form::FLOAT)
			->setHtmlAttribute('data-info', 'Zadejte hodnotu v procentech (%).')
			->addRule([FormValidators::class, 'isPercentNoMax'], 'Zadaná hodnota není správná (>=0)!')
			->setRequired()->setDefaultValue(100);

		if ($this->getParameter('type') === 'standard') {
			$beforePricesInput = $form->addCheckbox('beforePrices', 'Importovat původní ceny')
				->setHtmlAttribute(
					'data-info',
					'Původní cena bude zobrazena u produktu jako přeškrtnutá cena (cena před slevou)',
				);

			$beforePricesSourceInput = $form->addSelect('beforePricesSource', 'Zdroj původních cen', [
				PricelistRepository::COPY_PRICES_BEFORE_PRICE_SOURCE => 'Zdrojový ceník',
				PricelistRepository::COPY_PRICES_BEFORE_PRICE_TARGET => 'Cílový ceník',
			])->setHtmlAttribute('data-info', 'Zdrojový ceník - Jako původní ceny budou použity normální ceny ze zdrojového ceníku.<br>
Cílový ceník - Jako původní ceny budou použity normální ceny ze cílového ceníku.<br>
<b>Řídí se nastavením "Přepsat existující ceny"!.</b>');

			$beforePricesInput->addCondition($form::FILLED)
				->toggle($beforePricesSourceInput->getHtmlId() . '-toogle');
		}

		$form->addCheckbox('overwrite', 'Přepsat existující ceny')
			->setHtmlAttribute('data-info', 'Existující ceny v cílovém ceníku budou přepsány.');

		$form->addSubmits();

		$form->onSuccess[] = function (AdminForm $form) use ($originalPricelist, $ids, $grid): void {
			$values = $form->getValues('array');

			/** @var \Eshop\DB\Pricelist $targetPricelist */
			$targetPricelist = $this->priceListRepository->one($values['targetPricelist']);
			$quantity = $this->getParameter('type') === 'quantity';

			$this->priceListRepository->copyPricesArray(
				$values['bulkType'] === 'selected' ? $ids : \array_keys($grid->getFilteredSource()->toArrayOf('uuid')),
				$targetPricelist,
				(float) $values['percent'] / 100,
				ShopperUser::PRICE_PRECISSION,
				$values['overwrite'],
				$values['beforePrices'] ?? false,
				$quantity,
				$values['beforePricesSource'],
			);

			$this->flashMessage('Uloženo', 'success');
			$form->processRedirect(
				'this',
				$quantity ? 'quantityPrices' : 'priceListItems',
				[$ids, $originalPricelist, $this->getParameter('type')],
				[$originalPricelist],
			);
		};

		return $form;
	}

	public function renderCopyToPricelist(array $ids, Pricelist $pricelist, string $type): void
	{
		unset($ids);

		$this->template->headerLabel = 'Kopírovat ceny';
		$this->template->headerTree = [
			['Ceníky', 'default'],
			['Ceny', 'priceListItems', $pricelist],
			['Kopírovat ceny'],
		];
		$this->template->displayButtons = [
			$this->createBackButton($type === 'standard' ? 'priceListItems' : 'quantityPrices', $pricelist),
		];
		$this->template->displayControls = [$this->getComponent('copyToPricelistForm')];
	}

	public function actionCopyToPricelist(array $ids, Pricelist $pricelist, string $type): void
	{
		unset($ids);
		unset($pricelist);
		unset($type);
//		/** @var \Forms\Form $form */
//		$form = $this->getComponent('newsletterExportProducts');
//
//		$products = '';
//		foreach ($ids as $id) {
//			$products .= $this->productRepository->one($id)->getFullCode() . ';';
//		}
//
//		if (Strings::length($products) > 0) {
//			$products = Strings::substring($products, 0, -1);
//		}
//
//		$form->setDefaults(['products' => $products]);
	}

	public function actionAggregate(array $ids): void
	{
		unset($ids);
	}

	public function renderAggregate(array $ids): void
	{
		unset($ids);

		$this->template->headerLabel = 'Agregace ceníků';
		$this->template->headerTree = [
			['Ceníky', 'default'],
			['Agregace ceníků'],
		];
		$this->template->displayButtons = [$this->createBackButton('default')];
		$this->template->displayControls = [$this->getComponent('aggregateForm')];
	}

	public function createComponentAggregateForm(): AdminForm
	{
		/** @var \Grid\Datagrid $grid */
		$grid = $this->getComponent('priceLists');

		$ids = $this->getParameter('ids') ?: [];
		$totalNo = $grid->getFilteredSource()->enum();
		$selectedNo = \count($ids);

		$idsPricelists = $this->priceListRepository->many()->where('this.uuid', $ids)->toArray();
		$collectionPricelists = $grid->getFilteredSource()->toArray();

		$idsPricelistsCurrency = $this->priceListRepository->checkSameCurrency($idsPricelists);
		$collectionPricelistsCurrency = $this->priceListRepository->checkSameCurrency($collectionPricelists);

		$form = $this->formFactory->create();

		$bulkTypeOptions = [];

		if ($idsPricelistsCurrency) {
			$bulkTypeOptions['selected'] = "vybrané ($selectedNo)";
		}

		if ($collectionPricelistsCurrency) {
			$bulkTypeOptions['all'] = "celý výsledek ($totalNo)";
		}

		if (\count($bulkTypeOptions) === 0) {
			$this->flashMessage('Provedeno', 'warning');
			$this->redirect('default');
		}

		$form->setAction($this->link('this', ['selected' => $this->getParameter('selected')]));
		$form->addRadioList('bulkType', 'Upravit', $bulkTypeOptions)->setDefaultValue($idsPricelistsCurrency ? 'selected' : 'all');

		$targetPricelistInput = $form->addDataSelect('targetPricelist', 'Cílový ceník', $this->priceListRepository->getArrayForSelect());
		$form->addSelect('aggregateFunction', 'Agregační funkce', [
			'min' => 'Minimum',
			'max' => 'Maximum',
			'avg' => 'Průměr',
			'med' => 'Medián',
		]);

		$form->addText('percentageChange', 'Procentuální změna')
			->setHtmlAttribute('data-info', 'Zadejte hodnotu v procentech (%).')
			->setDefaultValue(100)
			->setRequired()
			->addRule($form::FLOAT)
			->addRule([FormValidators::class, 'isPercentNoMax'], 'Neplatná hodnota!');

		$form->addInteger('roundingAccuracy', 'Přesnost zaokrouhlení')
			->setDefaultValue(2)
			->setRequired()
			->addRule($form::MIN, 'Zadejte číslo větší nebo rovné 0!', 0);

		$form->addCheckbox('overwriteExisting', 'Přepsat existující ceny')->setDefaultValue(true);
		$form->addCheckbox('usePriority', 'Počítat s prioritou ceníků')->setDefaultValue(true);
		$form->addCheckbox('skipZeroPrices', 'Přeskočit nulové ceny')->setDefaultValue(false)
			->setHtmlAttribute('data-info', 'Při zašrtnutí bude cena vynechána pokud je cena bez daně nebo cena s daní nula (0).');

		$form->addSubmit('submit', 'Provést');

		$form->onValidate[] = function (AdminForm $form) use ($idsPricelistsCurrency, $collectionPricelistsCurrency, $targetPricelistInput): void {
			if ($form->hasErrors()) {
				return;
			}

			$values = $form->getValues('array');

			/** @var \Eshop\DB\Pricelist $targetPricelist */
			$targetPricelist = $this->priceListRepository->one($values['targetPricelist']);

			if ($values['bulkType'] === 'selected') {
				if ($targetPricelist->currency->getPK() !== $idsPricelistsCurrency->getPK()) {
					$targetPricelistInput->addError('Ceník nemá stejnou měnu jako vybrané ceníky!');
				}
			} elseif ($targetPricelist->currency->getPK() !== $collectionPricelistsCurrency->getPK()) {
				$targetPricelistInput->addError('Ceník nemá stejnou měnu jako vybrané ceníky!');
			}
		};

		$form->onSuccess[] = function (AdminForm $form) use ($idsPricelists, $collectionPricelists): void {
			$values = $form->getValues('array');

			$this->priceListRepository->aggregatePricelists(
				$values['bulkType'] === 'selected' ? $idsPricelists : $collectionPricelists,
				$this->priceListRepository->one($values['targetPricelist']),
				$values['aggregateFunction'],
				$values['percentageChange'],
				$values['roundingAccuracy'],
				$values['overwriteExisting'],
				$values['usePriority'],
				$values['skipZeroPrices'],
			);

			$this->flashMessage('Provedeno', 'success');
			$this->redirect('this');
		};

		return $form;
	}
}
