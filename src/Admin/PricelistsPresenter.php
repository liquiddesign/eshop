<?php

declare(strict_types=1);

namespace Eshop\Admin;

use Admin\BackendPresenter;
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
use Forms\Form;
use Grid\Datagrid;
use League\Csv\Reader;
use League\Csv\Writer;
use Nette\Application\Responses\FileResponse;
use StORM\Connection;
use Nette;

class PricelistsPresenter extends BackendPresenter
{
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
	public Connection $storm;
	
	/** @inject */
	public DiscountRepository $discountRepo;
	
	/** @inject */
	public QuantityPriceRepository $quantityPriceRepo;
	
	/** @inject */
	public CountryRepository $countryRepo;
	
	public function createComponentPriceLists()
	{
		$grid = $this->gridFactory->create($this->priceListRepository->many(), 20, 'priority', 'ASC');
		$grid->addColumnSelector();
		
		$grid->addColumnText('Kód', 'code', '%s', 'code', ['class' => 'fit']);
		$grid->addColumnText('Měna', "currency.code'", '%s', null, ['class' => 'fit'])->onRenderCell[] = [$grid, 'decoratorNowrap'];
		$grid->addColumnText('Název', 'name', '%s', 'name');
		$grid->addColumn('Akce', function (Pricelist $object) {
			$link = $this->admin->isAllowed(':Eshop:Admin:Discount:detail') && $object->discount ? $this->link(':Eshop:Admin:Discount:detail', [$object->discount, 'backLink' => $this->storeRequest()]) : '#';
			
			return $object->discount ? "<a href='" . $link . "'>" . $object->discount->name . "</a>" : '';
		}, '%s');
		
		$grid->addColumnText('Akce od', "discount.validFrom|date:'d.m.Y G:i'", '%s', null, ['class' => 'fit'])->onRenderCell[] = [$grid, 'decoratorNowrap'];
		$grid->addColumnText('Akce do', "discount.validTo|date:'d.m.Y G:i'", '%s', null, ['class' => 'fit'])->onRenderCell[] = [$grid, 'decoratorNowrap'];
		
		$grid->addColumn('Dodavatel', function (Pricelist $object) {
			$link = $this->admin->isAllowed(':Eshop:Admin:Supplier:detail') && $object->supplier ? $this->link(':Eshop:Admin:Supplier:detail', [$object->supplier, 'backLink' => $this->storeRequest()]) : '#';
			
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
		$grid->addFilterSelectInput('search2', 'fk_currency = :s', null, '- Měna -', null, $this->currencyRepo->getArrayForSelect(), 's');
		$grid->addFilterButtons();
		
		return $grid;
	}
	
	public function createComponentPriceListItems()
	{
		$grid = $this->gridFactory->create($this->priceRepository->getPricesByPriceList($this->getParameter('priceList')), 20, 'price', 'ASC');
		$grid->addColumnSelector();
		
		$grid->addColumnText('Kód', 'product.code', '%s', 'product.code', ['class' => 'fit']);
		
		$grid->addColumn('Produkt', function (Price $price, Datagrid $datagrid) {
			$link = $this->admin->isAllowed(':Eshop:Admin:Product:edit') && $price->product ? $datagrid->getPresenter()->link(':Eshop:Admin:Product:edit', [$price->product, 'backLink' => $this->storeRequest()]) : '#';
			
			return '<a href="' . $link . '">' . $price->product->name . '</a>';
		}, '%s');
		
		$grid->addColumnInputPrice('Cena', 'price');
		$grid->addColumnInputPrice('Cena s DPH', 'priceVat');
		$grid->addColumnInputPrice('Cena před slevou', 'priceBefore');
		$grid->addColumnInputPrice('Cena s DPH před slevou', 'priceVatBefore');
		
		$grid->addColumnActionDelete();
		
		$grid->addButtonSaveAll(['priceVat', 'priceBefore', 'priceVatBefore'], [
			'price' => 'float',
			'priceVat' => 'float',
			'priceBefore' => 'float',
			'priceVatBefore' => 'float',
		]);
		$grid->addButtonDeleteSelected();
		
		$grid->addFilterTextInput('search', ['product.code', 'product.name_cs'], null, 'Kód, název');
		$grid->addFilterButtons(['priceListItems', $this->getParameter('priceList')]);
		
		return $grid;
	}
	
	
	public function createComponentQuantityPricesGrid()
	{
		$grid = $this->gridFactory->create($this->quantityPriceRepo->getPricesByPriceList($this->getParameter('pricelist')), 20, 'price', 'ASC');
		$grid->addColumnSelector();
		
		$grid->addColumnText('Kód produktu', 'product.code', '%s', 'product.code', ['class' => 'fit']);
		$grid->addColumn('Produkt', function (QuantityPrice $price, Datagrid $datagrid) {
			$link = $this->admin->isAllowed(':Eshop:Admin:Product:edit') && $price->product ? $datagrid->getPresenter()->link(':Eshop:Admin:Product:edit', [$price->product, 'backLink' => $this->storeRequest()]) : '#';
			
			return '<a href="' . $link . '">' . $price->product->name . '</a>';
		}, '%s');
		
		$grid->addColumnInputPrice('Cena', 'price');
		$grid->addColumnInputPrice('Cena s daní', 'priceVat');
		$grid->addColumnInputInteger('Od jakého množství je cena', 'validFrom', '', '', 'validFrom', []);
		
		$grid->addColumnActionDelete();
		
		$grid->addButtonSaveAll(['priceVat', 'validFrom'], [
			'price' => 'float',
			'priceVat' => 'float',
		]);
		$grid->addButtonDeleteSelected();
		
		$grid->addFilterTextInput('search', ['product.code', 'product.name_cs'], null, 'Kód, název');
		$grid->addFilterButtons(['quantityPrices', $this->getParameter('pricelist')]);
		
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
		$form->addDataSelect('supplier', 'Dodavatel', $this->supplierRepo->getArrayForSelect())->setPrompt('Žádný');
		$form->addText('priority', 'Priorita')->addRule($form::INTEGER)->setRequired()->setDefaultValue(10);
		$form->addCheckbox('allowDiscountLevel', 'Povolit slevovou hladinu');
		$form->addCheckbox('isActive', 'Aktivní');
		
		$form->addSubmits(!$this->getParameter('priceList'));
		
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
			
			$priceList = $this->getParameter('priceList');
			$quantity = $this->getParameter('type') === 'quantity';
			
			$this->priceListRepository->csvImport($priceList, Reader::createFromString($file->getContents()), $quantity);
			
			
			$form->getPresenter()->flashMessage('Uloženo', 'success');
			$form->getPresenter()->redirect('priceListItems', $this->getParameter('priceList'));
		};
		
		return $form;
	}
	
	public function actionPriceListDetail(Pricelist $priceList): void
	{
		/** @var \App\Admin\Controls\AdminForm $priceListForm */
		$priceListForm = $this->getComponent('priceListDetail');
		
		$priceListForm->setDefaults($priceList->toArray());
	}
	
	public function renderPriceListDetail(Pricelist $priceList): void
	{
		$this->template->headerLabel = 'Detail ceníku';
		$this->template->headerTree = [
			['Ceníky', 'default'],
			['Detail'],
		];
		$this->template->displayButtons = [$this->createBackButton('default')];
		$this->template->displayControls = [$this->getComponent('priceListDetail')];
	}
	
	public function renderPriceListItems(Pricelist $priceList): void
	{
		$this->template->headerLabel = 'Ceny ceníku - ' . $priceList->name . ' (' . $priceList->currency->code . ')';
		$this->template->headerTree = [
			['Ceníky', 'default'],
			['Ceny'],
		];
		$this->template->displayButtons = [
			$this->createBackButton('default'),
			$this->createButtonWithClass('importPriceList', '<i class="fas fa-file-import"></i> Import', 'btn btn-outline-primary btn-sm', $priceList),
			$this->createButtonWithClass('priceListExport!', '<i class="fas fa-file-export"></i> Export', 'btn btn-outline-primary btn-sm', $priceList->getPK()),
			$this->createButtonWithClass('copyToPricelist','<i class="far fa-copy"></i> Kopírovat do ...', 'btn btn-outline-primary btn-sm', $priceList),
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
	
	public function renderPriceListItemsNew(Pricelist $priceList): void
	{
		$this->template->headerLabel = 'Nová cena ceníku - ' . $priceList->name . ' (' . $priceList->currency->code . ')';
		$this->template->headerTree = [
			['Ceníky', 'default'],
			['Ceny', 'priceListItems', $priceList],
			['Nová cena'],
		];
		$this->template->displayButtons = [$this->createBackButton('priceListItems', $this->getParameter('priceList'))];
		$this->template->displayControls = [$this->getComponent('priceListItemsNew')];
	}
	
	public function renderImportPriceList(Pricelist $priceList, string $type = 'standart'): void
	{
		$this->template->headerLabel = 'Importovat ceny';
		$this->template->headerTree = [
			['Ceníky', 'default'],
			['Ceny', 'priceListItems', $priceList],
			['Import'],
		];
		$this->template->displayButtons = [$this->createBackButton($type === 'standart' ? 'priceListItems' : 'quantityPrices', $priceList)];
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
	
	public function handlePriceListExport(string $pricelistId, string $type = 'standart')
	{
		$tempFilename = \tempnam($this->tempDir, "csv");
		
		$this->priceListRepository->csvExport($this->priceListRepository->one($pricelistId), Writer::createFromPath($tempFilename, 'w+'), $type === 'quantity');
		
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
			$this->createButtonWithClass('importPriceList', '<i class="fas fa-file-import"></i> Import', 'btn btn-outline-primary btn-sm', $pricelist, 'quantity'),
			$this->createButtonWithClass('priceListExport!', '<i class="fas fa-file-export"></i> Export', 'btn btn-outline-primary btn-sm', $pricelist->getPK()),
			$this->createButtonWithClass('copyToPricelist','<i class="far fa-copy"></i> Kopírovat do ...', 'btn btn-outline-primary btn-sm', $pricelist, 'quantity'),
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
			->addRule([FormValidators::class, 'isProductExists'], 'Produkt neexistuje nebo neplatná hodnota!', [$this->productRepository])
			->setRequired();
		
		$form->addText('price', 'Cena')->addRule($form::FLOAT)->setRequired();
		$form->addText('priceVat', 'Cena s daní')->addRule($form::FLOAT);
		$form->addText('validFrom', 'Od jakého množství')->addRule($form::INTEGER);
		$form->addHidden('pricelist', $this->getParameter('pricelist')->getPK());
		
		$form->addSubmits();
		
		$form->onSuccess[] = function (AdminForm $form) {
			$values = $form->getValues();
			
			$values['product'] = $this->productRepository->getProductByCodeOrEAN($values['product'])->getPK();
			
			$this->quantityPriceRepo->syncOne($values, null, true);
			
			$this->flashMessage('Uloženo', 'success');
			$form->processRedirect('this', 'quantityPrices', [$this->getParameter('pricelist')], [$this->getParameter('pricelist')]);
		};
		
		return $form;
	}
	
	public function createComponentCopyToPricelistForm()
	{
		$form = $this->formFactory->create();
		
		/** @var Pricelist $originalPricelist */
		$originalPricelist = $this->getParameter('priceList');
		$pricelists = $this->priceListRepository->many()->whereNot('uuid', $originalPricelist->getPK())->where('fk_currency', $originalPricelist->currency->getPK())->toArrayOf('name');
		
		$form->addDataSelect('originalPricelist', 'Cílový ceník', $pricelists)->setRequired();
		$form->addText('percent', 'Procentuální změna')->addRule($form::FLOAT)
			->setHtmlAttribute('data-info', 'Zadejte hodnotu v procentech (%).')
			->addRule([FormValidators::class, 'isPercentNoMax'], 'Zadaná hodnota není správná (>=0)!')
			->setRequired()->setDefaultValue(100);
		
		$form->addInteger('roundPrecision', 'Přesnost zaokrouhlení')
			->setDefaultValue(2)->setRequired()
			->setHtmlAttribute('data-info', 'Kladná čísla určují desetinnou část. Např.: přesnost 1 zaokrouhlí na 1 destinné místo. <br>Záporná čísla určují celou část. Např.: -2 zaokrouhlí na stovky.');
		$form->addCheckbox('beforePrices', 'Importovat původní ceny')
			->setHtmlAttribute('data-info', 'Původní cena bude zobrazena u produktu jako přeškrtnutá cena (cena před slevou)');
		$form->addCheckbox('overwrite', 'Přepsat existující ceny')
			->setHtmlAttribute('data-info', 'Existující ceny v cílovém ceníku budou přepsány');
		
		$form->addSubmits();
		
		$form->onSuccess[] = function (AdminForm $form) use ($originalPricelist) {
			$values = $form->getValues('array');
			
			
			/** @var Pricelist $targetPricelist */
			$targetPricelist = $this->priceListRepository->one($values['originalPricelist']);
			$quantity = $this->getParameter('type') === 'quantity';
			
			$this->priceListRepository->copyPrices($originalPricelist, $targetPricelist, (float) $values['percent'] / 100, $values['roundPrecision'], $values['overwrite'], $values['beforePrices'], $quantity);
			
			$this->flashMessage('Uloženo', 'success');
			$form->processRedirect('this', $quantity ? 'quantityPrices' : 'priceListItems', [$originalPricelist], [$originalPricelist]);
		};
		
		return $form;
	}
	
	public function renderCopyToPricelist(Pricelist $priceList, string $type = 'standart')
	{
		$this->template->headerLabel = 'Kopírovat ceny';
		$this->template->headerTree = [
			['Ceníky', 'default'],
			['Ceny', 'priceListItems', $priceList],
			['Kopírovat ceny'],
		];
		$this->template->displayButtons = [$this->createBackButton($type === 'standart' ? 'priceListItems' : 'quantityPrices', $priceList)];
		$this->template->displayControls = [$this->getComponent('copyToPricelistForm')];
	}
}
