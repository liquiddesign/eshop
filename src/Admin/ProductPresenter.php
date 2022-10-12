<?php

declare(strict_types=1);

namespace Eshop\Admin;

use Admin\Controls\AdminForm;
use Admin\Controls\AdminGrid;
use Eshop\Admin\Controls\IProductAttributesFormFactory;
use Eshop\Admin\Controls\IProductFormFactory;
use Eshop\Admin\Controls\ProductAttributesForm;
use Eshop\Admin\Controls\ProductAttributesGridFactory;
use Eshop\Admin\Controls\ProductGridFactory;
use Eshop\BackendPresenter;
use Eshop\DB\AmountRepository;
use Eshop\DB\AttributeAssignRepository;
use Eshop\DB\AttributeRepository;
use Eshop\DB\AttributeValue;
use Eshop\DB\AttributeValueRepository;
use Eshop\DB\CategoryTypeRepository;
use Eshop\DB\CustomerRepository;
use Eshop\DB\File;
use Eshop\DB\FileRepository;
use Eshop\DB\InternalCommentProductRepository;
use Eshop\DB\NewsletterTypeRepository;
use Eshop\DB\PhotoRepository;
use Eshop\DB\Pricelist;
use Eshop\DB\PricelistRepository;
use Eshop\DB\PriceRepository;
use Eshop\DB\ProducerRepository;
use Eshop\DB\Product;
use Eshop\DB\ProductRepository;
use Eshop\DB\ProductTabRepository;
use Eshop\DB\ProductTabTextRepository;
use Eshop\DB\RelatedTypeRepository;
use Eshop\DB\StoreRepository;
use Eshop\DB\SupplierProductRepository;
use Eshop\DB\SupplierRepository;
use Eshop\DB\VatRateRepository;
use Eshop\FormValidators;
use Eshop\Shopper;
use ForceUTF8\Encoding;
use Forms\Form;
use League\Csv\Reader;
use League\Csv\Writer;
use Nette\Application\Application;
use Nette\Application\Responses\FileResponse;
use Nette\Forms\Controls\TextInput;
use Nette\IOException;
use Nette\Utils\Arrays;
use Nette\Utils\FileSystem;
use Nette\Utils\Image;
use Nette\Utils\Random;
use Nette\Utils\Strings;
use Pages\DB\PageRepository;
use StORM\Connection;
use StORM\DIConnection;
use Tracy\Debugger;
use Tracy\ILogger;
use Web\DB\SettingRepository;

class ProductPresenter extends BackendPresenter
{
	protected const CONFIGURATION = [
		'relations' => true,
		'taxes' => true,
		'suppliers' => true,
		'weightAndDimension' => false,
		'externalCode' => false,
		'discountLevel' => true,
		'rounding' => true,
		'importButton' => false,
		'exportButton' => false,
		'exportColumns' => [
			'code' => 'Kód',
			'ean' => 'EAN',
			'mpn' => 'P/N',
			'name' => 'Název',
			'perex' => 'Popisek',
			'priority' => 'Priorita',
			'recommended' => 'Doporučeno',
			'hidden' => 'Skryto',
			'unavailable' => 'Neprodejné',
			'priceMin' => 'Minimální nákupní cena',
			'priceMax' => 'Maximální nákupní cena',
			'producer' => 'Výrobce',
			'content' => 'Obsah',
			'storeAmount' => 'Skladová dostupnost',
			'categories' => 'Kategorie',
			'adminUrl' => 'Admin URL',
			'frontUrl' => 'Front URL',
			'mergedProducts' => 'Sloučené produkty',
			'masterProduct' => 'Nadřazený sloučený produkt',
		],
		'exportAttributes' => [],
		'defaultExportColumns' => [
			'code',
			'name',
		],
		'defaultExportAttributes' => [],
		'importColumns' => [
			'code' => 'Kód',
			'ean' => 'EAN',
			'name' => 'Název',
			'perex' => 'Popisek',
			'priority' => 'Priorita',
			'recommended' => 'Doporučeno',
			'hidden' => 'Skryto',
			'unavailable' => 'Neprodejné',
			'producer' => 'Výrobce',
			'content' => 'Obsah',
			'storeAmount' => 'Skladová dostupnost',
			'categories' => 'Kategorie',
			'masterProduct' => 'Nadřazený sloučený produkt',
		],
		'importAttributes' => [],
		'importExampleFile' => null,
		'buyCount' => false,
		'attributeTab' => false,
		'loyaltyProgram' => false,
		'importImagesFromStorage' => [
			'server' => '',
			'login' => '',
			'password' => '',
		],
		'detailSuppliersTab' => false,
		'extendedName' => false,
		'productTabs' => true,
	];

	protected const IMPORT_SET_COLUMNS = [
		'setCode' => 'Kód setu',
		'setEan' => 'EAN setu',
		'productCode' => 'Kód produktu',
		'productEan' => 'EAN produktu',
		'amount' => 'Množství',
		'discountPct' => 'Sleva',
		'priority' => 'Priorita',
	];

	protected const DEFAULT_TEMPLATE = __DIR__ . '/../../_data/newsletterTemplates/newsletter.latte';

	/** @var array<callable(\Eshop\DB\Product, array): void> */
	public array $onProductFormSuccess = [];

	/** @inject */
	public ProductGridFactory $productGridFactory;

	/** @inject */
	public IProductFormFactory $productFormFatory;

	/** @inject */
	public IProductAttributesFormFactory $productAttributesFormFactory;

	/** @inject */
	public PhotoRepository $photoRepository;

	/** @inject */
	public FileRepository $fileRepository;

	/** @inject */
	public PricelistRepository $pricelistRepository;

	/** @inject */
	public PriceRepository $priceRepository;

	/** @inject */
	public ProductTabRepository $productTabRepository;

	/** @inject */
	public ProductTabTextRepository $productTabTextRepository;

	/** @inject */
	public VatRateRepository $vatRateRepository;

	/** @inject */
	public PageRepository $pageRepository;

	/** @inject */
	public SupplierProductRepository $supplierProductRepository;

	/** @inject */
	public ProductRepository $productRepository;

	/** @inject */
	public NewsletterTypeRepository $newsletterTypeRepository;

	/** @inject */
	public Shopper $shopper;

	/** @inject */
	public SettingRepository $settingRepository;

	/** @inject */
	public AttributeRepository $attributeRepository;

	/** @inject */
	public SupplierRepository $supplierRepository;

	/** @inject */
	public CustomerRepository $customerRepository;

	/** @inject */
	public ProducerRepository $producerRepository;

	/** @inject */
	public AttributeValueRepository $attributeValueRepository;

	/** @inject */
	public AttributeAssignRepository $attributeAssignRepository;

	/** @inject */
	public InternalCommentProductRepository $commentRepository;

	/** @inject */
	public ProductAttributesGridFactory $productAttributesGridFactory;

	/** @inject */
	public CategoryTypeRepository $categoryTypeRepository;

	/** @inject */
	public StoreRepository $storeRepository;

	/** @inject */
	public AmountRepository $amountRepository;

	/** @inject */
	public RelatedTypeRepository $relatedTypeRepository;

	/** @inject */
	public Application $application;

	/** @persistent */
	public string $tab = 'products';

	/** @persistent */
	public string $editTab = 'menu0';

	/**
	 * @var string[]
	 */
	private array $tabs = [
		'products' => 'Katalog',
		'attributes' => 'Atributy',
	];

	public function createComponentProductGrid(): \Grid\Datagrid
	{
		$config = $this::CONFIGURATION;
		$config['isManager'] = $this->isManager;

		return $this->productGridFactory->create($config);
	}

	public function createComponentProductAttributesGrid(): \Grid\Datagrid
	{
		return $this->productAttributesGridFactory->create($this::CONFIGURATION);
	}

	public function createComponentProductForm(): Controls\ProductForm
	{
		return $this->productFormFatory->create($this->getParameter('product'), $this::CONFIGURATION);
	}

	public function createComponentFileGrid(): AdminGrid
	{
		$grid = $this->gridFactory->create($this->fileRepository->many()->where('fk_product', $this->getParameter('product')), 20, 'priority', 'ASC', true);
		$grid->addColumnSelector();
		$grid->addColumnText('Popisek', 'label_cs', '%s', 'label_cs');

		$grid->addColumnInputInteger('Priorita', 'priority', '', '', 'priority', [], true);
		$grid->addColumnInputCheckbox('<i title="Skryto" class="far fa-eye-slash"></i>', 'hidden', '', '', 'hidden');

		$grid->addColumnLinkDetail('detailFile');

		$grid->addColumnActionDelete([$this, 'deleteFile']);

		$grid->addButtonSaveAll();

		$grid->addFilterTextInput('search', ['fileName'], null, 'Jméno souboru');
		$grid->addFilterButtons(['this', $this->getParameter('product')]);

		return $grid;
	}

	public function createComponentPriceGrid(): AdminGrid
	{
		$product = $this->getParameter('product');

		$collection = $this->pricelistRepository->many()
			->select([
				'price' => 'prices.price',
				'priceVat' => 'prices.priceVat',
				'priceBefore' => 'prices.priceBefore',
				'priceVatBefore' => 'prices.priceVatBefore',
				'rate' => 'rates.rate',
			])
			->join(['prices' => 'eshop_price'], 'prices.fk_pricelist=this.uuid AND prices.fk_product=:product', ['product' => $product])
			->join(['rates' => 'eshop_vatrate'], 'rates.uuid = :rate AND rates.fk_country=this.fk_country', ['rate' => $product->vatRate]);

		$grid = $this->gridFactory->create($collection, 20, 'priority', 'ASC');

		$grid->addColumnText('Kód', 'code', '%s', 'code');
		$grid->addColumnText('Ceník', 'name', '%s', 'name');
		$grid->addColumnText('Měna', 'currency.code', '%s', 'currency.code');
		$grid->addColumnInputPrice('Cena', 'price');

		if ($this->shopper->getShowVat()) {
			$grid->addColumnInputPrice('Cena s DPH', 'priceVat');
		}

		$grid->addColumnInputPrice('Cena před slevou', 'priceBefore');

		if ($this->shopper->getShowVat()) {
			$grid->addColumnInputPrice('Cena před slevou s DPH', 'priceVatBefore');
		}

		$grid->addColumnActionDelete([$this, 'deletePrice'], true);

		$submit = $grid->getForm()->addSubmit('submit', 'Uložit');
		$submit->setHtmlAttribute('class', 'btn btn-sm btn-primary');
		$submit->onClick[] = function ($button) use ($grid, $product): void {
			foreach ($grid->getInputData() as $id => $data) {
				if (!isset($data['price'])) {
					continue;
				}

				$newData = [
					'price' => \floatval(\str_replace(',', '.', $data['price'])),
					'priceBefore' => isset($data['priceBefore']) ? \floatval(\str_replace(',', '.', $data['priceBefore'])) : null,
					'product' => $product,
					'pricelist' => $id,
				];

				if ($this->shopper->getShowVat()) {
					$newData += [
						'priceVat' => isset($data['priceVat']) ? \floatval(\str_replace(',', '.', $data['priceVat'])) : $data['price'] +
							($data['price'] * \fdiv(\floatval($this->vatRateRepository->getDefaultVatRates()[$product->vatRate]), 100)),
						'priceVatBefore' => isset($data['priceVatBefore']) ? \floatval(\str_replace(',', '.', $data['priceVatBefore'])) : null,
					];
				}

				$this->priceRepository->syncOne($newData);
			}

			$grid->getPresenter()->flashMessage('Uloženo', 'success');
			$grid->getPresenter()->redirect('this');
		};

		$grid->addFilterTextInput('search', ['code'], null, 'Kód ceníku');
		$grid->addFilterButtons(['prices', $product]);

		return $grid;
	}

	public function deletePrice(Pricelist $pricelist): void
	{
		$this->priceRepository->getPricesByPriceList($pricelist)->where('fk_product', $this->getParameter('product'))->delete();
	}

	public function createComponentFileForm(): Form
	{
		$form = $this->formFactory->create(true);

		if (!$this->getParameter('file')) {
			$form->addFilePicker('fileName', 'Vybrat soubor', \DIRECTORY_SEPARATOR . Product::FILE_DIR)->setRequired();
		}

		$form->addLocaleText('label', 'Popisek')->forPrimary(function ($input): void {
			$input->setRequired();
		});
		$form->addInteger('priority', 'Priorita')->setDefaultValue(10);
		$form->addCheckbox('hidden', 'Skryto');

		$form->addHidden('product', (string)$this->getParameter('product'));

		$form->addSubmit('submit', 'Uložit');

		$form->onValidate[] = function (Form $form): void {
			if (!$form->isValid()) {
				return;
			}

			$values = $form->getValues('array');

			if (isset($values['fileName']) && $values['fileName']->isOK()) {
				return;
			}

			$form->addError('Je nutné přiložit soubor!');
		};

		$form->onSuccess[] = function (Form $form): void {
			$values = $form->getValues('array');

			$this->createDirectories();

			if (!$values['uuid']) {
				$values['uuid'] = DIConnection::generateUuid();
			}

			if (isset($values['fileName'])) {
				/** @var \Forms\Controls\UploadFile $upload */
				$upload = $form['fileName'];

				$values['fileName'] = $upload->upload($values['uuid'] . '.%2$s');
			}

			$this->fileRepository->syncOne($values);

			$this->flashMessage('Uloženo', 'success');
			$this->redirect('edit', [new Product(['uuid' => $values['product']])]);
		};

		return $form;
	}

	public function createComponentAttributesForm(): ProductAttributesForm
	{
		return $this->productAttributesFormFactory->create($this->getParameter('product'), true);
	}

	public function actionDetailFile(File $file): void
	{
		/** @var \Forms\Form $form */
		$form = $this->getComponent('fileForm');
		$form->setDefaults($file->toArray());
	}

	public function renderDetailFile(File $file): void
	{
		$this->template->headerLabel = 'Nová položka';
		$this->template->headerTree = [
			['Produkty', 'default'],
			['Soubory', 'edit', $file->product],
			['Detail'],
		];
		$this->template->displayButtons = [$this->createBackButton('edit', $file->product)];
		$this->template->displayControls = [$this->getComponent('fileForm')];
	}

	public function renderNewFile(Product $product): void
	{
		$this->template->headerLabel = 'Nová položka';
		$this->template->headerTree = [
			['Produkty', 'default'],
			['Soubory', 'edit', $product],
			['Nový soubor'],
		];
		$this->template->displayButtons = [$this->createBackButton('edit', $product)];
		$this->template->displayControls = [$this->getComponent('fileForm')];
	}

	public function renderNew(): void
	{
		$this->template->editTab = $this->editTab;
		$this->template->relatedTypes = $this->relatedTypeRepository->getArrayForSelect();

		$this->template->headerLabel = 'Detail';
		$this->template->headerTree = [
			['Produkty', 'default'],
			['Detail'],
		];
		$this->template->displayButtons = [$this->createBackButton('default')];
		$this->template->displayControls = [
			'productForm' => $this->getComponent('productForm'),
		];

		$this->template->comments = [];
		$this->template->photos = [];

		$this->template->setFile(__DIR__ . '/templates/product.edit.latte');
	}

	public function actionEdit(Product $product): void
	{
		/** @var \Admin\Controls\AdminForm $form */
		$form = $this->getComponent('productForm')['form'];

		$prices = $this->pricelistRepository->many()->orderBy(['this.priority'])
			->join(['prices' => 'eshop_price'], 'prices.fk_product=:product AND prices.fk_pricelist=this.uuid', ['product' => $product])
			->select([
				'price' => 'prices.price',
				'priceVat' => 'prices.priceVat',
				'priceBefore' => 'prices.priceBefore',
				'priceVatBefore' => 'prices.priceVatBefore',
			])->toArray();

		/** @var \Forms\Container $input */
		$input = $form['prices'];

		/**
		 * @var string $pricelistId
		 * @var \Forms\Container $container
		 */
		foreach ($input->getComponents() as $pricelistId => $container) {
			$container->setDefaults($prices[$pricelistId]->toArray());
		}

		/** @var \Eshop\DB\ProductTab $productTab */
		foreach ($this->productTabRepository->many() as $productTab) {
			$values = [];
			$productTabText = $this->productTabTextRepository->many()
				->where('fk_product=:product AND fk_tab=:tab', ['product' => $product->getPK(), 'tab' => $productTab->getPK()])
				->first();

			/** @var \Forms\Container $input */
			$input = $form['productTab' . $productTab->getPK()];

			/**
			 * @var string $key
			 * @var \Forms\Container $container
			 */
			foreach ($input->getComponents() as $key => $container) {
				$property = 'content_' . $key;
				$values[$key] = $productTabText->$property;
			}

			$input->setDefaults($values);
		}

		$amounts = $this->amountRepository->many()
			->where('fk_product', $product->getPK())
			->select(['storeId' => 'fk_store'])
			->setIndex('storeId')
			->toArray();

		/** @var \Forms\Container $input */
		$input = $form['stores'];

		/**
		 * @var string $storeId
		 * @var \Forms\Container $container
		 */
		foreach ($input->getComponents() as $storeId => $container) {
			$container->setDefaults(isset($amounts[$storeId]) ? $amounts[$storeId]->toArray() : []);
		}

		/** @var \Eshop\DB\CategoryType[] $categoryTypes */
		$categoryTypes = $this->categoryTypeRepository->getCollection(true)->toArray();

		$productData = $product->toArray(['ribbons', 'internalRibbons', 'parameterGroups', 'taxes', 'categories']);

		foreach ($categoryTypes as $categoryType) {
			$form['categories'][$categoryType->getPK()]
				->checkDefaultValue(false)
				->setDefaultValue($productData['categories']);
		}

		$form->setDefaults($productData);

		/** @var \Nette\Forms\Controls\SelectBox|null $input */
		$input = $form['supplierContent'] ?? null;

		if (isset($input)) {
			if ($product->supplierContentLock) {
				$input->setDefaultValue(Product::SUPPLIER_CONTENT_MODE_NONE);
			} elseif ($product->supplierContentMode === Product::SUPPLIER_CONTENT_MODE_LENGTH) {
				$input->setDefaultValue(Product::SUPPLIER_CONTENT_MODE_LENGTH);
			} elseif ($product->supplierContentMode === Product::SUPPLIER_CONTENT_MODE_PRIORITY) {
				$input->setDefaultValue(null);
			}
		}

		if (!$form->getPrettyPages()) {
			return;
		}

		/** @var \Web\DB\Page|null $page */
		$page = $this->pageRepository->getPageByTypeAndParams('product_detail', null, ['product' => $product]);

		if (!$page) {
			return;
		}

		/** @var \Forms\Container $pageContainer */
		$pageContainer = $form['page'];

		$pageContainer->setDefaults($page->toArray());

		$form['page']['url']->forAll(function (TextInput $text, $mutation) use ($page, $form): void {
			$text->getRules()->reset();
			$text->addRule([$form, 'validateUrl'], 'URL již existuje', [$this->pageRepository, $mutation, $page->getPK()]);
		});
	}

	public function renderEdit(Product $product): void
	{
		$this->template->product = $product;
		$this->template->headerLabel = 'Detail - ' . $product->name;
		$this->template->headerTree = [
			['Produkty', 'default'],
			['Detail'],
		];
		$this->template->displayButtons = [$this->createBackButton('default')];
		$this->template->displayControls = [
			'productForm' => $this->getComponent('productForm'),
		];

		if ($this->getParameter('product')) {
			$this->template->displayControls['attributesForm'] = $this->getComponent('attributesForm');
		}

		$this->template->editTab = $this->editTab;
		$this->template->comments = $this->commentRepository->many()->where('fk_product', $product->getPK())->orderBy(['createdTs' => 'DESC'])->toArray();
		$this->template->relatedTypes = $this->relatedTypeRepository->getArrayForSelect();

		$data = [];
		/** @var \Eshop\DB\Photo[] $photos */
		$photos = $this->photoRepository->many()->where('fk_product', $product->getPK())->orderBy(['priority']);

		$basePath = $this->container->parameters['wwwDir'] . '/userfiles/' . Product::GALLERY_DIR . '/origin/';

		foreach ($photos as $photo) {
			$row = [];
			$row['name'] = $photo->fileName;
			$row['size'] = \file_exists($basePath . $photo->fileName) ? \filesize($basePath . $photo->fileName) : 0;
			$row['main'] = $product->imageFileName === $photo->fileName;
			$row['googleFeed'] = $photo->googleFeed;

			$data[$photo->fileName] = $row;
		}

		$this->template->photos = $data;
		$this->template->configuration = $this::CONFIGURATION;

		$this->template->setFile(__DIR__ . '/templates/product.edit.latte');
	}

	public function renderPrices(Product $product): void
	{
		$this->template->headerLabel = 'Ceny - ' . $product->name;
		$this->template->headerTree = [
			['Produkty', 'default'],
			['Ceny'],
		];
		$this->template->displayButtons = [$this->createBackButton('default')];
		$this->template->displayControls = [$this->getComponent('priceGrid')];
	}

	public function renderFiles(Product $product): void
	{
		$this->template->headerLabel = 'Soubory - ' . $product->name;
		$this->template->headerTree = [
			['Produkty', 'default'],
			['Soubory'],
		];
		$this->template->displayButtons = [$this->createBackButton('default'), $this->createNewItemButton('newFile', [$product])];
		$this->template->displayControls = [$this->getComponent('fileGrid')];
	}

	public function renderDefault(): void
	{
		if (isset($this::CONFIGURATION['attributeTab']) && $this::CONFIGURATION['attributeTab']) {
			$this->template->tabs = $this->tabs;
		}

		if ($this->tab === 'attributes' && isset($this::CONFIGURATION['attributeTab']) && $this::CONFIGURATION['attributeTab']) {
			$this->template->headerLabel = 'Produkty';
			$this->template->headerTree = [
				['Produkty', 'default'],
				['Atributy'],
			];
			$this->template->displayButtons = [];
			$this->template->displayControls = [$this->getComponent('productAttributesGrid')];
			$this->template->setFile(__DIR__ . '/templates/productAttributesGrid.latte');
		} else {
			$this->template->headerLabel = 'Produkty';
			$this->template->headerTree = [
				['Produkty', 'default'],
				['Katalog'],
			];
			$this->template->displayButtons = [$this->createNewItemButton('new', ['editTab' => null])];

			if (isset($this::CONFIGURATION['importButton']) && $this::CONFIGURATION['importButton']) {
				$this->template->displayButtons[] = $this->createButton('importCsv', '<i class="fas fa-file-upload mr-1"></i>Import');
			}

			$this->template->displayControls = [$this->getComponent('productGrid')];
		}
	}

	public function deleteFile(File $file): void
	{
		$dir = File::FILE_DIR;
		$rootDir = $this->wwwDir . \DIRECTORY_SEPARATOR . 'userfiles' . \DIRECTORY_SEPARATOR . $dir;

		if (!$file->fileName) {
			return;
		}

		try {
			FileSystem::delete($rootDir . \DIRECTORY_SEPARATOR . $file->fileName);
		} catch (\Throwable $e) {
			Debugger::log($e, ILogger::WARNING);
		}
	}

	public function actionNewsletterExportSelect(array $ids): void
	{
		/** @var \Forms\Form $form */
		$form = $this->getComponent('newsletterExportProducts');

		$products = '';

		foreach ($ids as $id) {
			$products .= $this->productRepository->one($id)->getFullCode() . ';';
		}

		if (\strlen($products) > 0) {
			$products = \substr($products, 0, -1);
		}

		$form->setDefaults(['products' => $products]);
	}

	public function renderNewsletterExportSelect(array $ids): void
	{
		unset($ids);

		$this->template->headerLabel = 'Export pro newsletter';
		$this->template->headerTree = [
			['Produkty', 'default'],
			['Export pro newsletter'],
		];
		$this->template->displayButtons = [$this->createBackButton('default')];
		$this->template->displayControls = [$this->getComponent('newsletterExportProducts')];
	}

	public function createComponentNewsletterExportForm(): AdminForm
	{
		/** @var \Grid\Datagrid $productGrid */
		$productGrid = $this->getComponent('productGrid');

		$totalNo = $productGrid->getFilteredSource()->enum();

		$form = $this->formFactory->create();
		$form->setAction($this->link('this', ['selected' => $this->getParameter('selected')]));
		$form->addRadioList('bulkType', 'Upravit', [
			'selected' => 'vybrané',
			'all' => "celý výsledek ($totalNo)",
		])->setDefaultValue('selected');

		$form->addText('products', 'Produkty')
			->setHtmlAttribute('data-info', 'EAN nebo kódy oddělené středníky.')
			->setNullable()
			->addCondition($form::FILLED)
			->addRule([FormValidators::class, 'isMultipleProductsExists'], 'Chybný formát nebo nebyl nalezen některý ze zadaných produktů!', [$this->productRepository]);
		$form->addSelect('type', 'Typ šablony', $this->newsletterTypeRepository->getArrayForSelect())->setRequired();
		$form->addLocalePerexEdit('text', 'Textový obsah');

		$form->addSubmit('submit', 'Stáhnout');

		$form->onSuccess[] = function (AdminForm $form) use ($productGrid): void {
			$values = $form->getValues('array');

			$functionName = 'newsletterExport' . (\ucfirst($values['type']));

//          try {
			if ($values['bulkType'] === 'selected') {
				if (!$values['products']) {
					return;
				}

				$products = [];

				foreach (\explode(';', $values['products']) as $product) {
					$products[] = $this->productRepository->getProductByCodeOrEAN($product)->getPK();
				}

				$this->$functionName($products, $values['text']);
			} else {
				$this->$functionName(\array_keys($productGrid->getFilteredSource()->toArrayOf('uuid')), $values['text']);
			}

//          } catch (\Exception $e) {
//              bdump($e);
//          }
		};

		return $form;
	}

	public function createComponentNewsletterExportProducts(): AdminForm
	{
		/** @var \Grid\Datagrid $productGrid */
		$productGrid = $this->getComponent('productGrid');

		$ids = $this->getParameter('ids') ?: [];
		$totalNo = $productGrid->getFilteredSource()->enum();

		$form = $this->formFactory->create();
		$form->setAction($this->link('this', ['selected' => $this->getParameter('selected')]));
		$form->addRadioList('bulkType', 'Upravit', [
			'selected' => 'vybrané',
			'all' => "celý výsledek ($totalNo)",
		])->setDefaultValue('selected');

		$form->addPerexEdit('text', 'Textový obsah')
			->setHtmlAttribute(
				'data-info',
				'Můžete využít i proměnné systému MailerLite. Např.: "{$email}".&nbsp;
Více informací <a href="http://help.mailerlite.com/article/show/29194-what-custom-variables-can-i-use-in-my-campaigns" target="_blank">zde</a>.',
			);
		$form->addText('products', 'Produkty')
			->setHtmlAttribute('data-info', 'EAN nebo kódy oddělené středníky.')
			->setNullable()
			->addCondition($form::FILLED)
			->addRule([FormValidators::class, 'isMultipleProductsExists'], 'Chybný formát nebo nebyl nalezen některý ze zadaných produktů!', [$this->productRepository]);
		$form->addSelect('mutation', 'Jazyk', \array_combine($this->formFactory->getMutations(), $this->formFactory->getMutations()))->setRequired();
		$form->addDataMultiSelect('pricelists', 'Ceníky', $this->pricelistRepository->getArrayForSelect())->setRequired();

		$form->addSubmit('submit', 'Stáhnout');

		$form->onSuccess[] = function (AdminForm $form) use ($ids): void {
			$values = $form->getValues('array');

			$products = $this->productRepository->getProducts($this->pricelistRepository->many()->where('this.uuid', $values['pricelists'])->toArray())->where('this.uuid', $ids)->toArray();

			$this->productRepository->getConnection()->setMutation($values['mutation']);

			/** @var \Translator\DB\TranslationRepository $translator */
			$translator = $this->translator;
			$translator->setMutation($values['mutation']);

			/** @var \Nette\Bridges\ApplicationLatte\Template $template */
			$template = $this->getTemplate();
			$template->setTranslator($this->translator);

			$html = $template->renderToString($this::DEFAULT_TEMPLATE, [
				'type' => 'products',
				'text' => $values['text'],
				'args' => [
					'products' => $products,
					'lang' => $values['mutation'],
				],
			]);

			$tempFilename = \tempnam($this->tempDir, 'html');
			$this->application->onShutdown[] = function () use ($tempFilename): void {
				if (\is_file($tempFilename)) {
					try {
						FileSystem::delete($tempFilename);
					} catch (\Throwable $e) {
						Debugger::log($e, ILogger::WARNING);
					}
				}
			};

			$zip = new \ZipArchive();

			$zipFilename = \tempnam($this->tempDir, 'zip');

			if ($zip->open($zipFilename, \ZipArchive::CREATE) !== true) {
				exit("cannot open <$zipFilename>\n");
			}

			FileSystem::write($tempFilename, $html);

			$zip->addFile($tempFilename, 'newsletter.html');

			$zip->close();

			$this->application->onShutdown[] = function () use ($zipFilename): void {
				try {
					FileSystem::delete($zipFilename);
				} catch (\Throwable $e) {
					Debugger::log($e, ILogger::WARNING);
				}
			};

			$this->sendResponse(new FileResponse($zipFilename, 'newsletter.zip', 'application/zip'));
		};

		return $form;
	}

	public function actionMergeSelect(array $ids): void
	{
		unset($ids);
	}

	public function renderMergeSelect(array $ids): void
	{
		unset($ids);

		$this->template->headerLabel = 'Sloučení produktů';
		$this->template->headerTree = [
			['Produkty', 'default'],
			['Sloučení produktů'],
		];
		$this->template->displayButtons = [$this->createBackButton('default')];
		$this->template->displayControls = [$this->getComponent('mergeForm')];
	}

	public function createComponentMergeForm(): AdminForm
	{
		$ids = $this->getParameter('ids') ?: [];

		$form = $this->formFactory->create();
		$form->setAction($this->link('this', ['selected' => $this->getParameter('selected')]));

		$mutationSuffix = $this->productRepository->getConnection()->getMutationSuffix();

		$form->addSelect2(
			'mainProduct',
			'Hlavní produkt',
			$this->productRepository->many()
				->where('this.uuid', $ids)
				->select(['customName' => "CONCAT(this.name$mutationSuffix, ' (', this.code, ')')"])
			->toArrayOf('customName'),
		)->setRequired()->setHtmlAttribute('data-info', '<br>
Vysvětlení: Všechny vybrané produkty budou sloučené pod zvolený hlavní produkt.<br>
Produkt může být sloučený pod <b>maximálně jeden</b> produkt. Ten ale může být sloučený pod další a tím vznikne strom.<br>
Sloučení neovliňuje produkty ani importy, nic se nemaže. Můžete zvolit jestli se ostatní produkty skryjí či budou neprodejné.');

		$trueFalseOptions = [
			'0' => 'Ne',
			'1' => 'Ano',
		];

		$form->addGroup('Sloučené produkty');
		$form->addSelect('hidden', 'Skryté', $trueFalseOptions)->setPrompt('Původní');
		$form->addSelect('hiddenInMenu', 'Skryté v menu a vyhledávání', $trueFalseOptions)->setPrompt('Původní')->setDefaultValue(true);
		$form->addSelect('unavailable', 'Neprodejné', $trueFalseOptions)->setPrompt('Původní');

		$form->addSubmit('submit', 'Uložit');

		$form->onValidate[] = function (AdminForm $form) use ($ids): void {
			if (!$form->isValid()) {
				return;
			}

			$values = $form->getValues('array');

			$existingMasterMergedProducts = $this->productRepository->many()
				->where('this.fk_masterProduct IS NOT NULL')
				->where('this.uuid', $ids)
				->whereNot('this.uuid', $values['mainProduct'])
				->toArrayOf('code');

			if ($existingMasterMergedProducts) {
				$form->addError('Nelze sloučit: Následující produkty již jsou spojeny s jiným produktem a nelze je spojit. ' . \implode(', ', $existingMasterMergedProducts), false);
			}

			$existingMergedProductsForAllProducts = [];
			$circularReference = [];

			foreach ($this->productRepository->many()->where('this.uuid', $ids) as $product) {
				/** @var array<string, \Eshop\DB\Product> $localProducts */
				$localProducts = [$product->getPK() => $product];
				$localProducts = \array_merge($localProducts, $product->getAllMergedProducts());

				/**
				 * @var string $localProductPK
				 * @var \Eshop\DB\Product $localProduct
				 */
				foreach ($localProducts as $localProductPK => $localProduct) {
					if (isset($existingMergedProductsForAllProducts[$localProductPK])) {
						$circularReference[] = $localProduct->code;
					} else {
						$existingMergedProductsForAllProducts[$localProductPK] = $localProduct;
					}
				}
			}

			if (!$circularReference) {
				return;
			}

			$form->addError(
				'Nelze sloučit: Následující produkty mají cyklickou závislost na některý z vybraných produktů. Zkontrolujte Vaši strukturu na detailu produktů. ' . \implode(', ', $circularReference),
				false,
			);
		};

		$form->onSuccess[] = function (AdminForm $form) use ($ids): void {
			$values = $form->getValues('array');

			$updateValues = [
				'fk_masterProduct' => $values['mainProduct'],
			];

			foreach (['hidden', 'unavailable', 'hiddenInMenu'] as $key) {
				if ($values[$key] !== null) {
					$updateValues[$key] = $values[$key];
				}
			}

			$this->productRepository->many()
				->where('this.uuid', $ids)
				->whereNot('this.uuid', $values['mainProduct'])
				->update($updateValues);

			$this->flashMessage('Provedeno', 'success');
			$this->redirect('default');
		};

		return $form;
	}

	public function actionImportCsv(): void
	{
		Debugger::$showBar = false;
	}

	public function renderImportCsv(): void
	{
		$this->template->headerLabel = 'Import';
		$this->template->headerTree = [
			['Produkty', 'default'],
			['Import'],
		];
		$this->template->displayButtons = [$this->createBackButton('default')];
		$this->template->displayControls = [$this->getComponent('importCsvForm')];

		if (!isset($this::CONFIGURATION['importImagesFromStorage']['server']) || !$this::CONFIGURATION['importImagesFromStorage']['server']) {
			return;
		}

		$this->template->displayControls[] = $this->getComponent('importImagesForm');
	}

	public function createComponentImportImagesForm(): AdminForm
	{
		$form = $this->formFactory->create(false, false, false, false, false);

		$form->addGroup('Obrázky z úložiště');
		$form->addText('server', 'FTP server')->setDisabled()->setDefaultValue($this::CONFIGURATION['importImagesFromStorage']['server']);
		$form->addText('username', 'Uživatelské jméno')->setDisabled()->setDefaultValue($this::CONFIGURATION['importImagesFromStorage']['login']);
		$form->addText('password', 'Heslo')->setDisabled()->setDefaultValue($this::CONFIGURATION['importImagesFromStorage']['password']);
		$form->addCheckbox('asMain', 'Nastavit jako hlavní obrázek')->setHtmlAttribute('data-info', 'Pro práci s FTP doporučejeme klient WinSCP dostupný zde: 
<a target="_blank" href="https://winscp.net/eng/download.php">https://winscp.net/eng/download.php</a><br>
Výše zobrazené údaje stačí v klientovi vyplnit a nahrát obrázky. Název obrázků musí být kód daného produktu.');

		$form->addSubmit('images', 'Importovat');

		$form->onSuccess[] = function (AdminForm $form): void {
			$values = $form->getValues('array');

			$connection = $this->productRepository->getConnection();

			$imagesPath = \dirname(__DIR__, 5) . '/userfiles/images';
			$originalPath = \dirname(__DIR__, 5) . '/userfiles/' . Product::GALLERY_DIR . '/origin';
			$thumbPath = \dirname(__DIR__, 5) . '/userfiles/' . Product::GALLERY_DIR . '/thumb';
			$detailPath = \dirname(__DIR__, 5) . '/userfiles/' . Product::GALLERY_DIR . '/detail';

			FileSystem::createDir($imagesPath);
			FileSystem::createDir($originalPath);
			FileSystem::createDir($thumbPath);
			FileSystem::createDir($detailPath);

			$images = \scandir($imagesPath);
			$existingImages = \scandir($thumbPath);

			$products = $this->productRepository->many()->setIndex('code')->toArrayOf('uuid');

			$filtered = \array_filter($images, function ($value) use ($products) {
				$code = \explode('.', $value);

				return Arrays::get($products, $code[0], null);
			});

			if (\count($filtered) === 0) {
				$this->flashMessage('Nenalezen žádný odpovídající obrázek!', 'warning');
				$this->redirect('this');
			}

			$connection->getLink()->beginTransaction();

			$newPhotos = [];
			$newProductsMainImages = [];

			try {
				foreach ($filtered as $fileName) {
					$code = \explode('.', $fileName)[0];
					$imageFileName = \trim($fileName);

					if (!isset($products[$code])) {
						continue;
					}

					$newPhotos[] = [
						'product' => $products[$code],
						'fileName' => $imageFileName,
						'priority' => 999,
					];

					if ($values['asMain']) {
						$newProductsMainImages[] = ['uuid' => $products[$code], 'imageFileName' => $imageFileName];
					}

					$currentExistingImages = \array_filter($existingImages, function ($value) use ($code) {
						return \str_contains($value, $code);
					});

					if (\count($currentExistingImages) > 0) {
						$existingImagePath = \trim(Arrays::first($currentExistingImages));
						$existingTimestamp = \filemtime($thumbPath . '/' . $existingImagePath);
						$imagesTimestamp = \filemtime($imagesPath . '/' . $imageFileName);

						if ($existingTimestamp >= $imagesTimestamp) {
							continue;
						}

						try {
							FileSystem::delete($originalPath . '/' . $existingImagePath);
						} catch (\Throwable $e) {
							Debugger::log($e, ILogger::WARNING);
						}

						try {
							FileSystem::delete($detailPath . '/' . $existingImagePath);
						} catch (\Throwable $e) {
							Debugger::log($e, ILogger::WARNING);
						}

						try {
							FileSystem::delete($thumbPath . '/' . $existingImagePath);
						} catch (\Throwable $e) {
							Debugger::log($e, ILogger::WARNING);
						}
					}

					$imageD = Image::fromFile($imagesPath . '/' . $imageFileName);
					$imageT = Image::fromFile($imagesPath . '/' . $imageFileName);
					$imageD->resize(600, null);
					$imageT->resize(300, null);

					FileSystem::copy($imagesPath . '/' . $imageFileName, $originalPath . '/' . $imageFileName);

					try {
						$imageD->save($detailPath . '/' . $imageFileName);
						$imageT->save($thumbPath . '/' . $imageFileName);
					} catch (\Exception $e) {
					}
				}

				$this->photoRepository->createMany($newPhotos);

				if (\count($newProductsMainImages) > 0) {
					$this->productRepository->syncMany($newProductsMainImages);
				}

				$this->flashMessage('Provedeno', 'success');

				$connection->getLink()->commit();
			} catch (\Throwable $e) {
				$this->flashMessage('Při zpracovávání došlo k chybě!', 'error');

				$connection->getLink()->rollBack();
			}

			$this->redirect('this');
		};

		return $form;
	}

	public function createComponentImportCsvForm(): AdminForm
	{
		$form = $this->formFactory->create();

		$lastUpdate = null;
		$path = \dirname(__DIR__, 5) . '/userfiles/products.csv';

		if (\file_exists($path)) {
			$lastUpdate = \filemtime($path);
		}

		$form->addGroup('CSV soubor');
		$form->addText('lastProductFileUpload', 'Poslední aktualizace souboru')->setDisabled()->setDefaultValue($lastUpdate ? \date('d.m.Y G:i', $lastUpdate) : null);

		$allowedColumns = '';

		foreach ($this::CONFIGURATION['importColumns'] as $key => $value) {
			$allowedColumns .= "$key, $value<br>";
		}

		$filePicker = $form->addFilePicker('file', 'Soubor (CSV)')
			->setRequired()
			->addRule($form::MIME_TYPE, 'Neplatný soubor!', 'text/csv');

		if (isset($this::CONFIGURATION['importExampleFile']) && $this::CONFIGURATION['importExampleFile']) {
			$filePicker->setHtmlAttribute('data-info', 'Vzorový soubor: <a href="' . $this->link('downloadImportExampleFile!') . '">' . $this::CONFIGURATION['importExampleFile'] . '</a><br
>Podporuje <b>pouze</b> formátování Windows a Linux (UTF-8)!');
		}

		$form->addSelect('delimiter', 'Oddělovač', [
			';' => 'Středník (;)',
			',' => 'Čárka (,)',
			'   ' => 'Tab (\t)',
			' ' => 'Mezera ( )',
			'|' => 'Pipe (|)',
		]);

		$form->addSelect('searchCriteria', 'Hledat dle', ['all' => 'Kód a EAN', 'code' => 'Kód', 'ean' => 'EAN',])->setRequired()
			->setHtmlAttribute('data-info', 'Pole, dle kterých se hledá, se přepisují jen v případě nové položky.');
		$form->addCheckbox('addNew', 'Vytvářet nové záznamy');
		$form->addCheckbox('overwriteExisting', 'Přepisovat existující záznamy')->setDefaultValue(true);
		$form->addCheckbox('updateAttributes', 'Aktualizovat atributy');
		$form->addCheckbox('createAttributeValues', 'Vytvářet hodnoty atributů (pokud neexistují, hledá dle jména)')->setHtmlAttribute('data-info', '<h5 class="mt-2">Nápověda</h5>
Soubor <b>musí obsahovat</b> hlavičku a jeden ze sloupců "Kód" nebo "EAN" pro jednoznačné rozlišení produktů.&nbsp;
Jako prioritní se hledá kód a pokud není nalezen tak EAN. Kód se ukládá jen při vytváření nových záznamů.<br><br>
Povolené sloupce hlavičky (lze použít obě varianty kombinovaně):<br>
' . $allowedColumns . '<br>
Atributy a výrobce musí být zadány jako kód (např.: "001") nebo jako kombinace názvu a kódu(např.: "Tisková technologie#001).<br>
Hodnoty atributů, kategorie a skladové množství se zadávají ve stejném formátu jako atributy s tím že jich lze více oddělit pomocí ":". Např.: "Inkoustová#462:9549"<br>
<br>
<b>Pozor!</b> Pokud pracujete se souborem na zařízeních Apple, ujistětě se, že vždy při ukládání použijete možnost uložit do formátu Windows nebo Linux (UTF-8)!');

		$form->addSubmit('submit', 'Importovat');

		$form->onValidate[] = function (AdminForm $form) use ($filePicker): void {
			$values = $form->getValues('array');

			/** @var \Nette\Http\FileUpload $file */
			$file = $values['file'];

			if ($file->hasFile()) {
				return;
			}

			$filePicker->addError('Neplatný soubor!');
		};

		$form->onSuccess[] = function (AdminForm $form): void {
			$values = $form->getValues('array');

			/** @var \Nette\Http\FileUpload $file */
			$file = $values['file'];

			$dir = \dirname(__DIR__, 5);
			$productsFileName = $dir . '/userfiles/products.csv';
			$tempFileName = \tempnam($this->container->parameters['tempDir'], 'products');

			$file->move($tempFileName);

			$connection = $this->productRepository->getConnection();
			$connection->getLink()->beginTransaction();

			try {
				Debugger::log($this->importCsv(
					$tempFileName,
					$values['delimiter'],
					$values['addNew'],
					$values['overwriteExisting'],
					$values['updateAttributes'],
					$values['createAttributeValues'],
					$values['searchCriteria'],
				), ILogger::DEBUG);

				FileSystem::copy($tempFileName, $productsFileName);
				FileSystem::delete($tempFileName);

				$connection->getLink()->commit();
				$this->flashMessage('Provedeno', 'success');
			} catch (\Exception $e) {
				Debugger::log($e, ILogger::WARNING);

				try {
					FileSystem::delete($tempFileName);
				} catch (\Exception $e) {
					Debugger::log($e, ILogger::WARNING);
				}

				$connection->getLink()->rollBack();

				$this->flashMessage($e->getMessage() !== '' ? $e->getMessage() : 'Import dat se nezdařil!', 'error');
			}

			$this->redirect('this');
		};

		return $form;
	}

	public function handleMakeProductCategoryPrimary(string $product, string $category): void
	{
		$this->productRepository->one($product)->update(['primaryCategory' => $category]);

		$this->flashMessage('Uloženo', 'success');
		$this->redirect('this');
	}

	public function actionExport(array $ids): void
	{
		unset($ids);
	}

	public function renderExport(array $ids): void
	{
		unset($ids);

		$this->template->headerLabel = 'Export produktů do CSV';
		$this->template->headerTree = [
			['Produkty', 'default'],
			['Export produktů'],
		];
		$this->template->displayButtons = [$this->createBackButton('default')];
		$this->template->displayControls = [$this->getComponent('exportForm')];
	}

	public function createComponentExportForm(): AdminForm
	{
		/** @var \Grid\Datagrid $productGrid */
		$productGrid = $this->getComponent('productGrid');

		$ids = $this->getParameter('ids') ?: [];
		$totalNo = $productGrid->getPaginator()->getItemCount();
		$selectedNo = \count($ids);
		$mutationSuffix = $this->productRepository->getConnection()->getMutationSuffix();

		$form = $this->formFactory->create();
		$form->setAction($this->link('this', ['selected' => $this->getParameter('selected')]));
		$form->addRadioList('bulkType', 'Exportovat', [
			'selected' => "vybrané ($selectedNo)",
			'all' => "celý výsledek ($totalNo)",
		])->setDefaultValue('selected');

		$form->addSelect('delimiter', 'Oddělovač', [
			';' => 'Středník (;)',
			',' => 'Čárka (,)',
			'   ' => 'Tab (\t)',
			' ' => 'Mezera ( )',
			'|' => 'Pipe (|)',
		]);
		$form->addCheckbox('header', 'Hlavička')->setDefaultValue(true)->setHtmlAttribute('data-info', 'Pokud tuto možnost nepoužijete tak nebude možné tento soubor použít pro import!');

		$headerColumns = $form->addDataMultiSelect('columns', 'Sloupce')
		->setHtmlAttribute('data-info', '<br><b>Vysvětlivky sloupců:</b><br>
Sloučené produkty: Sloučené produkty se exportují do sloupce "mergedProducts" jako kódy produktů oddělené znakem ":". Tento sloupec se <b>NEPOUŽÍVÁ</b> při importu!<br>
Nadřazený sloučený produkt: U každého produktu se exportuje jen kód produktu do sloupce "masterProduct" jako jeho předchůdce ve stromové struktuře sloučených produktů. 
Tento sloupec se <b>POUŽÍVÁ</b> při importu!');
		$attributesColumns = $form->addDataMultiSelect('attributes', 'Atributy')->setHtmlAttribute('data-info', 'Zobrazují se pouze atributy, které mají alespoň jeden přiřazený produkt.');

		$items = [];
		$defaultItems = [];

		if (isset($this::CONFIGURATION['exportColumns'])) {
			$items += $this::CONFIGURATION['exportColumns'];

			if (isset($this::CONFIGURATION['defaultExportColumns'])) {
				$defaultItems = \array_merge($defaultItems, $this::CONFIGURATION['defaultExportColumns']);
			}
		}

		$headerColumns->setItems($items);
		$headerColumns->setDefaultValue($defaultItems);

		$attributes = [];
		$defaultAttributes = [];

		if (isset($this::CONFIGURATION['exportAttributes'])) {
			foreach ($this::CONFIGURATION['exportAttributes'] as $key => $value) {
				if ($attribute = $this->attributeRepository->many()->where('code', $key)->first()) {
					$attributes[$attribute->getPK()] = "$value#$key";
					$defaultAttributes[] = $attribute->getPK();
				}
			}

			$attributes += $this->attributeRepository->many()
				->whereNot('this.code', \array_keys($this::CONFIGURATION['exportAttributes']))
				->join(['attributeValue' => 'eshop_attributevalue'], 'this.uuid = attributeValue.fk_attribute')
				->join(['assign' => 'eshop_attributeassign'], 'attributeValue.uuid = assign.fk_value')
				->where('assign.uuid IS NOT NULL')
				->orderBy(["this.name$mutationSuffix"])
				->select(['nameAndCode' => "CONCAT(this.name$mutationSuffix, '#', this.code)"])
				->toArrayOf('nameAndCode');
		}

		$attributesColumns->setItems($attributes);
		$attributesColumns->setDefaultValue($defaultAttributes);

		$form->addSubmit('submit', 'Exportovat');

		$form->onValidate[] = function (AdminForm $form) use ($headerColumns): void {
			$values = $form->getValues();

			if (Arrays::contains($values['columns'], 'code') || Arrays::contains($values['columns'], 'ean')) {
				return;
			}

			$headerColumns->addError('Je nutné vybrat "Kód" nebo "EAN" pro jednoznačné označení produktu.');
		};

		$form->onSuccess[] = function (AdminForm $form) use ($ids, $productGrid, $items, $attributes): void {
			$values = $form->getValues('array');

			$products = $values['bulkType'] === 'selected' ? $this->productRepository->many()->where('this.uuid', $ids) : $productGrid->getFilteredSource();

			$tempFilename = \tempnam($this->tempDir, 'csv');

			$headerColumns = \array_filter($items, function ($item) use ($values) {
				return \in_array($item, $values['columns']);
			}, \ARRAY_FILTER_USE_KEY);

			$attributeColumns = \array_filter($attributes, function ($item) use ($values) {
				return \in_array($item, $values['attributes']);
			}, \ARRAY_FILTER_USE_KEY);

			$this->productRepository->csvExport(
				$products,
				Writer::createFromPath($tempFilename),
				$headerColumns,
				$attributeColumns,
				$values['delimiter'],
				$values['header'] ? \array_merge(\array_values($headerColumns), \array_values($attributeColumns)) : null,
			);

			$this->getPresenter()->sendResponse(new FileResponse($tempFilename, 'products.csv', 'text/csv'));
		};

		return $form;
	}

	public function handleDownloadImportExampleFile(): void
	{
		if (isset($this::CONFIGURATION['importExampleFile']) && $this::CONFIGURATION['importExampleFile']) {
			$this->getPresenter()->sendResponse(new FileResponse($this->wwwDir . '/userfiles/' . $this::CONFIGURATION['importExampleFile'], 'example.csv', 'text/csv'));
		}
	}

	public function actionGenerateRandomBuyCounts(array $ids): void
	{
		unset($ids);
	}

	public function renderGenerateRandomBuyCounts(array $ids): void
	{
		unset($ids);

		$this->template->headerLabel = 'Generovat náhodný počet zakoupení';
		$this->template->headerTree = [
			['Produkty', 'default'],
			['Generovat náhodný počet zakoupení'],
		];
		$this->template->displayButtons = [$this->createBackButton('default')];
		$this->template->displayControls = [$this->getComponent('generateRandomBuyCountsForm')];
	}

	public function createComponentGenerateRandomBuyCountsForm(): AdminForm
	{
		/** @var \Grid\Datagrid $productGrid */
		$productGrid = $this->getComponent('productGrid');

		$ids = $this->getParameter('ids') ?: [];
		$totalNo = $productGrid->getPaginator()->getItemCount();
		$selectedNo = \count($ids);

		$form = $this->formFactory->create();


		$form->setAction($this->link('this', ['selected' => $this->getParameter('selected')]));
		$form->addRadioList('bulkType', 'Produkty', [
			'selected' => "vybrané ($selectedNo)",
			'all' => "celý výsledek ($totalNo)",
		])->setDefaultValue('selected');


		$form->addInteger('from', 'Od')->setDefaultValue(5)->setRequired()->addRule($form::MIN, 'Zadejte číslo rovné nebo větší než 0!', 0);
		$form->addInteger('to', 'Do')->setDefaultValue(30)->setRequired();

		$form->addSubmit('submitAndBack', 'Uložit a zpět')->setHtmlAttribute('class', 'btn btn-outline-primary btn-sm');

		$form->onSuccess[] = function (AdminForm $form) use ($ids, $productGrid): void {
			$values = $form->getValues('array');

			/** @var \StORM\Collection $products */
			$products = $values['bulkType'] === 'selected' ? $this->productRepository->many()->where('this.uuid', $ids) : $productGrid->getFilteredSource();

			foreach ($products as $product) {
				$product->update(['buyCount' => \rand($values['from'], $values['to'])]);
			}

			$this->flashMessage('Provedeno', 'success');
			$this->redirect('default');
		};

		return $form;
	}

	public function actionComments(Product $product): void
	{
		$this->template->headerLabel = 'Komentáře - ' . $product->name;
		$this->template->headerTree = [
			['Produkty', 'default'],
			['Komentáře'],
		];
		$this->template->displayButtons = [$this->createBackButton('default')];
	}

	public function renderComments(Product $product): void
	{
		$this->template->comments = $this->commentRepository->many()->where('fk_product', $product->getPK())->orderBy(['createdTs' => 'DESC'])->toArray();
		$this->template->setFile(__DIR__ . '/templates/comments.latte');
	}

	public function createComponentNewComment(): AdminForm
	{
		$form = $this->formFactory->create(true, false, false, false, false);

		$form->addGroup('Nový komentář');
		$form->addTextArea('text', 'Komentáře');

		$form->addSubmit('send', 'Odeslat');

		$form->onSuccess[] = function (Form $form): void {
			$values = $form->getValues('array');

			/** @var \Admin\DB\Administrator|null $admin */
			$admin = $this->admin->getIdentity();

			if (!$admin) {
				return;
			}

			$data = [
				'product' => $this->getParameter('product')->getPK(),
				'text' => $values['text'],
				'administrator' => $admin->getPK(),
				'adminFullname' => $admin->fullName ??
					($admin->getAccount() ? ($admin->getAccount()->fullname ?? $admin->getAccount()->login) : null),
			];

			$this->commentRepository->createOne($data);

			$this->flashMessage('Uloženo', 'success');
			$this->redirect('edit', ['product' => $this->getParameter('product'), 'editTab' => 'comments']);
		};

		return $form;
	}

	public function handleDropzoneRemovePhoto(): void
	{
		$filename = $this->getPresenter()->getParameter('file');

		/** @var \Eshop\DB\Photo|null $photo */
		$photo = $this->photoRepository->many()->where('fileName', $filename)->first();

		if (!$photo) {
			return;
		}

		$basePath = $this->container->parameters['wwwDir'] . '/userfiles/' . Product::GALLERY_DIR;

		try {
			FileSystem::delete($basePath . '/origin/' . $photo->fileName);
		} catch (IOException $e) {
		}

		try {
			FileSystem::delete($basePath . '/detail/' . $photo->fileName);
		} catch (IOException $e) {
		}

		try {
			FileSystem::delete($basePath . '/thumb/' . $photo->fileName);
		} catch (IOException $e) {
		}

		if ($photo->product->imageFileName === $photo->fileName) {
			$photo->product->update(['imageFileName' => null]);
		}

		$this->photoRepository->many()->where('fileName', $filename)->delete();
	}

	public function handleDropzoneUploadPhoto(): void
	{
		$this->createDirectories();

		/** @var \Eshop\DB\Product $product */
		$product = $this->getParameter('product');

		/** @var \Nette\Http\FileUpload $fileUpload */
		$fileUpload = $this->getPresenter()->getHttpRequest()->getFile('file');

		$basePath = $this->container->parameters['wwwDir'] . '/userfiles/' . Product::GALLERY_DIR;

		$filename = \pathinfo($fileUpload->getSanitizedName(), \PATHINFO_FILENAME);
		$fileExtension = \strtolower(\pathinfo($fileUpload->getSanitizedName(), \PATHINFO_EXTENSION));

		while (\is_file("$basePath/origin/$filename.$fileExtension")) {
			$filename .= '-' . Random::generate(1, '0-9');
		}

		$uuid = Connection::generateUuid();
		$filename .= '.' . $fileUpload->getImageFileExtension();

		/** @var \Eshop\DB\Photo $photo */
		$photo = $this->photoRepository->createOne([
			'uuid' => $uuid,
			'product' => $product->getPK(),
			'fileName' => $filename,
			'priority' => 999,
		]);

		try {
			$fileUpload->move($basePath . '/origin/' . $filename);
		} catch (\Exception $e) {
			$this->error('Wrong file!', 500);
		}

		$imageD = Image::fromFile($basePath . '/origin/' . $filename);
		$imageT = Image::fromFile($basePath . '/origin/' . $filename);
		$imageD->resize(600, null);
		$imageT->resize(300, null);

		try {
			$imageD->save($basePath . '/detail/' . $filename);
			$imageT->save($basePath . '/thumb/' . $filename);
		} catch (\Exception $e) {
		}

		$row = [];
		$row['name'] = $photo->fileName;
		$row['size'] = \file_exists($basePath . '/origin/' . $photo->fileName) ? \filesize($basePath . '/origin/' . $photo->fileName) : 0;
		$row['src'] = $this->getHttpRequest()->getUrl()->withoutUserInfo()->getBaseUrl() . 'userfiles/' . Product::GALLERY_DIR . '/thumb/' . $filename;

		$this->sendJson($row);
	}

	public function handleDropzoneSetMain(?string $filename): void
	{
		if (!$filename) {
			return;
		}

		/** @var \Eshop\DB\Photo|null $photo */
		$photo = $this->photoRepository->many()->where('filename', $filename)->first();

		if (!$photo) {
			return;
		}

		$photo->product->update(['imageFileName' => $filename]);

		$this->redirect('this');
	}

	public function handleDropzoneSetGoogleFeed(): void
	{
		$filename = $this->getPresenter()->getParameter('file');

		if (!$filename) {
			return;
		}

		/** @var \Eshop\DB\Photo|null $photo */
		$photo = $this->photoRepository->many()->where('filename', $filename)->first();

		if (!$photo) {
			return;
		}

		$this->photoRepository->many()->where('fk_product', $photo->getValue('product'))->update(['googleFeed' => false]);
		$photo->update(['googleFeed' => true]);

		$this->redirect('this');
	}

	public function handleDropzoneSetOrder(): void
	{
		$items = $this->getHttpRequest()->getPost();

		if (!$items) {
			return;
		}

		foreach ($items as $uuid => $priority) {
			$this->photoRepository->many()->where('uuid', $uuid)->update(['priority' => (int)$priority]);
		}

		$this->redirect('this');
	}

	protected function createDirectories(): void
	{
		$subDirs = ['origin', 'detail', 'thumb'];
		$dirs = [Product::GALLERY_DIR, Product::FILE_DIR];

		foreach ($dirs as $dir) {
			$rootDir = $this->wwwDir . \DIRECTORY_SEPARATOR . 'userfiles' . \DIRECTORY_SEPARATOR . $dir;
			FileSystem::createDir($rootDir);

			foreach ($subDirs as $subDir) {
				FileSystem::createDir($rootDir . \DIRECTORY_SEPARATOR . $subDir);
			}
		}

		FileSystem::createDir($this->wwwDir . \DIRECTORY_SEPARATOR . 'userfiles' . \DIRECTORY_SEPARATOR . File::FILE_DIR);
	}

	/**
	 * @param string $filePath
	 * @param string $delimiter
	 * @param bool $addNew
	 * @param bool $overwriteExisting
	 * @param bool $updateAttributes
	 * @param bool $createAttributeValues
	 * @return array<string|int>
	 * @throws \League\Csv\Exception
	 * @throws \League\Csv\InvalidArgument
	 * @throws \StORM\Exception\NotFoundException
	 */
	protected function importCsv(
		string $filePath,
		string $delimiter = ';',
		bool $addNew = false,
		bool $overwriteExisting = true,
		bool $updateAttributes = false,
		bool $createAttributeValues = false,
		string $searchCriteria = 'all'
	): array {
		Debugger::timer();

		$csvData = FileSystem::read($filePath);

		$csvData = Encoding::toUTF8($csvData);
		$reader = Reader::createFromString($csvData);

		$reader->setDelimiter($delimiter);
		$reader->setHeaderOffset(0);
		$mutation = $this->productRepository->getConnection()->getMutation();
		$mutationSuffix = $this->productRepository->getConnection()->getMutationSuffix();

		$producers = $this->producerRepository->many()->setIndex('code')->toArrayOf('uuid');
		$stores = $this->storeRepository->many()->setIndex('code')->toArrayOf('uuid');
		$categories = $this->categoryRepository->many()->toArrayOf('uuid');
		$categoriesCodes = $this->categoryRepository->many()->setIndex('code')->toArrayOf('uuid');

		$products = $this->productRepository->many()->setSelect([
			'uuid',
			'code',
			'fullCode' => 'CONCAT(code,".",subCode)',
			'ean',
			'name' => "name$mutationSuffix",
			'perex' => "perex$mutationSuffix",
			'supplierContentLock',
			'mpn',
		], [], true)->fetchArray(\stdClass::class);

		$header = $reader->getHeader();

		$parsedHeader = [];
		$attributes = [];

		$groupedAttributeValues = [];
		$attributeValues = $this->attributeValueRepository->many()->setSelect([
			'uuid',
			'label' => "label$mutationSuffix",
			'code',
			'attribute' => 'fk_attribute',
		], [], true)->fetchArray(\stdClass::class);

		foreach ($attributeValues as $attributeValue) {
			if (!isset($groupedAttributeValues[$attributeValue->attribute])) {
				$groupedAttributeValues[$attributeValue->attribute] = [];
			}

			$groupedAttributeValues[$attributeValue->attribute][$attributeValue->uuid] = $attributeValue;
		}

		unset($attributeValues);

		foreach ($header as $headerItem) {
			if (isset($this::CONFIGURATION['importColumns'][$headerItem])) {
				$parsedHeader[$headerItem] = $headerItem;
			} elseif ($key = \array_search($headerItem, $this::CONFIGURATION['importColumns'])) {
				$parsedHeader[$key] = $headerItem;
			} else {
				if (Strings::contains($headerItem, '#')) {
					$attributeCode = \explode('#', $headerItem);

					if (\count($attributeCode) !== 2) {
						continue;
					}

					$attributeCode = $attributeCode[1];
				} else {
					$attributeCode = $headerItem;
				}

				if ($attribute = $this->attributeRepository->many()->where('code', $attributeCode)->first()) {
					$attributes[$attribute->getPK()] = $attribute;
					$parsedHeader[$attribute->getPK()] = $headerItem;
				}
			}
		}

		if (\count($parsedHeader) === 0) {
			throw new \Exception('Soubor neobsahuje hlavičku nebo nebyl nalezen žádný použitelný sloupec!');
		}

		if (!isset($parsedHeader['code']) && !isset($parsedHeader['ean'])) {
			throw new \Exception('Soubor neobsahuje kód ani EAN!');
		}

		$valuesToUpdate = [];
		$amountsToUpdate = [];
		$productsToDeleteCategories = [];
		$attributeValuesToCreate = [];
		$attributeAssignsToSync = [];

		$createdProducts = 0;
		$updatedProducts = 0;
		$skippedProducts = 0;

		$searchCode = $searchCriteria === 'all' || $searchCriteria === 'code';
		$searchEan = $searchCriteria === 'all' || $searchCriteria === 'ean';

		foreach ($reader->getRecords() as $record) {
			$newValues = [];
			$code = null;
			$ean = null;
			$codePrefix = null;

			// Take Code or Ean based on search criteria - if search by, delete it from record

			/** @var string|null $codeFromRecord */
			$codeFromRecord = isset($parsedHeader['code']) ? ($searchCode ? Arrays::pick($record, $parsedHeader['code'], null) : ($record[$parsedHeader['code']] ?? null)) : null;
			/** @var string|null $eanFromRecord */
			$eanFromRecord = isset($parsedHeader['ean']) ? ($searchEan ? Arrays::pick($record, $parsedHeader['ean'], null) : ($record[$parsedHeader['ean']] ?? null)) : null;

			/** @var \Eshop\DB\Product|null $product */
			$product = null;

			// Sanitize and prefix code and ean

			if (isset($parsedHeader['code']) && $codeFromRecord) {
				$codeBase = Strings::trim($codeFromRecord);
				$codePrefix = Strings::trim('00' . $codeFromRecord);

				$code = $codeBase;
			}

			if (isset($parsedHeader['ean']) && $eanFromRecord) {
				$ean = Strings::trim($eanFromRecord);
			}

			// Fast local search of product based on criteria

			if ($code && $ean && $searchCode && $searchEan) {
				$product = $this->arrayFind($products, function (\stdClass $x) use ($code, $codePrefix, $ean): bool {
					return $x->code === $code || $x->fullCode === $code ||
						$x->code === $codePrefix || $x->fullCode === $codePrefix ||
						$x->ean === $ean;
				});
			} elseif ($code && $searchCode) {
				$product = $this->arrayFind($products, function (\stdClass $x) use ($code, $codePrefix): bool {
					return $x->code === $code || $x->fullCode === $code ||
						$x->code === $codePrefix || $x->fullCode === $codePrefix;
				});
			} elseif ($ean && $searchEan) {
				$product = $this->arrayFind($products, function (\stdClass $x) use ($ean): bool {
					return $x->ean === $ean;
				});
			}

			// Continue based on settings adn data

			if (($searchCode && $searchEan && !$code && !$ean) ||
				($searchCode && !$searchEan && !$code) ||
				($searchEan && !$searchCode && !$ean) ||
				(!$product && !$addNew) ||
				($product && !$overwriteExisting)
			) {
				$skippedProducts++;

				continue;
			}

			if ($product) {
				$updatedProducts++;
			}

			foreach ($record as $key => $value) {
				$key = \array_search($key, $parsedHeader);

				if (!$key) {
					continue;
				}

				if ($key === 'producer') {
					if (Strings::contains($value, '#')) {
						$producerCode = \explode('#', $value);

						if (\count($producerCode) !== 2) {
							continue;
						}

						$producerCode = $producerCode[1];
					} else {
						$producerCode = $value;
					}

					if (isset($producers[$producerCode]) && \strlen($producerCode) > 0) {
						$newValues[$key] = $producers[$producerCode];
					}
				} elseif ($key === 'storeAmount') {
					$amounts = \explode(':', $value);

					foreach ($amounts as $amount) {
						$amount = \explode('#', $amount);

						if (\count($amount) !== 2) {
							continue;
						}

						if (!isset($stores[$amount[1]])) {
							continue;
						}

						$amountsToUpdate[] = [
							'store' => $stores[$amount[1]],
							'product' => $product->uuid,
							'inStock' => \intval($amount[0]),
						];
					}
				} elseif ($key === 'categories') {
					if ($product) {
						$productsToDeleteCategories[] = $product->uuid;
					}

					$valueCategories = \explode(':', $value);

					foreach ($valueCategories as $category) {
						$category = \explode('#', $category);

						if (\count($category) !== 2) {
							continue;
						}

						$category = $category[1];

						if (isset($categories[$category])) {
							$category = $categories[$category];
						} elseif (isset($categoriesCodes[$category])) {
							$category = $categoriesCodes[$category];
						} else {
							continue;
						}

						$newValues['categories'][] = $category;
					}
				} elseif ($key === 'name' || $key === 'perex' || $key === 'content') {
					$newValues[$key][$mutation] = $value;
				} elseif ($key === 'priority') {
					$newValues[$key] = \intval($value);
				} elseif ($key === 'recommended' || $key === 'hidden' || $key === 'unavailable') {
					$newValues[$key] = $value === '1';
				} elseif ($key === 'code') {
					if (!$searchCode) {
						$newValues[$key] = $codeFromRecord ?: null;
					}
				} elseif ($key === 'ean') {
					if (!$searchEan) {
						$newValues[$key] = $eanFromRecord ?: null;
					}
				} elseif ($key === 'masterProduct') {
					$newValues[$key] = null;

					if ($value) {
						$masterProduct = $this->arrayFind($products, function (\stdClass $x) use ($value): bool {
							return $x->code === $value || $x->fullCode === $value;
						});

						if ($masterProduct) {
							$newValues[$key] = $masterProduct->uuid;
						}
					}
				} elseif (!isset($attributes[$key])) {
					$newValues[$key] = $value;
				}
			}

			try {
				if ($product) {
					if (\count($newValues) > 0) {
						$newValues['uuid'] = $product->uuid;
						$newValues['supplierContentLock'] = $product->supplierContentLock;

						if (isset($newValues['name'][$mutation]) && $newValues['name'][$mutation] !== $product->name) {
							$newValues['supplierContentLock'] = true;
						}

						if (isset($newValues['perex'][$mutation]) && $newValues['perex'][$mutation] !== $product->perex) {
							$newValues['supplierContentLock'] = true;
						}

						$valuesToUpdate[$product->uuid] = $newValues;
					}
				} elseif (\count($newValues) > 0) {
					if ($ean) {
						$newValues['ean'] = $ean;
					}

					$newValues['code'] = $code;

					$this->productRepository->createOne($newValues);
				}
			} catch (\Exception $e) {
				throw new \Exception('Chyba při zpracování dat!');
			}

			if (!$updateAttributes) {
				continue;
			}

			foreach ($record as $key => $value) {
				$key = \array_search($key, $parsedHeader);

				if (!isset($attributes[$key]) || \strlen($value) === 0) {
					continue;
				}

				$this->attributeAssignRepository->many()
					->join(['eshop_attributevalue'], 'this.fk_value = eshop_attributevalue.uuid')
					->where('this.fk_product', $product->uuid)
					->where('eshop_attributevalue.fk_attribute', $key)
					->delete();

				$attributeValues = Strings::contains($value, ':') ? \explode(':', $value) : [$value];

				foreach ($attributeValues as $attributeString) {
					if (Strings::contains($attributeString, '#')) {
						$attributeValueCode = \explode('#', $attributeString);

						if (\count($attributeValueCode) !== 2) {
							continue;
						}

						$attributeValueCode = $attributeValueCode[1];
					} else {
						$attributeValueCode = $attributeString;
					}

					/** @var \stdClass|null|false|\Eshop\DB\AttributeValue $attributeValue */
					$attributeValue = $this->arrayFind($groupedAttributeValues[$key] ?? [], function (\stdClass $x) use ($attributeValueCode): bool {
						return $x->code === $attributeValueCode;
					});

					if (!$attributeValue && !$createAttributeValues) {
						continue;
					}

					if (!$attributeValue) {
						/** @var \stdClass|null|false|\Eshop\DB\AttributeValue $attributeValue */
						$attributeValue = $this->arrayFind($groupedAttributeValues[$key] ?? [], function (\stdClass $x) use ($attributeValueCode): bool {
							return $x->label === $attributeValueCode;
						});

						$tried = 0;

						while ($attributeValue === false || $attributeValue === null) {
							try {
								$attributeValue = $this->attributeValueRepository->createOne([
									'code' => Strings::webalize($attributeValueCode) . '-' . Random::generate(),
									'label' => [
										$mutation => $attributeValueCode,
									],
									'attribute' => $key,
								], false, true);

								$attributeValuesToCreate[] = $attributeValue;
							} catch (\Throwable $e) {
							}

							$tried++;

							if ($tried > 10) {
								throw new \Exception('Cant create new attribute value. Tried 10 times! (product:' . $product->code . ')');
							}
						}

						if (!isset($groupedAttributeValues[$key][$attributeValue->uuid])) {
							$groupedAttributeValues[$key][$attributeValue->uuid] = (object) [
								'uuid' => $attributeValue->uuid,
								'label' => $attributeValue instanceof AttributeValue ? $attributeValue->getValue('label', $mutation) : $attributeValue->label,
								'code' => $attributeValue->code,
								'attribute' => $attributeValue instanceof AttributeValue ? $attributeValue->getValue('attribute') : $attributeValue->attribute,
							];
						}
					}

					$attributeAssignsToSync[] = [
						'product' => $product->uuid,
						'value' => $attributeValue->uuid,
					];
				}
			}
		}

		foreach (\array_chunk($productsToDeleteCategories, 100) as $categories) {
			$this->categoryRepository->getConnection()->rows(['eshop_product_nxn_eshop_category'])
				->where('fk_product', $categories)
				->delete();
		}

		$this->attributeAssignRepository->syncMany($attributeAssignsToSync);
		$this->productRepository->syncMany($valuesToUpdate);
		$this->amountRepository->syncMany($amountsToUpdate);

		return [
			'createdProducts' => $createdProducts,
			'updatedProducts' => $updatedProducts,
			'skippedProducts' => $skippedProducts,
			'updatedAmounts' => \count($amountsToUpdate),
			'createdAttributeValues' => \count($attributeValuesToCreate),
			'attributeAssignsUpdated' => \count($attributeAssignsToSync),
			'elapsedTimeInSeconds' => (int) Debugger::timer(),
		];
	}

	/**
	 * @param array<\stdClass> $xs
	 * @param callable $f
	 */
	private function arrayFind(array $xs, callable $f): ?\stdClass
	{
		foreach ($xs as $x) {
			if (\call_user_func($f, $x) === true) {
				return $x;
			}
		}

		return null;
	}
}
