<?php

declare(strict_types=1);

namespace Eshop\Admin;

use Admin\Controls\AdminForm;
use Admin\Controls\AdminGrid;
use Carbon\Carbon;
use Eshop\Admin\Configs\ProductFormAutoPriceConfig;
use Eshop\Admin\Configs\ProductFormConfig;
use Eshop\Admin\Controls\IProductAttributesFormFactory;
use Eshop\Admin\Controls\IProductFormFactory;
use Eshop\Admin\Controls\ProductAttributesForm;
use Eshop\Admin\Controls\ProductAttributesGridFactory;
use Eshop\Admin\Controls\ProductGridFactory;
use Eshop\BackendPresenter;
use Eshop\Common\Services\ProductExporter;
use Eshop\Common\Services\ProductImporter;
use Eshop\DB\AmountRepository;
use Eshop\DB\AttributeAssignRepository;
use Eshop\DB\AttributeRepository;
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
use Eshop\DB\ProductContentRepository;
use Eshop\DB\ProductRepository;
use Eshop\DB\RelatedTypeRepository;
use Eshop\DB\StoreRepository;
use Eshop\DB\SupplierProductRepository;
use Eshop\DB\SupplierRepository;
use Eshop\DB\VatRateRepository;
use Eshop\DB\VisibilityListRepository;
use Eshop\FormValidators;
use Eshop\ShopperUser;
use Forms\Form;
use Nette\Application\Application;
use Nette\Application\Attributes\Persistent;
use Nette\Application\Responses\FileResponse;
use Nette\Application\UI\Component;
use Nette\DI\Attributes\Inject;
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
			'name_cs' => 'Název_cs',
			'priceMin' => 'Minimální nákupní cena',
			'priceMax' => 'Maximální nákupní cena',
			'producer' => 'Výrobce',
			'productContent_perex_cs' => 'Popisek_cs',
			'productContent_content_cs' => 'Obsah_cs',
			'storeAmount' => 'Skladová dostupnost',
			'categories' => 'Kategorie',
			'primaryCategories' => 'Primární kategorie',
			'adminUrl' => 'Admin URL',
			'frontUrl' => 'Front URL ',
			'mergedProducts' => 'Sloučené produkty',
			'masterProduct' => 'Nadřazený sloučený produkt',
			'recyclingFee' => 'Recyklační poplatek',
			'exportHeureka' => 'Exportovat do Heureky',
			'exportGoogle' => 'Exportovat do Google',
			'exportZbozi' => 'Exportovat do Zboží.cz',
			'exportPage_title_cs' => 'SEO Titulek_cs',
			'exportPage_description_cs' => 'SEO Popis_cs',
			'exportPage_url_cs' => 'URL_cs',
		],
		'exportAttributes' => [],
		'defaultExportColumns' => [
			'code',
			'name_cs',
		],
		'defaultExportAttributes' => [],
		'importColumns' => [
			'code' => 'Kód',
			'ean' => 'EAN',
			'name' => 'Název',
			'producer' => 'Výrobce',
			'storeAmount' => 'Skladová dostupnost',
			'masterProduct' => 'Nadřazený sloučený produkt',
			'exportHeureka' => 'Exportovat do Heureky',
			'exportGoogle' => 'Exportovat do Google',
			'exportZbozi' => 'Exportovat do Zboží.cz',
			'categories' => 'Kategorie',
			'primaryCategories' => 'Primární kategorie',
		],
		'importAttributes' => [],
		'importExampleFile' => null,
		'buyCount' => false,
		'attributeTab' => false,
		'loyaltyProgram' => false,
		'detailSuppliersTab' => false,
		'extendedName' => false,
		'karsa' => false,
		ProductFormConfig::class => [
			ProductFormAutoPriceConfig::class => ProductFormAutoPriceConfig::NONE,
		],
	];

	protected const DEFAULT_TEMPLATE = __DIR__ . '/../../_data/newsletterTemplates/newsletter.latte';

	/** @var array<callable(\Eshop\DB\Product, array): void> */
	public array $onProductFormSuccess = [];

	/** @var array<callable(array<string>): void> */
	public array $onImport = [];

	/** @var array<callable(string $mainProduct, array<string> $slaveProducts, array<mixed> $updateValues): void> */
	public array $onMergeFormSuccess = [];

	#[Inject]
	public ProductGridFactory $productGridFactory;

	#[Inject]
	public IProductFormFactory $productFormFatory;

	#[Inject]
	public IProductAttributesFormFactory $productAttributesFormFactory;

	#[Inject]
	public PhotoRepository $photoRepository;

	#[Inject]
	public FileRepository $fileRepository;

	#[Inject]
	public PricelistRepository $pricelistRepository;

	#[Inject]
	public PriceRepository $priceRepository;

	#[Inject]
	public ProductContentRepository $productContentRepository;

	#[Inject]
	public VatRateRepository $vatRateRepository;

	#[Inject]
	public PageRepository $pageRepository;

	#[Inject]
	public SupplierProductRepository $supplierProductRepository;

	#[Inject]
	public ProductRepository $productRepository;

	#[Inject]
	public NewsletterTypeRepository $newsletterTypeRepository;

	#[Inject]
	public ShopperUser $shopperUser;

	#[Inject]
	public SettingRepository $settingRepository;

	#[Inject]
	public AttributeRepository $attributeRepository;

	#[Inject]
	public SupplierRepository $supplierRepository;

	#[Inject]
	public CustomerRepository $customerRepository;

	#[Inject]
	public ProducerRepository $producerRepository;

	#[Inject]
	public AttributeValueRepository $attributeValueRepository;

	#[Inject]
	public AttributeAssignRepository $attributeAssignRepository;

	#[Inject]
	public InternalCommentProductRepository $commentRepository;

	#[Inject]
	public ProductAttributesGridFactory $productAttributesGridFactory;

	#[Inject]
	public CategoryTypeRepository $categoryTypeRepository;

	#[Inject]
	public StoreRepository $storeRepository;

	#[Inject]
	public AmountRepository $amountRepository;

	#[Inject]
	public RelatedTypeRepository $relatedTypeRepository;

	#[Inject]
	public Application $application;

	#[Inject]
	public ProductImporter $productImporter;

	#[Inject]
	public ProductExporter $productExporter;

	#[Inject]
	public VisibilityListRepository $visibilityListRepository;

	#[Persistent]
	public string $tab = 'products';

	#[Persistent]
	public string $editTab = 'menu0';

	/**
	 * @var array<mixed>
	 */
	#[Persistent]
	public array|null $productGridPreFilterForm = null;

	/**
	 * @var array<string>
	 */
	private array $tabs = [
		'products' => 'Katalog',
		'attributes' => 'Atributy',
	];

	public function createComponentProductGrid(): \Grid\Datagrid
	{
		$config = $this::CONFIGURATION;
		$config['isManager'] = $this->isManager;

		return $this->productGridFactory->create($config, $this->getBulkColumns());
	}

	public function createComponentProductGridPreFilterForm(): AdminForm
	{
		$form = $this->formFactory->create(defaultGroup: false);

		$form->addGroup('Nastavení pro výpis');
		$form->addMultiSelect2('priceLists', 'Ceníky', $this->pricelistRepository->getArrayForSelect());
		$form->addMultiSelect2('visibilityLists', 'Seznamy viditenosti', $this->visibilityListRepository->getArrayForSelect())
			->setHtmlAttribute('data-info', '<br>Tato nastavení ovliňují zobrazování dostupnosti ve výpisu produktů. Pokud není vyplněno, používá výchozí nastavení pro aktuální obchod.');

		$form->addSubmit('submit', 'Uložit');
		$form->addSubmit('cancel', 'Zrušit')->setHtmlAttribute('class', 'btn btn-sm btn-secondary form-control-sm');

		$form->setDefaults($this->productGridPreFilterForm ?: []);

		$form->onSuccess[] = function (AdminForm $form, array $values): void {
			$submitter = $form->isSubmitted();
			$submitName = $submitter instanceof Component ? $submitter->getName() : null;

			if ($submitName === 'cancel') {
				$this->productGridPreFilterForm = null;

				$this->redirect('this');
			}

			$this->productGridPreFilterForm = $values;
			$this->redirect('this');
		};

		return $form;
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

		if ($this->shopperUser->getShowVat()) {
			$grid->addColumnInputPrice('Cena s DPH', 'priceVat');
		}

		$grid->addColumnInputPrice('Cena před slevou', 'priceBefore');

		if ($this->shopperUser->getShowVat()) {
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

				if ($this->shopperUser->getShowVat()) {
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

		$form->addHidden('product', (string) $this->getParameter('product'));

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
			$this->redirect('edit', $this->getParameter('product'));
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

		/** @var array<\Eshop\DB\CategoryType> $categoryTypes */
		$categoryTypes = $this->categoryTypeRepository->getCollection(true)->toArray();

		$productData = $product->toArray(['ribbons', 'internalRibbons', 'taxes', 'categories'], selectContent: false);

		foreach ($categoryTypes as $categoryType) {
			$form['categories'][$categoryType->getPK()]
				->checkDefaultValue(false)
				->setDefaultValue($productData['categories']);
		}

		if (!$product->exportGoogle && !$product->exportHeureka && !$product->exportZbozi) {
			$productData['exportNone'] = true;
		}

		foreach ($this->productContentRepository->many()->where('this.fk_product', $product->getPK()) as $productContent) {
			$productContentArray = $productContent->toArray();

			if ($productContent->getValue('shop') === null) {
				$productData['content']['perex'] = $productContentArray['perex'];
				$productData['content']['content'] = $productContentArray['content'];

				continue;
			}

			$productData['content']['content_' . $productContent->getValue('shop')] = $productContentArray['content'];
			$productData['content']['perex_' . $productContent->getValue('shop')] = $productContentArray['perex'];
		}

		$form->setDefaults($productData);

		if (isset($form['customContainer'])) {
			/** @var \Nette\Forms\Container $customContainer */
			$customContainer = $form['customContainer'];
			$customContainer->setDefaults($productData);
		}

		/** @var \Nette\Forms\Controls\SelectBox|null $input */
		$input = $form['supplierContent'] ?? null;

		if (isset($input)) {
			if ($product->supplierContentLock) {
				$input->setDefaultValue(Product::SUPPLIER_CONTENT_MODE_NONE);
			} elseif ($product->supplierContentMode === Product::SUPPLIER_CONTENT_MODE_PRIORITY) {
				$input->setDefaultValue(null);
			}
		}

		if (!$form->getPrettyPages()) {
			return;
		}

		/** @var \Web\DB\Page|null $page */
		$page = $this->pageRepository->getPageByTypeAndParams('product_detail', null, ['product' => $product], selectedShop: $this->shopsConfig->getSelectedShop());

		if (!$page) {
			return;
		}

		/** @var \Forms\Container $pageContainer */
		$pageContainer = $form['page'];

		$pageContainer->setDefaults($page->toArray());

		$form['page']['url']->forAll(function (TextInput $text, $mutation) use ($page, $form): void {
			$text->getRules()->reset();
			$text->addRule(
				[$form, 'validateUrl'],
				'URL již existuje',
				[$this->pageRepository, $mutation, $page->getPK(), $this->shopsConfig->getSelectedShop()],
			);
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
		/** @var array<\Eshop\DB\Photo> $photos */
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

			$this->template->displayControls = [
//				$this->getComponent('productGridPreFilterForm'),
				$this->getComponent('productGrid'),
			];
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

		if (Strings::length($products) > 0) {
			$products = Strings::substring($products, 0, -1);
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

			$functionName = 'newsletterExport' . Strings::firstUpper($values['type']);

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
		$ids = \array_values($this->getParameter('ids') ?: []);

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
			/** @var array<mixed> $values */
			$values = $form->getValues('array');

			$link = $this->productRepository->getConnection()->getLink();
			$link->beginTransaction();

			try {
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

				Arrays::invoke($this->onMergeFormSuccess, $values['mainProduct'], $ids, $updateValues);

				$link->commit();

				$this->flashMessage('Provedeno', 'success');
			} catch (\Exception $e) {
				$link->rollBack();

				Debugger::barDump($e);
				Debugger::log($e, ILogger::EXCEPTION);

				$this->flashMessage($e->getMessage(), 'error');
			}

			$this->redirect('default');
		};

		return $form;
	}

	public function actionImportCsv(): void
	{
		$this->connection->setDebug(false);
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
	}

	public function createComponentImportCsvForm(): AdminForm
	{
		$form = $this->formFactory->create();
		$mutations = $this->connection->getAvailableMutations();

		$lastUpdate = null;
		$path = \dirname(__DIR__, 5) . '/userfiles/products.csv';

		if (\file_exists($path)) {
			$lastUpdate = \filemtime($path);
		}

		$form->addGroup('CSV soubor');
		$form->addText('lastProductFileUpload', 'Poslední aktualizace souboru')->setDisabled()->setDefaultValue($lastUpdate ? Carbon::createFromTimestamp($lastUpdate)->format('d.m.Y G:i') : null);

		$importColumns = $this->getImportColumns();
		$allowedColumns = '';

		$productImportColumns = [
			'name' => 'Název',
		];

		foreach ($productImportColumns as $key => $value) {
			foreach ($mutations as $mutation) {
				$allowedColumns .= "$key, $value$mutation<br>";
			}

			unset($importColumns[$key]);
		}

		$productContentColumns = [
			'productContent_perex' => 'Perex',
			'productContent_content' => 'Obsah',
		];

		foreach ($productContentColumns as $key => $value) {
			foreach ($mutations as $mutation) {
				$allowedColumns .= "$key, $value$mutation<br>";
			}

			unset($importColumns[\explode('_', $key)[1]]);
		}

		$pagesImportColumns = [
			'title' => 'SEO Titulek',
			'description' => 'SEO Popis',
			'url' => 'URL',
		];

		foreach ($pagesImportColumns as $key => $value) {
			foreach ($mutations as $mutation) {
				$pagesImportColumns[$key . $mutation] = $value . $mutation . ($key === 'url' ? ' (URL bez domény)' : '');
			}

			unset($pagesImportColumns[$key]);
		}

		foreach (\array_merge($importColumns, $pagesImportColumns) as $key => $value) {
			$allowedColumns .= "$key, $value<br>";
		}

		$filePicker = $form->addFilePicker('file', 'Soubor (CSV)')
			->setRequired()
			->addRule($form::MimeType, 'Neplatný soubor!', 'text/csv');

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

		$dataInfo = '<h5 class="mt-2">Nápověda</h5>
Soubor <b>musí obsahovat</b> hlavičku a jeden ze sloupců "Kód" nebo "EAN" pro jednoznačné rozlišení produktů.&nbsp;
Jako prioritní se hledá kód a pokud není nalezen tak EAN. Kód se ukládá jen při vytváření nových záznamů.<br><br>
Povolené sloupce hlavičky (lze použít obě varianty kombinovaně):<br>
' . $allowedColumns . '<br>
Atributy a výrobce musí být zadány jako kód (např.: "001") nebo jako kombinace názvu a kódu(např.: "Tisková technologie#001).<br>
Hodnoty atributů a skladové množství se zadávají ve stejném formátu jako atributy s tím že jich lze více oddělit pomocí ":". Např.: "Inkoustová#462:9549"<br>
Kategorie se zadávají ve formátu "KOD_KATEGORIE#KOD_TYPU".<br><br>
Pro dané seznamy viditelnosti budou importovány všechny sloupce ve tvaru "HODNOTA#KOD_SEZNAMU" Např.: "Skryto#seznam1".
<br>
<b>Pozor!</b> Pokud pracujete se souborem na zařízeních Apple, ujistětě se, že vždy při ukládání použijete možnost uložit do formátu Windows nebo Linux (UTF-8)!';

		if ($this->shopsConfig->getAvailableShops()) {
			$dataInfo .= '<br><br>Váš eshop využívá více obchodů.<br>
Perex a Obsah budou importovány vždy pro aktuálně zvolený obchod.';
		}

		$form->addCheckbox('createAttributeValues', 'Vytvářet hodnoty atributů (pokud neexistují, hledá dle jména)')
			->setHtmlAttribute('data-info', $dataInfo);

		$form->addSubmit('submit', 'Importovat');

		$form->onValidate[] = function (AdminForm $form) use ($filePicker): void {
			/** @var array<mixed> $values */
			$values = $form->getValues('array');

			/** @var \Nette\Http\FileUpload $file */
			$file = $values['file'];

			if ($file->hasFile()) {
				return;
			}

			$filePicker->addError('Neplatný soubor!');
		};

		$form->onSuccess[] = function (AdminForm $form): void {
			/** @var array<mixed> $values */
			$values = $form->getValues('array');

			/** @var \Nette\Http\FileUpload $file */
			$file = $values['file'];

			$dir = \dirname(__DIR__, 5);
			$productsFileName = $dir . '/userfiles/products.csv';
			$tempFileName = \tempnam($this->container->getParameter('tempDir'), 'products');

			if (!$tempFileName) {
				throw new \Exception('Cant create temp file');
			}

			$file->move($tempFileName);

			$connection = $this->productRepository->getConnection();
			$connection->getLink()->beginTransaction();

			try {
				Debugger::log($this->productImporter->importCsv(
					$tempFileName,
					$values['delimiter'],
					$values['addNew'],
					$values['overwriteExisting'],
					$values['updateAttributes'],
					$values['createAttributeValues'],
					$values['searchCriteria'],
					$this->getImportColumns(),
					$this->onImport,
				), ILogger::DEBUG);

				FileSystem::copy($tempFileName, $productsFileName);

				$connection->getLink()->commit();
				$this->flashMessage('Import produktů: úspěšný', 'success');
			} catch (\Exception $e) {
				Debugger::barDump($e);

				$connection->getLink()->rollBack();

				$this->flashMessage('Import produktů: ' . ($e->getMessage() !== '' ? $e->getMessage() : 'chyba'), 'error');
			}

			$connection->getLink()->beginTransaction();

			try {
				Debugger::log($this->productImporter->importPagesCsv(
					$tempFileName,
					$values['delimiter'],
				), ILogger::DEBUG);

				$connection->getLink()->commit();
				$this->flashMessage('Import stránek produktů: úspěšný', 'success');
			} catch (\Exception $e) {
				$connection->getLink()->rollBack();

				$this->flashMessage('Import stránek produktů: ' . ($e->getMessage() !== '' ? $e->getMessage() : 'chyba'), 'error');
			}

			try {
				FileSystem::delete($tempFileName);
			} catch (\Exception $e) {
				Debugger::log($e, ILogger::WARNING);
			}

			$this->redirect('this');
		};

		return $form;
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
		/** @var \Admin\Controls\AdminGrid $productGrid */
		$productGrid = $this->getComponent('productGrid');

		return $this->productExporter->createForm(
			$productGrid,
			$this->getExportColumns(),
			/** @phpstan-ignore-next-line */
			$this::CONFIGURATION['defaultExportColumns'] ?? self::CONFIGURATION['defaultExportColumns'] ?? [],
			/** @phpstan-ignore-next-line */
			$this::CONFIGURATION['exportAttributes'] ?? self::CONFIGURATION['exportAttributes'] ?? [],
			$this->getCsvExportGetSupplierCodeCallback(),
		);
	}

	public function getCsvExportGetSupplierCodeCallback(): ?callable
	{
		return null;
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
		$fileExtension = Strings::lower(\pathinfo($fileUpload->getSanitizedName(), \PATHINFO_EXTENSION));

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
			$this->photoRepository->many()->where('uuid', $uuid)->update(['priority' => (int) $priority]);
		}

		$this->redirect('this');
	}

	/**
	 * @return array<string, string>
	 */
	protected function getExportColumns(): array
	{
		return $this::CONFIGURATION['exportColumns'] ?? [];
	}

	/**
	 * @return array<string, string>
	 */
	protected function getImportColumns(): array
	{
		return $this::CONFIGURATION['importColumns'] ?? [];
	}

	/**
	 * @return list<string>
	 */
	protected function getBulkColumns(): array
	{
		return [
			'producer',
			'categories',
			'ribbons',
			'internalRibbons',
			'displayAmount',
			'displayDelivery',
			'vatRate',
			'taxes',
			'primaryCategories',
			'defaultReviewsCount',
			'defaultReviewsScore',
			'supplierDisplayAmountLock',
			'supplierDisplayAmountMergedLock',
			'weight',
			'width',
			'length',
			'depth',
			'dimension',
			'exportHeureka',
			'exportZbozi',
			'exportGoogle',
		];
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
}
