<?php

declare(strict_types=1);

namespace Eshop\Admin;

use Admin\BackendPresenter;
use Eshop\DB\CategoryRepository;
use Eshop\DB\ProducerRepository;
use Eshop\DB\RibbonRepository;
use Eshop\DB\SupplierCategoryRepository;
use Eshop\DB\TagRepository;
use Eshop\DB\VatRateRepository;
use Eshop\FormValidators;
use Admin\Controls\AdminForm;
use Eshop\DB\CountryRepository;
use Eshop\DB\DiscountRepository;
use Eshop\DB\QuantityPrice;
use Eshop\DB\QuantityPriceRepository;
use Eshop\DB\CustomerRepository;
use Eshop\DB\CurrencyRepository;
use Eshop\DB\Price;
use Eshop\DB\Pricelist;
use Eshop\DB\PricelistRepository;
use Eshop\DB\PriceRepository;
use Eshop\DB\ProductRepository;
use Eshop\DB\SupplierRepository;
use Eshop\Shopper;
use Forms\Form;
use Grid\Datagrid;
use League\Csv\Reader;
use League\Csv\Writer;
use Nette\Application\Responses\FileResponse;
use StORM\Collection;
use StORM\Connection;
use StORM\ICollection;

class PricelistsPresenter extends BackendPresenter
{
	protected const CONFIGURATION = [
		'aggregate' => true,
	];

	/** @inject */
	public PricelistRepository $priceListRepository;

	/** @inject */
	public CurrencyRepository $currencyRepo;

	/** @inject */
	public PriceRepository $priceRepository;

	/** @inject */
	public SupplierRepository $supplierRepo;

	/** @inject */
	public CurrencyRepository $currencyRepository;

	/** @inject */
	public ProductRepository $productRepository;

	/** @inject */
	public CustomerRepository $customerRepository;

	/** @inject */
	public CategoryRepository $categoryRepository;

	/** @inject */
	public ProducerRepository $producerRepository;

	/** @inject */
	public SupplierRepository $supplierRepository;

	/** @inject */
	public SupplierCategoryRepository $supplierCategoryRepository;

	/** @inject */
	public TagRepository $tagRepository;

	/** @inject */
	public RibbonRepository $ribbonRepository;

	/** @inject */
	public Connection $storm;

	/** @inject */
	public DiscountRepository $discountRepo;

	/** @inject */
	public QuantityPriceRepository $quantityPriceRepo;

	/** @inject */
	public CountryRepository $countryRepo;

	/** @inject */
	public Shopper $shopper;

	/** @inject */
	public VatRateRepository $vatRateRepository;

	public function createComponentPriceLists()
	{
		$grid = $this->gridFactory->create($this->priceListRepository->many(), 20, 'priority', 'ASC');
		$grid->addColumnSelector();

		$grid->addColumnText('Kód', 'code', '%s', 'code', ['class' => 'fit']);
		$grid->addColumnText('Měna', "currency.code'", '%s', null, ['class' => 'fit'])->onRenderCell[] = [
			$grid,
			'decoratorNowrap'
		];
		$grid->addColumnText('Název', 'name', '%s', 'name');
		$grid->addColumn('Akce', function (Pricelist $object) {
			$link = $this->admin->isAllowed(':Eshop:Admin:Discount:detail') && $object->discount ? $this->link(':Eshop:Admin:Discount:detail',
				[$object->discount, 'backLink' => $this->storeRequest()]) : '#';

			return $object->discount ? "<a href='" . $link . "'>" . $object->discount->name . "</a>" : '';
		}, '%s');

		$grid->addColumnText('Akce od', "discount.validFrom|date:'d.m.Y G:i'", '%s', null,
			['class' => 'fit'])->onRenderCell[] = [$grid, 'decoratorNowrap'];
		$grid->addColumnText('Akce do', "discount.validTo|date:'d.m.Y G:i'", '%s', null,
			['class' => 'fit'])->onRenderCell[] = [$grid, 'decoratorNowrap'];

		$grid->addColumn('Zdroj', function (Pricelist $object) {
			$link = $this->admin->isAllowed(':Eshop:Admin:Supplier:detail') && $object->supplier ? $this->link(':Eshop:Admin:Supplier:detail',
				[$object->supplier, 'backLink' => $this->storeRequest()]) : '#';

			return $object->supplier ? "<a href='" . $link . "'>" . $object->supplier->name . "</a>" : '';
		}, '%s');

		$grid->addColumnInputInteger('Priorita', 'priority', '', '', 'priority', [], true);
		$grid->addColumnInputCheckbox('Aktivní', 'isActive', '', '', 'isActive');

		$grid->addColumnLink('priceListItems', 'Ceny');
		$grid->addColumnLink('quantityPrices', 'Množstevní ceny');

		$grid->addColumnLinkDetail('priceListDetail');
		$grid->addColumnActionDelete();

		$grid->addButtonSaveAll();
		$grid->addButtonDeleteSelected();

		$grid->addFilterTextInput('search', ['name'], null, 'Název');
		$grid->addFilterSelectInput('search2', 'fk_currency = :s', null, '- Měna -', null,
			$this->currencyRepo->getArrayForSelect(), 's');
		$grid->addFilterButtons();

		if (isset(static::CONFIGURATION['aggregate']) && static::CONFIGURATION['aggregate']) {

			$submit = $grid->getForm()->addSubmit('aggregate', 'Agregovat ...')->setHtmlAttribute('class', 'btn btn-outline-primary btn-sm');

			$submit->onClick[] = function ($button) use ($grid) {
				$grid->getPresenter()->redirect('aggregate', [$grid->getSelectedIds()]);
			};
		}

		return $grid;
	}

	public function createComponentPriceListItems()
	{
		$grid = $this->gridFactory->create($this->priceRepository->getPricesByPriceList($this->getParameter('pricelist')),
			20, 'product.code', 'ASC');
		$grid->addColumnSelector();

		$grid->addColumnText('Kód', 'product.code', '%s', 'product.code', ['class' => 'fit']);

		$grid->addColumn('Produkt', function (Price $price, Datagrid $datagrid) {
			$link = $this->admin->isAllowed(':Eshop:Admin:Product:edit') && $price->product ? $datagrid->getPresenter()->link(':Eshop:Admin:Product:edit',
				[$price->product, 'backLink' => $this->storeRequest()]) : '#';

			return '<a href="' . $link . '">' . $price->product->name . '</a>';
		}, '%s');

		$grid->addColumnInputPrice('Cena', 'price');
		if ($this->shopper->getShowVat()) {
			$grid->addColumnInputPrice('Cena s DPH', 'priceVat');
		}

		$grid->addColumnInputPrice('Cena před slevou', 'priceBefore');

		$nullColumns = [
			'priceBefore'
		];

		$saveAllTypes = [
			'price' => 'float',
			'priceBefore' => 'float',
		];

		if ($this->shopper->getShowVat()) {
			$grid->addColumnInputPrice('Cena s DPH před slevou', 'priceVatBefore');

			$saveAllTypes += ['priceVat' => 'float', 'priceVatBefore' => 'float'];
			$nullColumns = ['priceBefore', 'priceVatBefore'];
		}

		$grid->addColumnActionDelete();

		$grid->addButtonSaveAll($nullColumns, $saveAllTypes, null, false, function ($key, &$data, $type, Price $object) {
			if ($key == 'price' && !isset($data['price'])) {
				$data['price'] = 0;
				return;
			}

			if ($key == 'priceVat' && !isset($data['priceVat'])) {
				$newValue = \floatval($data['price']) + (\floatval($data['price']) * \fdiv(\floatval($this->vatRateRepository->getDefaultVatRates()[$object->product->vatRate]), 100));
			} else {
				$newValue = $data[$key];
			}

			if ($type == 'float') {
				$data[$key] = \floatval(\str_replace(',', '.', $newValue));
				return;
			}

			$data[$key] = \settype($newValue, $type) ? $newValue : null;

			return;
		}, null, false);
		$grid->addButtonDeleteSelected(null, false, null, 'this.uuid');

		$grid->addFilterButtons(['priceListItems', $this->getParameter('pricelist')]);

		$grid->addFilterTextInput('code', ['products.code', 'products.ean', 'products.name_cs'], null, 'Název, EAN, kód', '', '%s%%');

		if ($categories = $this->categoryRepository->getTreeArrayForSelect()) {
			$grid->addFilterDataSelect(function (Collection $source, $value) {
				$categoryPath = $this->categoryRepository->one($value)->path;
				$source->join(['eshop_product_nxn_eshop_category'], 'eshop_product_nxn_eshop_category.fk_product=products.uuid');
				$source->join(['categories' => 'eshop_category'], 'categories.uuid=eshop_product_nxn_eshop_category.fk_category');
				$source->where('categories.path LIKE :category', ['category' => "$categoryPath%"]);
			}, '', 'category', null, $categories)->setPrompt('- Kategorie -');
		}

		if ($producers = $this->producerRepository->getArrayForSelect()) {
			$grid->addFilterDataMultiSelect(function (ICollection $source, $value) {
				$source->where('products.fk_producer', $value);
			}, '', 'producers', null, $producers, ['placeholder' => '- Výrobci -']);
		}

		if ($tags = $this->tagRepository->getArrayForSelect()) {
			$grid->addFilterDataMultiSelect(function (ICollection $source, $value) {
				$source->join(['tags' => 'eshop_product_nxn_eshop_tag'], 'tags.fk_product=products.uuid');
				$source->where('tags.fk_tag', $value);
			}, '', 'tags', null, $tags, ['placeholder' => '- Tagy -']);
		}

		if ($ribbons = $this->ribbonRepository->getArrayForSelect()) {
			$grid->addFilterDataMultiSelect(function (ICollection $source, $value) {
				$source->join(['ribbons' => 'eshop_product_nxn_eshop_ribbon'], 'ribbons.fk_product=products.uuid');
				$source->where('ribbons.fk_ribbon', $value);
			}, '', 'ribbons', null, $ribbons, ['placeholder' => '- Štítky -']);
		}

		$grid->addFilterDataSelect(function (ICollection $source, $value) {
			$source->where('products.hidden', (bool)$value);
		}, '', 'hidden', null, ['1' => 'Skryté', '0' => 'Viditelné'])->setPrompt('- Viditelnost -');

		$grid->addFilterDataSelect(function (ICollection $source, $value) {
			$source->where('products.unavailable', (bool)$value);
		}, '', 'unavailable', null, ['1' => 'Neprodejné', '0' => 'Prodejné'])->setPrompt('- Prodejnost -');

		$submit = $grid->getForm()->addSubmit('copyTo', 'Kopírovat do ...')->setHtmlAttribute('class', 'btn btn-outline-primary btn-sm');

		$submit->onClick[] = function ($button) use ($grid) {
			$grid->getPresenter()->redirect('copyToPricelist', [$grid->getSelectedIds(), $this->getParameter('pricelist'), 'standard']);
		};

		return $grid;
	}

	public function createComponentQuantityPricesGrid()
	{
		$grid = $this->gridFactory->create($this->quantityPriceRepo->getPricesByPriceList($this->getParameter('pricelist')),
			20, 'price', 'ASC');
		$grid->addColumnSelector();

		$grid->addColumnText('Kód produktu', 'product.code', '%s', 'product.code', ['class' => 'fit']);
		$grid->addColumn('Produkt', function (QuantityPrice $price, Datagrid $datagrid) {
			$link = $this->admin->isAllowed(':Eshop:Admin:Product:edit') && $price->product ? $datagrid->getPresenter()->link(':Eshop:Admin:Product:edit',
				[$price->product, 'backLink' => $this->storeRequest()]) : '#';

			return '<a href="' . $link . '">' . $price->product->name . '</a>';
		}, '%s');

		$grid->addColumnInputPrice('Cena', 'price');

		$processTypes = [
			'price' => 'float',
		];

		if ($this->shopper->getShowVat()) {
			$grid->addColumnInputPrice('Cena s daní', 'priceVat');

			$processTypes += ['priceVat' => 'float'];
		}

		$grid->addColumnInputInteger('Od jakého množství je cena', 'validFrom', '', '', 'validFrom', []);

		$grid->addColumnActionDelete();

		$grid->addButtonSaveAll($this->shopper->getShowVat() ? ['priceVat', 'validFrom'] : ['validFrom'],
			$processTypes);
		$grid->addButtonDeleteSelected(null, false, null, 'this.uuid');

		$grid->addFilterTextInput('search', ['product.code', 'product.name_cs'], null, 'Kód, název');
		$grid->addFilterButtons(['quantityPrices', $this->getParameter('pricelist')]);

		$submit = $grid->getForm()->addSubmit('copyTo', 'Kopírovat do ...')->setHtmlAttribute('class', 'btn btn-outline-primary btn-sm');

		$submit->onClick[] = function ($button) use ($grid) {
			$grid->getPresenter()->redirect('copyToPricelist', [$grid->getSelectedIds(), $this->getParameter('pricelist'), 'quantity']);
		};

		return $grid;
	}

	public function createComponentPriceListDetail()
	{
		$form = $this->formFactory->create();

		$form->addText('code', 'Kód');
		$form->addText('name', 'Název');

		$form->addDataSelect('currency', 'Měna', $this->currencyRepository->getArrayForSelect());
		$form->addDataSelect('country', 'Země DPH', $this->countryRepo->getArrayForSelect());
		$form->addDataSelect('discount', 'Akce', $this->discountRepo->getArrayForSelect())->setPrompt('Žádná');
		$form->addDataSelect('supplier', 'Zdroj', $this->supplierRepo->getArrayForSelect())->setPrompt('Žádný');
		$form->addText('priority', 'Priorita')->addRule($form::INTEGER)->setRequired()->setDefaultValue(10);
		$form->addCheckbox('allowDiscountLevel', 'Povolit slevovou hladinu');
		$form->addCheckbox('isPurchase', 'Nákupní');
		$form->addCheckbox('isActive', 'Aktivní');

		$form->addSubmits(!$this->getParameter('pricelist'));

		$form->onSuccess[] = function (AdminForm $form) {
			$values = $form->getValues('array');

			$pricelist = $this->priceListRepository->syncOne($values, null, true);

			$this->flashMessage('Uloženo', 'success');
			$form->processRedirect('priceListDetail', 'default', [$pricelist]);
		};

		return $form;
	}

	public function createComponentImportPriceList()
	{
		$form = $this->formFactory->create();
		$form->addUpload('file', 'CSV soubor')->setRequired();
		$form->addSubmit('submit', 'Uložit');

		$form->onSuccess[] = function (Form $form) {
			/** @var \Nette\Http\FileUpload $file */
			$file = $form->getValues()->file;

			$pricelist = $this->getParameter('pricelist');
			$quantity = $this->getParameter('type') === 'quantity';

			$this->priceListRepository->csvImport($pricelist, Reader::createFromString($file->getContents()),
				$quantity);


			$form->getPresenter()->flashMessage('Uloženo', 'success');
			$form->getPresenter()->redirect('priceListItems', $this->getParameter('pricelist'));
		};

		return $form;
	}

	public function actionPriceListDetail(Pricelist $pricelist): void
	{
		/** @var AdminForm $priceListForm */
		$priceListForm = $this->getComponent('priceListDetail');

		$priceListForm->setDefaults($pricelist->toArray());
	}

	public function renderPriceListDetail(Pricelist $pricelist): void
	{
		$this->template->headerLabel = 'Detail ceníku';
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
			$this->createButtonWithClass('importPriceList', '<i class="fas fa-file-import"></i> Import',
				'btn btn-outline-primary btn-sm', $pricelist),
			$this->createButtonWithClass('priceListExport!', '<i class="fas fa-file-export"></i> Export',
				'btn btn-outline-primary btn-sm', $pricelist->getPK()),
//			$this->createButtonWithClass('copyToPricelist', '<i class="far fa-copy"></i> Kopírovat do ...',
//				'btn btn-outline-primary btn-sm', $pricelist),
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

	public function renderPriceListItemsNew(Pricelist $pricelist): void
	{
		$this->template->headerLabel = 'Nová cena ceníku - ' . $pricelist->name . ' (' . $pricelist->currency->code . ')';
		$this->template->headerTree = [
			['Ceníky', 'default'],
			['Ceny', 'priceListItems', $pricelist],
			['Nová cena'],
		];
		$this->template->displayButtons = [$this->createBackButton('priceListItems', $this->getParameter('pricelist'))];
		$this->template->displayControls = [$this->getComponent('priceListItemsNew')];
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
			$this->createBackButton($type === 'standard' ? 'priceListItems' : 'quantityPrices', $pricelist)
		];
		$this->template->displayControls = [$this->getComponent('importPriceList')];
	}

	public function renderDefault()
	{
		$this->template->headerLabel = 'Ceníky';
		$this->template->headerTree = [
			['Ceníky'],
		];
		$this->template->displayButtons = [$this->createNewItemButton('priceListNew')];
		$this->template->displayControls = [$this->getComponent('priceLists')];
	}

	public function handlePriceListExport(string $pricelistId, string $type = 'standard')
	{
		$tempFilename = \tempnam($this->tempDir, "csv");

		$this->priceListRepository->csvExport($this->priceListRepository->one($pricelistId),
			Writer::createFromPath($tempFilename, 'w+'), $type === 'quantity', $this->shopper->getShowVat());

		$response = new FileResponse($tempFilename, "cenik.csv", 'text/csv');
		$this->sendResponse($response);
	}

	public function renderQuantityPrices(Pricelist $pricelist)
	{
		$this->template->headerLabel = 'Množstevní ceny ceníku - ' . $pricelist->name . ' (' . $pricelist->currency->code . ')';
		$this->template->headerTree = [
			['Ceníky', 'default'],
			['Množstevní ceny'],
		];
		$this->template->displayButtons = [
			$this->createBackButton('default'),
			$this->createNewItemButton('quantityPricesNew', [$pricelist]),
			$this->createButtonWithClass('importPriceList', '<i class="fas fa-file-import"></i> Import',
				'btn btn-outline-primary btn-sm', $pricelist, 'quantity'),
			$this->createButtonWithClass('priceListExport!', '<i class="fas fa-file-export"></i> Export',
				'btn btn-outline-primary btn-sm', $pricelist->getPK()),
//			$this->createButtonWithClass('copyToPricelist', '<i class="far fa-copy"></i> Kopírovat do ...',
//				'btn btn-outline-primary btn-sm', $pricelist, 'quantity'),
		];
		$this->template->displayControls = [$this->getComponent('quantityPricesGrid')];
	}

	public function renderQuantityPricesNew(Pricelist $pricelist)
	{
		$this->template->headerLabel = 'Nová množstevní cena - ' . $pricelist->name . ' (' . $pricelist->currency->code . ')';;
		$this->template->headerTree = [
			['Ceníky', 'default'],
			['Množstevní ceny', 'quantityPrices', $pricelist],
			['Nová množstevní cena'],
		];
		$this->template->displayButtons = [$this->createBackButton('quantityPrices', $pricelist)];
		$this->template->displayControls = [$this->getComponent('quantityPricesForm')];
	}


	public function createComponentQuantityPricesForm()
	{
		$form = $this->formFactory->create();

		$form->addText('product', 'Produkt')
			->setHtmlAttribute('data-info', 'Zadejte kód, subkód nebo EAN')
			->addRule([FormValidators::class, 'isProductExists'], 'Produkt neexistuje nebo neplatná hodnota!',
				[$this->productRepository])
			->setRequired();

		$form->addText('price', 'Cena')->addRule($form::FLOAT)->setRequired();
		$form->addText('priceVat', 'Cena s daní')->addRule($form::FLOAT);
		$form->addText('validFrom', 'Od jakého množství')->addRule($form::INTEGER)->addFilter('intval')->setNullable();
		$form->addHidden('pricelist', $this->getParameter('pricelist')->getPK());

		$form->addSubmits();

		$form->onSuccess[] = function (AdminForm $form) {
			$values = $form->getValues();

			$values['priceVat'] = $values['priceVat'] !== '' ?: 0;
			$values['product'] = $this->productRepository->getProductByCodeOrEAN($values['product'])->getPK();

			$this->quantityPriceRepo->syncOne($values, null, true);

			$this->flashMessage('Uloženo', 'success');
			$form->processRedirect('this', 'quantityPrices', [$this->getParameter('pricelist')],
				[$this->getParameter('pricelist')]);
		};

		return $form;
	}

	public function createComponentCopyToPricelistForm()
	{
		/** @var \Grid\Datagrid $grid */
		$grid = $this->getComponent($this->getParameter('type') == 'standard' ? 'priceListItems' : 'quantityPricesGrid');

		$ids = $this->getParameter('ids') ?: [];
		$totalNo = $grid->getFilteredSource()->enum();
		$selectedNo = \count($ids);

		$form = $this->formFactory->create();
		$form->setAction($this->link('this', ['selected' => $this->getParameter('selected')]));
		$form->addRadioList('bulkType', 'Upravit', [
			'selected' => "vybrané ($selectedNo)",
			'all' => "celý výsledek ($totalNo)",
		])->setDefaultValue('selected');

		/** @var Pricelist $originalPricelist */
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

		$form->addInteger('roundPrecision', 'Přesnost zaokrouhlení')
			->setDefaultValue(2)->setRequired()
			->setHtmlAttribute('data-info',
				'Kladná čísla určují desetinnou část. Např.: přesnost 1 zaokrouhlí na 1 destinné místo. <br>Záporná čísla určují celou část. Např.: -2 zaokrouhlí na stovky.');

		if ($this->getParameter('type') == 'standard') {
			$form->addCheckbox('beforePrices', 'Importovat původní ceny')
				->setHtmlAttribute('data-info',
					'Původní cena bude zobrazena u produktu jako přeškrtnutá cena (cena před slevou)');
		}

		$form->addCheckbox('overwrite', 'Přepsat existující ceny')
			->setHtmlAttribute('data-info', 'Existující ceny v cílovém ceníku budou přepsány');

		$form->addSubmits();

		$form->onSuccess[] = function (AdminForm $form) use ($originalPricelist, $ids, $grid) {
			$values = $form->getValues('array');

			/** @var Pricelist $targetPricelist */
			$targetPricelist = $this->priceListRepository->one($values['targetPricelist']);
			$quantity = $this->getParameter('type') === 'quantity';

			$this->priceListRepository->copyPricesArray(
				$values['bulkType'] == 'selected' ? $ids : array_keys($grid->getFilteredSource()->toArrayOf('uuid')),
				$targetPricelist,
				(float)$values['percent'] / 100,
				$values['roundPrecision'],
				$values['overwrite'],
				$values['beforePrices'] ?? false,
				$quantity
			);

			$this->flashMessage('Uloženo', 'success');
			$form->processRedirect(
				'this',
				$quantity ? 'quantityPrices' : 'priceListItems',
				[$ids, $originalPricelist, $this->getParameter('type')],
				[$originalPricelist]);
		};

		return $form;
	}

	public function renderCopyToPricelist(array $ids, Pricelist $pricelist, string $type)
	{
		$this->template->headerLabel = 'Kopírovat ceny';
		$this->template->headerTree = [
			['Ceníky', 'default'],
			['Ceny', 'priceListItems', $pricelist],
			['Kopírovat ceny'],
		];
		$this->template->displayButtons = [
			$this->createBackButton($type === 'standard' ? 'priceListItems' : 'quantityPrices', $pricelist)
		];
		$this->template->displayControls = [$this->getComponent('copyToPricelistForm')];
	}

	public function actionCopyToPricelist(array $ids, Pricelist $pricelist, string $type)
	{
//		/** @var \Forms\Form $form */
//		$form = $this->getComponent('newsletterExportProducts');
//
//		$products = '';
//		foreach ($ids as $id) {
//			$products .= $this->productRepository->one($id)->getFullCode() . ';';
//		}
//
//		if (\strlen($products) > 0) {
//			$products = \substr($products, 0, -1);
//		}
//
//		$form->setDefaults(['products' => $products]);
	}

	public function actionAggregate(array $ids)
	{

	}

	public function renderAggregate(array $ids)
	{
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

		$form->setAction($this->link('this', ['selected' => $this->getParameter('selected')]));
		$form->addRadioList('bulkType', 'Upravit', $bulkTypeOptions)->setDefaultValue($idsPricelistsCurrency ? 'selected' : 'all');

		$form->addDataSelect('targetPricelist', 'Cílový ceník', $this->priceListRepository->getArrayForSelect());
		$form->addSelect('aggregateFunction', 'Agregační funkce', [
			'min' => 'Minimum',
			'max' => 'Maximum',
			'avg' => 'Průměr',
			'med' => 'Medián'
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

		$form->addSubmit('submit', 'Uložit');

		$form->onValidate[] = function (AdminForm $form) use ($idsPricelists, $collectionPricelists, $idsPricelistsCurrency, $collectionPricelistsCurrency, $grid) {
			$values = $form->getValues('array');

			/** @var Pricelist $targetPricelist */
			$targetPricelist = $this->priceListRepository->one($values['targetPricelist']);

			if ($values['bulkType'] == 'selected') {
				if ($targetPricelist->currency->getPK() != $idsPricelistsCurrency->getPK()) {
					$form['targetPricelist']->addError('Ceník nemá stejnou měnu jako vybrané ceníky!');
				}
			} elseif ($targetPricelist->currency->getPK() != $collectionPricelistsCurrency->getPK()) {
				$form['targetPricelist']->addError('Ceník nemá stejnou měnu jako vybrané ceníky!');
			}
		};

		$form->onSuccess[] = function (AdminForm $form) use ($idsPricelists, $collectionPricelists, $idsPricelistsCurrency, $collectionPricelistsCurrency, $grid) {
			$values = $form->getValues('array');

			$this->priceListRepository->aggregatePricelists(
				$values['bulkType'] == 'selected' ? $idsPricelists : $collectionPricelists,
				$this->priceListRepository->one($values['targetPricelist']),
				$values['aggregateFunction'],
				$values['percentageChange'],
				$values['roundingAccuracy'],
				$values['overwriteExisting'],
				$values['usePriority'],
			);

			$this->flashMessage('Uloženo', 'success');
			$this->redirect('this');
		};

		return $form;
	}
}
