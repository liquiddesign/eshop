<?php

declare(strict_types=1);

namespace Eshop\Admin;

use Common\NumbersHelper;
use Eshop\Admin\Controls\ProductAttributesGridFactory;
use Eshop\BackendPresenter;
use Admin\Controls\AdminForm;
use Eshop\Admin\Controls\IProductAttributesFormFactory;
use Eshop\Admin\Controls\IProductFormFactory;
use Eshop\Admin\Controls\IProductParametersFormFactory;
use Eshop\Admin\Controls\ProductGridFactory;
use Eshop\DB\AmountRepository;
use Eshop\DB\AttributeAssignRepository;
use Eshop\DB\AttributeRepository;
use Eshop\DB\AttributeValueRepository;
use Eshop\DB\Category;
use Eshop\DB\CategoryType;
use Eshop\DB\CategoryTypeRepository;
use Eshop\DB\CustomerRepository;
use Eshop\DB\File;
use Eshop\DB\FileRepository;
use Eshop\DB\InternalCommentProductRepository;
use Eshop\DB\NewsletterTypeRepository;
use Eshop\DB\ParameterRepository;
use Eshop\DB\ParameterValueRepository;
use Eshop\DB\Photo;
use Eshop\DB\PhotoRepository;
use Eshop\DB\Pricelist;
use Eshop\DB\PricelistRepository;
use Eshop\DB\PriceRepository;
use Eshop\DB\ProducerRepository;
use Eshop\DB\Product;
use Eshop\DB\ProductRepository;
use Eshop\DB\SetRepository;
use Eshop\DB\StoreRepository;
use Eshop\DB\SupplierProductRepository;
use Eshop\DB\SupplierRepository;
use Eshop\DB\VatRateRepository;
use Eshop\FormValidators;
use Eshop\Shopper;
use Forms\Form;
use League\Csv\Reader;
use League\Csv\Writer;
use Nette\Application\Application;
use Nette\Application\Responses\FileResponse;
use Nette\Forms\Controls\TextInput;
use Nette\Http\FileUpload;
use Nette\InvalidArgumentException;
use Nette\Utils\Arrays;
use Nette\Utils\FileSystem;
use Nette\Utils\Image;
use Nette\Utils\Random;
use Nette\Utils\Strings;
use Onnov\DetectEncoding\EncodingDetector;
use Pages\DB\PageRepository;
use StORM\Collection;
use StORM\Connection;
use StORM\DIConnection;
use StORM\Expression;
use Web\DB\SettingRepository;

class ProductPresenter extends BackendPresenter
{
	protected const CONFIGURATION = [
		'sets' => true,
		'relations' => true,
		'parameters' => true,
		'taxes' => true,
		'upsells' => true,
		'suppliers' => true,
		'weightAndDimension' => false,
		'discountLevel' => true,
		'rounding' => true,
		'importButton' => false,
		'exportButton' => false,
		'exportColumns' => [
			'code' => 'Kód',
			'ean' => 'EAN',
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
		],
		'importAttributes' => [],
		'importExampleFile' => null,
		'importSetExampleFile' => null,
		'buyCount' => false,
		'attributeTab' => false,
		'loyaltyProgram' => false,
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

	/** @inject */
	public ProductGridFactory $productGridFactory;

	/** @inject */
	public IProductFormFactory $productFormFatory;

	/** @inject */
	public IProductParametersFormFactory $productParametersFormFatory;

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
	public ParameterRepository $parameterRepository;

	/** @inject */
	public ParameterValueRepository $parameterValueRepository;

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
	public SetRepository $setRepository;

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
	public Application $application;

	private array $tabs = [
		'products' => 'Katalog',
		'attributes' => 'Atributy'
	];

	/** @persistent */
	public string $tab = 'products';

	/** @persistent */
	public string $editTab = 'menu0';

	public function createComponentProductGrid()
	{
		return $this->productGridFactory->create(static::CONFIGURATION);
	}

	public function createComponentProductAttributesGrid()
	{
		return $this->productAttributesGridFactory->create(static::CONFIGURATION);
	}

	public function createComponentProductForm()
	{
		return $this->productFormFatory->create($this->getParameter('product'), static::CONFIGURATION);
	}

	public function createComponentPhotoGrid()
	{
		$grid = $this->gridFactory->create($this->photoRepository->many()->where('fk_product', $this->getParameter('product')->getPK()), 20, 'priority', 'ASC', true);
		$grid->addColumnImage('fileName', Photo::IMAGE_DIR);

		$grid->addColumnText('Popisek', 'label_cs', '%s', 'label_cs');
		$grid->addColumnText('Zdroj', 'supplier.name', '%s', 'supplier.name');
		$grid->addColumnInputInteger('Priorita', 'priority', '', '', 'priority', [], true);
		$grid->addColumnInputCheckbox('<i title="Skryto" class="far fa-eye-slash"></i>', 'hidden', '', '', 'hidden');
		$grid->addColumnLinkDetail('detailPhoto');
		$grid->addColumnActionDelete([$this, 'deleteGalleryPhoto']);
		$grid->addFilterTextInput('search', ['fileName'], null, 'Název');

		if ($suppliers = $this->supplierRepository->getArrayForSelect()) {
			$grid->addFilterDataSelect(function (Collection $source, $value) {
				$source->where('supplier.uuid', $value);
			}, '', 'supplier', null, $suppliers)->setPrompt('- Zdroj -');
		}

		$grid->addFilterButtons(['productPhotos', $this->getParameter('product')]);

		$grid->addButtonSaveAll();

		return $grid;
	}

	public function createComponentFileGrid()
	{
		$grid = $this->gridFactory->create($this->fileRepository->many()->where('fk_product', $this->getParameter('product')->getPK()), 20, 'priority', 'ASC', true);
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

	public function createComponentPriceGrid()
	{
		$product = $this->getParameter('product');
		$countryCode = 'CZ';

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
		$submit->onClick[] = function ($button) use ($grid, $product) {
			foreach ($grid->getInputData() as $id => $data) {
				if (!isset($data['price'])) {
					continue;
				}

				$newData = [
					'price' => isset($data['price']) ? \floatval(\str_replace(',', '.', $data['price'])) : 0,
					'priceBefore' => isset($data['priceBefore']) ? \floatval(\str_replace(',', '.', $data['priceBefore'])) : null,
					'product' => $product,
					'pricelist' => $id,
				];

				if ($this->shopper->getShowVat()) {
					$newData += [
						'priceVat' => isset($data['priceVat']) ? \floatval(\str_replace(',', '.', $data['priceVat'])) : ($data['price'] + ($data['price'] * \fdiv(\floatval($this->vatRateRepository->getDefaultVatRates()[$product->vatRate]), 100))),
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

	public function deletePrice(Pricelist $pricelist)
	{
		$this->priceRepository->getPricesByPriceList($pricelist)->where('fk_product', $this->getParameter('product'))->delete();
	}

	public function createComponentFileForm(): Form
	{
		$form = $this->formFactory->create(true, false, false, false, false);

		if (!$this->getParameter('file')) {
			$form->addFilePicker('fileName', 'Vybrat soubor', \DIRECTORY_SEPARATOR . Product::FILE_DIR)->setRequired();
		}

		$form->addLocaleText('label', 'Popisek')->forPrimary(function ($input) {
			$input->setRequired();
		});
		$form->addInteger('priority', 'Priorita')->setDefaultValue(10);
		$form->addCheckbox('hidden', 'Skryto');

		$form->addHidden('product', (string)$this->getParameter('product'));

		$form->addSubmit('submit', 'Uložit');

		$form->onSuccess[] = function (Form $form) {
			$values = $form->getValues('array');

			$this->createDirectories();

			if (!$values['uuid']) {
				$values['uuid'] = DIConnection::generateUuid();
			}

			if (isset($values['fileName'])) {
				$values['fileName'] = $form['fileName']->upload($values['uuid'] . '.%2$s');
			}

			$file = $this->fileRepository->syncOne($values);

			$this->flashMessage('Uloženo', 'success');
			$this->redirect('edit', [new Product(['uuid' => $values['product']])]);
		};

		return $form;
	}

	public function createComponentPhotoForm(): Form
	{
		$form = $this->formFactory->create(true);

		$imagePicker = $form->addImagePicker('fileName', 'Obrázek', [
			Product::GALLERY_DIR . \DIRECTORY_SEPARATOR . 'origin' => null,
			Product::GALLERY_DIR . \DIRECTORY_SEPARATOR . 'detail' => static function (Image $image): void {
				$image->resize(600, null);
			},
			Product::GALLERY_DIR . \DIRECTORY_SEPARATOR . 'thumb' => static function (Image $image): void {
				$image->resize(300, null);
			},
		]);

		$form->addLocaleText('label', 'Popisek');

		/*$imagePicker->onDelete[] = function (array $directories, $filename) use ($product) {
			foreach ($directories as $key => $directory) {
				FileSystem::delete($this->wwwDir . \DIRECTORY_SEPARATOR . 'userfiles' . \DIRECTORY_SEPARATOR . $key . \DIRECTORY_SEPARATOR . $photo->fileName);
			}
			
			$photo->delete();
			$this->redirect('productPhotos', $product);
		};*/

		$form->addInteger('priority', 'Priorita')->setDefaultValue(10);
		$form->addCheckbox('hidden', 'Skryto');
		$form->addHidden('product', (string)($this->getParameter('photo') ? $this->getParameter('photo')->product : $this->getParameter('product')));

		$form->addSubmits(false, false);

		$form->onSuccess[] = function (AdminForm $form) {
			$values = $form->getValues('array');

			$this->createDirectories();

			if (!$values['uuid']) {
				$values['uuid'] = DIConnection::generateUuid();
			}

			$values['fileName'] = $form['fileName']->upload($values['uuid'] . '.%2$s');

			$photo = $this->photoRepository->syncOne($values);

			$this->flashMessage('Uloženo', 'success');
			$this->redirect('photos', [new Product(['uuid' => $values['product']])]);

		};

		return $form;
	}

	public function createComponentParameterForm()
	{
		return $this->productAttributesFormFactory->create($this->getParameter('product'), false);
	}

	public function actionDetailFile(File $file)
	{
		/** @var \Forms\Form $form */
		$form = $this->getComponent('fileForm');
		$form->setDefaults($file->toArray());
	}

	public function renderDetailFile(File $file)
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

	public function renderNewFile(Product $product)
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

	public function renderDetailPhoto(Photo $photo)
	{
		$this->template->headerLabel = 'Nová položka';
		$this->template->headerTree = [
			['Produkty', 'default'],
			['Fotografie', 'photos', $photo->product],
			['Detail'],
		];
		$this->template->displayButtons = [$this->createBackButton('photos', $photo->product), $this->createButtonWithClass('makePhotoPrimary!', '<i class="fas fa-star"></i>  Převést obrázek na hlavní', 'btn btn-sm btn-outline-primary', $photo)];
		$this->template->displayControls = [$this->getComponent('photoForm')];
	}

	public function renderNewPhoto(Product $product)
	{
		$this->template->headerLabel = 'Nová položka';
		$this->template->headerTree = [
			['Produkty', 'default'],
			['Fotky', 'photos', $product],
			['Nová položka'],
		];
		$this->template->displayButtons = [$this->createBackButton('photos', $product)];
		$this->template->displayControls = [$this->getComponent('photoForm')];
	}

	public function renderNew()
	{
		$this->template->headerLabel = 'Detail';
		$this->template->headerTree = [
			['Produkty', 'default'],
			['Detail'],
		];
		$this->template->displayButtons = [$this->createBackButton('default')];
		$this->template->displayControls = [
			$this->getComponent('productForm'),
		];
	}

	public function actionDetailPhoto(Photo $photo)
	{
		/** @var \Forms\Form $form */
		$form = $this->getComponent('photoForm');
		$form->setDefaults($photo->toArray());
	}

	public function actionEdit(Product $product)
	{
		/** @var \Forms\Form $form */
		$form = $this->getComponent('productForm')['form'];

		$prices = $this->pricelistRepository->many()->orderBy(['this.priority'])
			->join(['prices' => 'eshop_price'], 'prices.fk_product=:product AND prices.fk_pricelist=this.uuid', ['product' => $product])
			->select([
				'price' => 'prices.price',
				'priceVat' => 'prices.priceVat',
				'priceBefore' => 'prices.priceBefore',
				'priceVatBefore' => 'prices.priceVatBefore',
			])->toArray();

		foreach ($form['prices']->getComponents() as $pricelistId => $container) {
			$container->setDefaults($prices[$pricelistId]->toArray());
		}

		/** @var CategoryType[] $categoryTypes */
		$categoryTypes = $this->categoryTypeRepository->getCollection(true)->toArray();

		$productData = $product->toArray(['ribbons', 'internalRibbons', 'parameterGroups', 'taxes', 'categories']);

		foreach ($categoryTypes as $categoryType) {
			$form['categories'][$categoryType->getPK()]
				->checkDefaultValue(false)
				->setDefaultValue($productData['categories']);
		}

		$form->setDefaults($productData);
		$form['alternative']->setValue($product->alternative ? $product->getValue('alternative') : null);

		if (isset($form['upsells'])) {
			$this->template->select2AjaxDefaults[$form['upsells']->getHtmlId()] = $product->upsells->toArrayOf('name');
		}

		if (isset($form['tonerForPrinters'])) {
			try {
				$this->template->select2AjaxDefaults[$form['tonerForPrinters']->getHtmlId()] = $this->productRepository->getSlaveProductsByRelationAndMaster('tonerForPrinter', $product)->toArrayOf('name');
			} catch (InvalidArgumentException $e) {
				$form['tonerForPrinters']->setHtmlAttribute('data-error', 'Byla detekována chybná vazba! Vyberte, prosím, tiskárny znovu.');
			}
		}

		if ($product->supplierContentLock && isset($form['supplierContent'])) {
			$form['supplierContent']->setDefaultValue(0);
		}

		if ($form->getPrettyPages()) {
			if ($page = $this->pageRepository->getPageByTypeAndParams('product_detail', null, ['product' => $product])) {
				$form['page']->setDefaults($page->toArray());

				$form['page']['url']->forAll(function (TextInput $text, $mutation) use ($page, $form) {
					$text->getRules()->reset();
					$text->addRule([$form, 'validateUrl'], 'URL již existuje', [$this->pageRepository, $mutation, $page->getPK()]);
				});
			}
		}
	}

	public function renderEdit(Product $product)
	{
		$this->template->product = $product;
		$this->template->headerLabel = 'Detail - ' . $product->name;
		$this->template->headerTree = [
			['Produkty', 'default'],
			['Detail'],
		];
		$this->template->displayButtons = [$this->createBackButton('default')];
		$this->template->displayControls = [
			$this->getComponent('productForm')
		];

		if (isset($this::CONFIGURATION['parameters']) && $this::CONFIGURATION['parameters'] && $this->getParameter('product')) {
			$this->template->displayControls[] = $this->getComponent('parameterForm');
		}

		$this->template->editTab = $this->editTab;
		$this->template->comments = $this->commentRepository->many()->where('fk_product', $product->getPK())->orderBy(['createdTs' => 'DESC'])->toArray();

		$data = [];
		/** @var Photo[] $photos */
		$photos = $this->photoRepository->many()->where('fk_product', $product->getPK())->orderBy(['priority']);

		$basePath = $this->container->parameters['wwwDir'] . '/userfiles/' . Product::GALLERY_DIR . '/origin/';

		foreach ($photos as $photo) {
			$row = [];
			$row['name'] = $photo->fileName;
			$row['size'] = \file_exists($basePath . $photo->fileName) ? \filesize($basePath . $photo->fileName) : 0;
			$row['main'] = $product->imageFileName === $photo->fileName;

			$data[$photo->fileName] = $row;
		}

		$this->template->photos = $data;

		$this->template->setFile(__DIR__ . '/templates/product.edit.latte');
	}

	public function actionParameters(Product $product)
	{

	}

	public function renderParameters(Product $product)
	{
		$this->template->headerLabel = 'Parametery - ' . $product->name;
		$this->template->headerTree = [
			['Produkty', 'default'],
			['Soubory'],
		];
		$this->template->displayButtons = [$this->createBackButton('default')];
		$this->template->displayControls = [$this->getComponent('parameterForm')];
	}

	public function renderPrices(Product $product)
	{
		$this->template->headerLabel = 'Ceny - ' . $product->name;
		$this->template->headerTree = [
			['Produkty', 'default'],
			['Ceny'],
		];
		$this->template->displayButtons = [$this->createBackButton('default')];
		$this->template->displayControls = [$this->getComponent('priceGrid')];
	}

	public function renderPhotos(Product $product)
	{
		$this->template->headerLabel = 'Fotografie - ' . $product->name;
		$this->template->headerTree = [
			['Produkty', 'default'],
			['Fotografie'],
		];

//		$this->template->displayButtons = [$this->createBackButton('default'), $this->createNewItemButton('newPhoto', [$product])];
//		$this->template->displayControls = [$this->getComponent('photoGrid')];

		$this->template->displayButtons = [$this->createBackButton('default')];
		$this->template->displayControls = [];

		$this->template->setFile(__DIR__ . '/templates/productPhotosDropzone.latte');

		/** @var Product $product */
		$product = $this->getParameter('product');

		$data = [];
		/** @var Photo[] $photos */
		$photos = $this->photoRepository->many()->where('fk_product', $product->getPK())->orderBy(['priority']);

		$basePath = $this->container->parameters['wwwDir'] . '/userfiles/' . Product::GALLERY_DIR . '/origin/';

		foreach ($photos as $photo) {
			$row = [];
			$row['name'] = $photo->fileName;
			$row['size'] = \file_exists($basePath . $photo->fileName) ? \filesize($basePath . $photo->fileName) : 0;
			$row['main'] = $product->imageFileName === $photo->fileName;

			$data[$photo->fileName] = $row;
		}

		$this->template->photos = $data;
	}

	public function renderFiles(Product $product)
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
		if (isset(static::CONFIGURATION['attributeTab']) && static::CONFIGURATION['attributeTab']) {
			$this->template->tabs = $this->tabs;
		}

		if ($this->tab == 'attributes' && isset(static::CONFIGURATION['attributeTab']) && static::CONFIGURATION['attributeTab']) {
			$this->template->headerLabel = 'Produkty';
			$this->template->headerTree = [
				['Produkty', 'default'],
				['Atributy']
			];
			$this->template->displayButtons = [];
			$this->template->displayControls = [$this->getComponent('productAttributesGrid')];
			$this->template->setFile(__DIR__ . '/templates/productAttributesGrid.latte');
		} else {
			$this->template->headerLabel = 'Produkty';
			$this->template->headerTree = [
				['Produkty', 'default'],
				['Katalog']
			];
			$this->template->displayButtons = [$this->createNewItemButton('new')];

			if (isset(static::CONFIGURATION['importButton']) && static::CONFIGURATION['importButton']) {
				$this->template->displayButtons[] = $this->createButton('importCsv', '<i class="fas fa-file-upload mr-1"></i>Import');
			}

			if (isset(static::CONFIGURATION['sets']) && static::CONFIGURATION['sets']) {
				$this->template->displayButtons[] = $this->createButton('importSetCsv', '<i class="fas fa-file-upload mr-1"></i>Import setů');
			}

			$this->template->displayControls = [$this->getComponent('productGrid')];
		}
	}

	public function deleteFile(File $file)
	{
		$dir = File::FILE_DIR;
		$rootDir = $this->wwwDir . \DIRECTORY_SEPARATOR . 'userfiles' . \DIRECTORY_SEPARATOR . $dir;

		if (!$file->fileName) {
			return;
		}

		FileSystem::delete($rootDir . \DIRECTORY_SEPARATOR . $file->fileName);
	}

	public function deleteGalleryPhoto(Photo $photo)
	{
		$subDirs = ['origin', 'detail', 'thumb'];
		$dir = Photo::IMAGE_DIR;

		if (!$photo->fileName) {
			return;
		}

		foreach ($subDirs as $subDir) {
			$rootDir = $this->wwwDir . \DIRECTORY_SEPARATOR . 'userfiles' . \DIRECTORY_SEPARATOR . $dir;
			FileSystem::delete($rootDir . \DIRECTORY_SEPARATOR . $subDir . \DIRECTORY_SEPARATOR . $photo->fileName);
		}

		$photo->update(['fileName' => null]);
	}

	protected function createDirectories()
	{
		$subDirs = ['origin', 'detail', 'thumb'];
		$dirs = [Product::IMAGE_DIR, Photo::IMAGE_DIR, Product::FILE_DIR];

		foreach ($dirs as $dir) {
			$rootDir = $this->wwwDir . \DIRECTORY_SEPARATOR . 'userfiles' . \DIRECTORY_SEPARATOR . $dir;
			FileSystem::createDir($rootDir);

			foreach ($subDirs as $subDir) {
				FileSystem::createDir($rootDir . \DIRECTORY_SEPARATOR . $subDir);
			}
		}

		FileSystem::createDir($this->wwwDir . \DIRECTORY_SEPARATOR . 'userfiles' . \DIRECTORY_SEPARATOR . File::FILE_DIR);
	}

	public function actionNewsletterExportSelect(array $ids)
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

	public function renderNewsletterExportSelect(array $ids)
	{
		$this->template->headerLabel = 'Export pro newsletter';
		$this->template->headerTree = [
			['Produkty', 'default'],
			['Export pro newsletter']
		];
		$this->template->displayButtons = [$this->createBackButton('default')];
		$this->template->displayControls = [$this->getComponent('newsletterExportProducts')];
	}

	public function createComponentNewsletterExportForm()
	{
		/** @var \Grid\Datagrid $productGrid */
		$productGrid = $this->getComponent('productGrid');

		$ids = $this->getParameter('ids') ?: [];
		$totalNo = $productGrid->getFilteredSource()->enum();
		$selectedNo = \count($ids);

		$form = $this->formFactory->create();
		$form->setAction($this->link('this', ['selected' => $this->getParameter('selected')]));
		$form->addRadioList('bulkType', 'Upravit', [
			'selected' => "vybrané",
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

		$form->onSuccess[] = function (AdminForm $form) use ($ids, $productGrid) {
			$values = $form->getValues('array');

			$functionName = 'newsletterExport' . (\ucfirst($values['type']));

//			try {
			if ($values['bulkType'] == 'selected') {
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
//			} catch (\Exception $e) {
//				bdump($e);
//			}
		};

		return $form;
	}

	public function createComponentNewsletterExportProducts()
	{
		/** @var \Grid\Datagrid $productGrid */
		$productGrid = $this->getComponent('productGrid');

		$ids = $this->getParameter('ids') ?: [];
		$totalNo = $productGrid->getFilteredSource()->enum();
		$selectedNo = \count($ids);

		$form = $this->formFactory->create();
		$form->setAction($this->link('this', ['selected' => $this->getParameter('selected')]));
		$form->addRadioList('bulkType', 'Upravit', [
			'selected' => "vybrané",
			'all' => "celý výsledek ($totalNo)",
		])->setDefaultValue('selected');

		$form->addText('products', 'Produkty')
			->setHtmlAttribute('data-info', 'EAN nebo kódy oddělené středníky.')
			->setNullable()
			->addCondition($form::FILLED)
			->addRule([FormValidators::class, 'isMultipleProductsExists'], 'Chybný formát nebo nebyl nalezen některý ze zadaných produktů!', [$this->productRepository]);
		$form->addSelect('mutation', 'Jazyk', \array_combine($this->formFactory->getMutations(), $this->formFactory->getMutations()))->setRequired();
		$form->addDataMultiSelect('pricelists', 'Ceníky', $this->pricelistRepository->getArrayForSelect())->setRequired();
		$form->addPerexEdit('text', 'Textový obsah')->setHtmlAttribute('data-info', 'Můžete využít i proměnné systému MailerLite. Např.: "{$email}". Více informací <a href="http://help.mailerlite.com/article/show/29194-what-custom-variables-can-i-use-in-my-campaigns" target="_blank">zde</a>.');

		$form->addSubmit('submit', 'Stáhnout');

		$form->onSuccess[] = function (AdminForm $form) use ($ids, $productGrid) {
			$values = $form->getValues('array');

			$products = $this->productRepository->getProducts($this->pricelistRepository->many()->where('this.uuid', $values['pricelists'])->toArray())->where('this.uuid', $ids)->toArray();

			$this->productRepository->getConnection()->setMutation($values['mutation']);
			$this->translator->setMutation($values['mutation']);
			$this->getTemplate()->setTranslator($this->translator);

			$html = $this->getTemplate()->renderToString($this::DEFAULT_TEMPLATE, [
				'type' => 'products',
				'text' => $values['text'],
				'args' => [
					'products' => $products,
					'lang' => $values['mutation']
				],
			]);

			$tempFilename = \tempnam($this->tempDir, "html");
			$this->application->onShutdown[] = function () use ($tempFilename) {
				if (\is_file($tempFilename)) {
					\unlink($tempFilename);
				}
			};

			$zip = new \ZipArchive();

			$zipFilename = \tempnam($this->tempDir, "zip");

			if ($zip->open($zipFilename, \ZipArchive::CREATE) !== TRUE) {
				exit("cannot open <$zipFilename>\n");
			}

			FileSystem::write($tempFilename, $html);

			$zip->addFile($tempFilename, 'newsletter.html');

			$zip->close();

			$this->getPresenter()->application->onShutdown[] = function () use ($zipFilename) {
				\unlink($zipFilename);
			};

			$this->sendResponse(new FileResponse($zipFilename, "newsletter.zip", 'application/zip'));
		};

		return $form;
	}

	public function actionJoinSelect(array $ids)
	{

	}

	public function renderJoinSelect(array $ids)
	{
		$this->template->headerLabel = 'Sloučení produktů';
		$this->template->headerTree = [
			['Produkty', 'default'],
			['Sloučení produktů']
		];
		$this->template->displayButtons = [$this->createBackButton('default')];
		$this->template->displayControls = [$this->getComponent('joinForm')];
	}

	public function createComponentJoinForm(): AdminForm
	{
		$ids = $this->getParameter('ids') ?: [];

		$form = $this->formFactory->create();
		$form->setAction($this->link('this', ['selected' => $this->getParameter('selected')]));

		$mutationSuffix = $this->productRepository->getConnection()->getMutationSuffix();

		$form->addRadioList('mainProduct',
			'Hlavní produkt',
			$this->productRepository->many()
				->where('this.uuid', $ids)
				->select(['customName' => "CONCAT(this.name$mutationSuffix, ' (', this.code, ')')"])
				->toArrayOf('customName'))
			->setRequired();

		$form->addSubmit('submit', 'Uložit');

		$form->onSuccess[] = function (AdminForm $form) use ($ids) {
			$values = $form->getValues('array');

			/** @var Product[] $products */
			$products = $this->productRepository->many()->where('this.uuid', $ids)->whereNot('this.uuid', $values['mainProduct'])->toArray();

			$error1 = false;
			$error2 = false;

			foreach ($products as $product) {
				try {
					$this->supplierProductRepository->many()
						->where('fk_product', $product->getPK())
						->update(['fk_product' => $values['mainProduct']]);
				} catch (\Exception $e) {
					bdump($e);

					$error1 = 'Některé produkty již mají namapovaného stejného dodavatele! Mapování těchto dodavatelů nebylo změněno.';
				}

				try {
					$product->delete();
				} catch (\Exception $e) {
					bdump($e);

					$error2 = 'Některé produkty nebyly smazány! Smazání pravděpodobně blokují vazby s jinými produkty.';
				}
			}

			if ($error1) {
				$this->flashMessage($error1, 'warning');
			}

			if ($error2) {
				$this->flashMessage($error2, 'warning');
			}

			$this->flashMessage('Provedeno', 'success');
			$this->redirect('default');
		};

		return $form;
	}

	public function renderImportCsv()
	{
		$this->template->headerLabel = 'Import zdrojového souboru';
		$this->template->headerTree = [
			['Produkty', 'default'],
			['Import zdrojového souboru']
		];
		$this->template->displayButtons = [$this->createBackButton('default')];
		$this->template->displayControls = [$this->getComponent('importCsvForm')];
	}

	public function createComponentImportCsvForm(): AdminForm
	{
		$form = $this->formFactory->create();

		$lastUpdate = null;
		$path = \dirname(__DIR__, 5) . '/userfiles/products.csv';

		if (\file_exists($path)) {
			$lastUpdate = \filemtime($path);
		}

		$form->addText('lastProductFileUpload', 'Poslední aktualizace souboru')->setDisabled()->setDefaultValue($lastUpdate ? \date('d.m.Y G:i', $lastUpdate) : null);

		$allowedColumns = '';

		foreach (static::CONFIGURATION['importColumns'] as $key => $value) {
			$allowedColumns .= "$key, $value<br>";
		}

		$filePicker = $form->addFilePicker('file', 'Soubor (CSV)')
			->setRequired()
			->addRule($form::MIME_TYPE, 'Neplatný soubor!', 'text/csv');

		if (isset(static::CONFIGURATION['importExampleFile']) && static::CONFIGURATION['importExampleFile']) {
			$filePicker->setHtmlAttribute('data-info', 'Vzorový soubor: <a href="' . $this->link('downloadImportExampleFile!') . '">' . static::CONFIGURATION['importExampleFile'] . '</a><br
>Podporuje <b>pouze</b> formátování Windows a Linux (UTF-8)!');
		}

		$form->addSelect('delimiter', 'Oddělovač', [
			';' => 'Středník (;)',
			',' => 'Čárka (,)',
			'	' => 'Tab (\t)',
			' ' => 'Mezera ( )',
			'|' => 'Pipe (|)',
		]);

		$form->addCheckbox('addNew', 'Vytvářet nové záznamy');
		$form->addCheckbox('overwriteExisting', 'Přepisovat existující záznamy')->setDefaultValue(true);
		$form->addCheckbox('updateAttributes', 'Aktualizovat atributy')->setHtmlAttribute('data-info', '<h5 class="mt-2">Nápověda</h5>
Soubor <b>musí obsahovat</b> hlavičku a jeden ze sloupců "Kód" nebo "EAN" pro jednoznačné rozlišení produktů. Jako prioritní se hledá kód a pokud není nalezen tak EAN. Kód a EAN se ukládají jen při vytváření nových záznamů.<br><br>
Povolené sloupce hlavičky (lze použít obě varianty kombinovaně):<br>
' . $allowedColumns . '<br>
Atributy a výrobce musí být zadány jako kód (např.: "001") nebo jako kombinace názvu a kódu(např.: "Tisková technologie#001).<br>
Hodnoty atributů, kategorie a skladové množství se zadávají ve stejném formátu jako atributy s tím že jich lze více oddělit pomocí ":". Např.: "Inkoustová#462:9549"<br>
<br>
<b>Pozor!</b> Pokud pracujete se souborem na zařízeních Apple, ujistětě se, že vždy při ukládání použijete možnost uložit do formátu Windows nebo Linux (UTF-8)!');

		$form->addSubmit('submit', 'Importovat');

		$form->onValidate[] = function (AdminForm $form) {
			$values = $form->getValues('array');

			/** @var FileUpload $file */
			$file = $values['file'];

			if (!$file->hasFile()) {
				$form['file']->addError('Neplatný soubor!');
			}
		};

		$form->onSuccess[] = function (AdminForm $form) {
			$values = $form->getValues('array');

			/** @var FileUpload $file */
			$file = $values['file'];

			$file->move(\dirname(__DIR__, 5) . '/userfiles/products.csv');
			\touch(\dirname(__DIR__, 5) . '/userfiles/products.csv');

			$connection = $this->productRepository->getConnection();

			$connection->getLink()->beginTransaction();

			try {
				$this->importCsv(\dirname(__DIR__, 5) . '/userfiles/products.csv',
					$values['delimiter'],
					$values['addNew'],
					$values['overwriteExisting'],
					$values['updateAttributes'],
				);

				$connection->getLink()->commit();
				$this->flashMessage('Provedeno', 'success');
			} catch (\Exception $e) {
				FileSystem::delete(\dirname(__DIR__, 5) . '/userfiles/products.csv');
				$connection->getLink()->rollBack();

				$this->flashMessage($e->getMessage() != '' ? $e->getMessage() : 'Import dat se nezdařil!', 'error');
			}

			$this->redirect('this');
		};

		return $form;
	}

	public function actionExport(array $ids)
	{

	}

	public function renderExport(array $ids)
	{
		$this->template->headerLabel = 'Export produktů do CSV';
		$this->template->headerTree = [
			['Produkty', 'default'],
			['Export produktů']
		];
		$this->template->displayButtons = [$this->createBackButton('default')];
		$this->template->displayControls = [$this->getComponent('exportForm')];
	}

	public function createComponentExportForm()
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
			'	' => 'Tab (\t)',
			' ' => 'Mezera ( )',
			'|' => 'Pipe (|)',
		]);
		$form->addCheckbox('header', 'Hlavička')->setDefaultValue(true)->setHtmlAttribute('data-info', 'Pokud tuto možnost nepoužijete tak nebude možné tento soubor použít pro import!');

		$headerColumns = $form->addDataMultiSelect('columns', 'Sloupce');
		$attributesColumns = $form->addDataMultiSelect('attributes', 'Atributy')->setHtmlAttribute('data-info', 'Zobrazují se pouze atributy, které mají alespoň jeden přiřazený produkt.');

		$items = [];
		$defaultItems = [];

		if (isset(static::CONFIGURATION['exportColumns'])) {
			$items += static::CONFIGURATION['exportColumns'];

			if (isset(static::CONFIGURATION['defaultExportColumns'])) {
				$defaultItems = \array_merge($defaultItems, static::CONFIGURATION['defaultExportColumns']);
			}
		}

		$headerColumns->setItems($items);
		$headerColumns->setDefaultValue($defaultItems);

		$attributes = [];
		$defaultAttributes = [];

		if (isset(static::CONFIGURATION['exportAttributes'])) {
			foreach (static::CONFIGURATION['exportAttributes'] as $key => $value) {
				if ($attribute = $this->attributeRepository->many()->where('code', $key)->first()) {
					$attributes[$attribute->getPK()] = "$value#$key";
					$defaultAttributes[] = $attribute->getPK();
				}
			}

			$attributes += $this->attributeRepository->many()
				->whereNot('this.code', \array_keys(static::CONFIGURATION['exportAttributes']))
				->join(['attributeValue' => 'eshop_attributevalue'], 'this.uuid = attributeValue.fk_attribute')
				->join(['assign' => 'eshop_attributeassign'], 'attributeValue.uuid = assign.fk_value')
				->where('assign.uuid IS NOT NULL')
				->where('this.hidden', false)
				->orderBy(["this.name$mutationSuffix"])
				->select(['nameAndCode' => "CONCAT(this.name$mutationSuffix, '#', this.code)"])
				->toArrayOf('nameAndCode');
		}

		$attributesColumns->setItems($attributes);
		$attributesColumns->setDefaultValue($defaultAttributes);

		$form->addSubmit('submit', 'Exportovat');

		$form->onValidate[] = function (AdminForm $form) use ($ids, $productGrid, $items, $attributes) {
			$values = $form->getValues();

			if (!Arrays::contains($values['columns'], 'code') && !Arrays::contains($values['columns'], 'ean')) {
				$form['columns']->addError('Je nutné vybrat "Kód" nebo "EAN" pro jednoznačné označení produktu.');
			}
		};

		$form->onSuccess[] = function (AdminForm $form) use ($ids, $productGrid, $items, $attributes) {
			$values = $form->getValues('array');

			$products = $values['bulkType'] == 'selected' ? $this->productRepository->many()->where('this.uuid', $ids) : $productGrid->getFilteredSource();

			$tempFilename = \tempnam($this->tempDir, "csv");

			$headerColumns = \array_filter($items, function ($item) use ($values) {
				return \in_array($item, $values['columns']);
			}, ARRAY_FILTER_USE_KEY);

			$attributeColumns = \array_filter($attributes, function ($item) use ($values) {
				return \in_array($item, $values['attributes']);
			}, ARRAY_FILTER_USE_KEY);

			$this->productRepository->csvExport(
				$products,
				Writer::createFromPath($tempFilename),
				$headerColumns,
				$attributeColumns,
				$values['delimiter'],
				$values['header'] ? \array_merge(\array_values($headerColumns), \array_values($attributeColumns)) : null
			);

			$this->getPresenter()->sendResponse(new FileResponse($tempFilename, "products.csv", 'text/csv'));
		};

		return $form;
	}

	protected function importCsv(string $filePath, string $delimiter = ';', bool $addNew = false, bool $overwriteExisting = true, bool $updateAttributes = false)
	{
		if (!\ini_get("auto_detect_line_endings")) {
			\ini_set("auto_detect_line_endings", '1');
		}

		$csvData = FileSystem::read($filePath);

		$detector = new EncodingDetector();

		$detector->disableEncoding([
			EncodingDetector::ISO_8859_5,
			EncodingDetector::KOI8_R
		]);

		$encoding = $detector->getEncoding($csvData);

		if ($encoding !== 'utf-8') {
			$csvData = \iconv('windows-1250', 'utf-8', $csvData);
			$reader = Reader::createFromString($csvData);
			unset($csvData);
		} else {
			unset($csvData);
			$reader = Reader::createFromPath($filePath);
		}

		$reader->setDelimiter($delimiter);
		$reader->setHeaderOffset(0);
		$mutation = $this->productRepository->getConnection()->getMutation();
		$mutationSuffix = $this->productRepository->getConnection()->getMutationSuffix();

		$producers = $this->producerRepository->many()->setIndex('code')->toArrayOf('uuid');
		$stores = $this->storeRepository->many()->setIndex('code')->toArrayOf('uuid');
		$categories = $this->categoryRepository->many()->toArrayOf('uuid');
		$categoriesCodes = $this->categoryRepository->many()->setIndex('code')->toArrayOf('uuid');

		$header = $reader->getHeader();
		$parsedHeader = [];

		foreach ($header as $headerItem) {
			if (isset(static::CONFIGURATION['importColumns'][$headerItem])) {
				$parsedHeader[$headerItem] = $headerItem;
			} elseif ($key = \array_search($headerItem, static::CONFIGURATION['importColumns'])) {
				$parsedHeader[$key] = $headerItem;
			} else {
				if (Strings::contains($headerItem, '#')) {
					$attributeCode = \explode('#', $headerItem);

					if (\count($attributeCode) != 2) {
						continue;
					}

					$attributeCode = $attributeCode[1];
				} else {
					$attributeCode = $headerItem;
				}

				if ($attribute = $this->attributeRepository->many()->where("code", $attributeCode)->first()) {
					$attributes[$attribute->getPK()] = $attribute;
					$parsedHeader[$attribute->getPK()] = $headerItem;
				}
			}
		}

		if (\count($parsedHeader) == 0) {
			throw new \Exception('Soubor neobsahuje hlavičku nebo nebyl nalezen žádný použitelný sloupec!');
		}

		if (!isset($parsedHeader['code']) && !isset($parsedHeader['ean'])) {
			throw new \Exception('Soubor neobsahuje kód ani EAN!');
		}

		foreach ($reader->getRecords() as $record) {
			$newValues = [];
			$product = null;
			$expression = new Expression();
			$code = null;
			$ean = null;

			if (isset($parsedHeader['code']) && ($code = Arrays::pick($record, $parsedHeader['code'], null))) {
				$codeBase = Strings::trim($code);
				$codePrefix = Strings::trim('00' . $code);

				$expression->add('OR', 'code = %s OR CONCAT(code,".",subCode) = %s', [$codeBase, $codeBase]);
				$expression->add('OR', 'code = %s OR CONCAT(code,".",subCode) = %s', [$codePrefix, $codePrefix]);

				$code = $codeBase;
			}

			if (isset($parsedHeader['ean']) && ($ean = Arrays::pick($record, $parsedHeader['ean'], null))) {
				$expression->add('OR', 'ean = %s', [Strings::trim($ean)]);

				$ean = Strings::trim($ean);
			}

			$product = $this->productRepository->many()->where($expression->getSql(), $expression->getVars())->first();

			if ((!$code && !$ean) || (!$product && !$addNew) || ($product && !$overwriteExisting)) {
				continue;
			}

			foreach ($record as $key => $value) {
				$key = \array_search($key, $parsedHeader);

				if (!$key) {
					continue;
				}

				if ($key == 'producer') {
					if (Strings::contains($value, '#')) {
						$producerCode = \explode('#', $value);

						if (\count($producerCode) != 2) {
							continue;
						}

						$producerCode = $producerCode[1];
					} else {
						$producerCode = $value;
					}

					if (isset($producers[$producerCode])) {
						$newValues[$key] = $producers[$producerCode];
					}
				} elseif ($key == 'storeAmount') {
					$amounts = \explode(':', $value);

					foreach ($amounts as $amount) {
						$amount = \explode('#', $amount);

						if (\count($amount) != 2) {
							continue;
						}

						if (!isset($stores[$amount[1]])) {
							continue;
						}

						$this->amountRepository->syncOne([
							'store' => $stores[$amount[1]],
							'product' => $product->getPK(),
							'inStock' => \intval($amount[0])
						]);
					}
				} elseif ($key == 'categories') {
					$this->categoryRepository->getConnection()->rows(['eshop_product_nxn_eshop_category'])
						->where('fk_product', $product->getPK())
						->delete();

					$valueCategories = \explode(':', $value);

					foreach ($valueCategories as $category) {
						$category = \explode('#', $category);

						if (\count($category) != 2) {
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

						$this->categoryRepository->getConnection()->createRow('eshop_product_nxn_eshop_category', [
							'fk_product' => $product->getPK(),
							'fk_category' => $category
						]);
					}
				} elseif ($key == 'name' || $key == 'perex' || $key == 'content') {
					$newValues[$key][$mutation] = $value;
				} elseif ($key == 'priority') {
					$newValues[$key] = \intval($value);
				} elseif ($key == 'recommended' || $key == 'hidden' || $key == 'unavailable') {
					$newValues[$key] = $value == '1';
				} elseif (!isset($attributes[$key])) {
					$newValues[$key] = $value;
				}
			}

			try {
				if ($product) {
					if (\count($newValues) > 0) {
						$newValues['uuid'] = $product->getPK();

						if (isset($newValues['name'][$mutation]) && $newValues['name'][$mutation] != $product->name) {
							$newValues['supplierContentLock'] = true;
						}

						if (isset($newValues['perex'][$mutation]) && $newValues['perex'][$mutation] != $product->perex) {
							$newValues['supplierContentLock'] = true;
						}

						$product->update($newValues);
					}
				} elseif (\count($newValues) > 0) {
					if (!$code && !$ean) {
						continue;
					}

					$newValues['ean'] = $ean;
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
					->where('this.fk_product', $product->getPK())
					->where('eshop_attributevalue.fk_attribute', $key)
					->delete();

				$attributeValues = Strings::contains($value, ':') ? \explode(':', $value) : [$value];

				foreach ($attributeValues as $attributeString) {
					if (Strings::contains($attributeString, '#')) {
						$attributeValueCode = \explode('#', $attributeString);

						if (\count($attributeValueCode) != 2) {
							continue;
						}

						$attributeValueCode = $attributeValueCode[1];
					} else {
						$attributeValueCode = $attributeString;
					}

					if ($attributeValue = $this->attributeValueRepository->many()->where("code", $attributeValueCode)->firstValue('uuid')) {
						$this->attributeAssignRepository->syncOne([
							'product' => $product->getPK(),
							'value' => $attributeValue
						]);

					}
				}
			}
		}
	}

	protected function importSetCsv(string $filePath, string $delimiter = ';', bool $addNew = false, bool $overwriteExisting = true, bool $autoSet = false)
	{
		if (!\ini_get("auto_detect_line_endings")) {
			\ini_set("auto_detect_line_endings", '1');
		}

		$csvData = FileSystem::read($filePath);

		$detector = new EncodingDetector();

		$detector->disableEncoding([
			EncodingDetector::ISO_8859_5,
			EncodingDetector::KOI8_R
		]);

		$encoding = $detector->getEncoding($csvData);

		if ($encoding !== 'utf-8') {
			$csvData = \iconv('windows-1250', 'utf-8', $csvData);
			$reader = Reader::createFromString($csvData);
			unset($csvData);
		} else {
			unset($csvData);
			$reader = Reader::createFromPath($filePath);
		}

		$reader->setDelimiter($delimiter);
		$reader->setHeaderOffset(0);
		$mutation = $this->productRepository->getConnection()->getMutation();
		$mutationSuffix = $this->productRepository->getConnection()->getMutationSuffix();

		$header = $reader->getHeader();
		$parsedHeader = [];

		foreach ($header as $headerItem) {
			if (isset($this::IMPORT_SET_COLUMNS[$headerItem])) {
				$parsedHeader[$headerItem] = $headerItem;
			} elseif ($key = \array_search($headerItem, $this::IMPORT_SET_COLUMNS)) {
				$parsedHeader[$key] = $headerItem;
			}
		}

		if (\count($parsedHeader) == 0) {
			throw new \Exception('Soubor neobsahuje hlavičku nebo nebyl nalezen žádný použitelný sloupec!');
		}

		if (!isset($parsedHeader['setCode']) && !isset($parsedHeader['setEan'])) {
			throw new \Exception('Soubor neobsahuje kód ani EAN setu!');
		}

		if (!isset($parsedHeader['productCode']) && !isset($parsedHeader['productEan'])) {
			throw new \Exception('Soubor neobsahuje kód ani EAN produktu!');
		}

		foreach ($reader->getRecords() as $record) {
			$newValues = [];
			$setProduct = null;
			$expression = new Expression();

			if (isset($parsedHeader['setCode']) && ($code = Arrays::pick($record, $parsedHeader['setCode'], null))) {
				$codeBase = Strings::trim($code);
				$codePrefix = Strings::trim('00' . $code);

				$expression->add('OR', 'code = %s OR CONCAT(code,".",subCode) = %s', [$codeBase, $codeBase]);
				$expression->add('OR', 'code = %s OR CONCAT(code,".",subCode) = %s', [$codePrefix, $codePrefix]);
			}

			if (isset($parsedHeader['setEan']) && ($ean = Arrays::pick($record, $parsedHeader['setEan'], null))) {
				$expression->add('OR', 'ean = %s', [Strings::trim($ean)]);
			}

			/** @var Product $setProduct */
			if (!$setProduct = $this->productRepository->many()->where($expression->getSql(), $expression->getVars())->first()) {
				continue;
			}

			if (!$setProduct->productsSet) {
				if ($autoSet) {
					$setProduct->update(['productsSet' => true]);
				} else {
					continue;
				}
			}

			$product = null;

			foreach ($record as $key => $value) {
				$key = \array_search($key, $parsedHeader);

				if (!$key) {
					continue;
				}

				if ($key == 'productCode' || $key == 'productEan') {
					if ($product) {
						continue;
					}

					$product = $this->productRepository->getProductByCodeOrEAN($value);
				} elseif ($key == 'discountPct') {
					$newValues[$key] = NumbersHelper::strToFloat($value);
				} elseif ($key == 'amount' || $key == 'priority') {
					$newValues[$key] = \intval($value);
				}
			}

			if (!$product || !isset($newValues['amount'])) {
				continue;
			}

			$set = $this->setRepository->many()
				->where('fk_set', $setProduct->getPK())
				->where('fk_product', $product->getPK())
				->first();

			if ((!$set && !$addNew) || ($set && !$overwriteExisting)) {
				continue;
			}

			try {
				if (\count($newValues) > 0) {
					if ($set) {
						$set->update($newValues);
					} else {
						$newValues['set'] = $setProduct->getPK();
						$newValues['product'] = $product->getPK();

						$this->setRepository->createOne($newValues);
					}
				}
			} catch (\Exception $e) {
				throw new \Exception('Chyba při zpracování dat!');
			}
		}
	}

	public function handleDownloadImportExampleFile()
	{
		if (isset(static::CONFIGURATION['importExampleFile']) && static::CONFIGURATION['importExampleFile']) {
			$this->getPresenter()->sendResponse(new FileResponse($this->wwwDir . '/userfiles/' . static::CONFIGURATION['importExampleFile'], "example.csv", 'text/csv'));
		}
	}

	public function handleDownloadImportSetExampleFile()
	{
		if (isset(static::CONFIGURATION['importSetExampleFile']) && static::CONFIGURATION['importSetExampleFile']) {
			$this->getPresenter()->sendResponse(new FileResponse($this->wwwDir . '/userfiles/' . static::CONFIGURATION['importSetExampleFile'], "example_set.csv", 'text/csv'));
		}
	}

	public function actionGenerateRandomBuyCounts(array $ids)
	{

	}

	public function renderGenerateRandomBuyCounts(array $ids)
	{
		$this->template->headerLabel = 'Generovat náhodný počet zakoupení';
		$this->template->headerTree = [
			['Produkty', 'default'],
			['Generovat náhodný počet zakoupení']
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

		$form->onSuccess[] = function (AdminForm $form) use ($ids, $productGrid) {
			$values = $form->getValues('array');

			$products = $values['bulkType'] == 'selected' ? $this->productRepository->many()->where('this.uuid', $ids) : $productGrid->getFilteredSource();

			foreach ($products as $product) {
				$product->update(['buyCount' => \rand($values['from'], $values['to'])]);
			}

			$this->flashMessage('Provedeno', 'success');
			$this->redirect('default');
		};

		return $form;
	}

	public function handleMakePhotoPrimary(Photo $photo)
	{
		$subDirs = ['origin', 'detail', 'thumb'];
		$imageDir = $this->wwwDir . '/' . 'userfiles' . '/' . Product::IMAGE_DIR;
		$galleryDir = $this->wwwDir . '/' . 'userfiles' . '/' . Product::GALLERY_DIR;

		$tempFilename = Random::generate();

		foreach ($subDirs as $subDir) {
			FileSystem::rename($imageDir . '/' . $subDir . '/' . $photo->product->imageFileName, $galleryDir . '/' . $subDir . '/' . $tempFilename);
			FileSystem::rename($galleryDir . '/' . $subDir . '/' . $photo->fileName, $imageDir . '/' . $subDir . '/' . $photo->product->imageFileName);
			FileSystem::rename($galleryDir . '/' . $subDir . '/' . $tempFilename, $galleryDir . '/' . $subDir . '/' . $photo->fileName);
		}

		$this->flashMessage('Provedeno', 'success');
		$this->redirect('this');
	}

	public function actionComments(Product $product)
	{
		$this->template->headerLabel = 'Komentáře - ' . $product->name;
		$this->template->headerTree = [
			['Produkty', 'default'],
			['Komentáře']
		];
		$this->template->displayButtons = [$this->createBackButton('default')];
	}

	public function renderComments(Product $product)
	{
		$this->template->comments = $this->commentRepository->many()->where('fk_product', $product->getPK())->orderBy(['createdTs' => 'DESC'])->toArray();
		$this->template->setFile(__DIR__ . '/templates/comments.latte');
	}

	public function createComponentNewComment()
	{

		$form = $this->formFactory->create(true, false, false, false, false);

		$form->addGroup('Nový komentář');
		$form->addTextArea('text', 'Komentáře');

		$form->addSubmit('send', 'Odeslat');

		$form->onSuccess[] = function (Form $form) {
			$values = $form->getValues('array');

			$data = [
				'product' => $this->getParameter('product')->getPK(),
				'text' => $values['text'],
				'administrator' => $this->admin->getIdentity()->getPK(),
				'adminFullname' => $this->admin->getIdentity()->fullName ?? ($this->admin->getIdentity()->getAccount() ? ($this->admin->getIdentity()->getAccount()->fullname ?? $this->admin->getIdentity()->getAccount()->login) : null)
			];

			$this->commentRepository->createOne($data);

			$this->flashMessage('Uloženo', 'success');
			$this->redirect('edit', ['product' => $this->getParameter('product'), 'editTab' => 'comments']);
		};

		return $form;
	}

	public function renderImportSetCsv()
	{
		$this->template->headerLabel = 'Import zdrojového souboru';
		$this->template->headerTree = [
			['Produkty', 'default'],
			['Import zdrojového souboru']
		];
		$this->template->displayButtons = [$this->createBackButton('default')];
		$this->template->displayControls = [$this->getComponent('importSetCsvForm')];
	}

	public function createComponentImportSetCsvForm(): AdminForm
	{
		$form = $this->formFactory->create();

		$lastUpdate = null;
		$path = \dirname(__DIR__, 5) . '/userfiles/sets.csv';

		if (\file_exists($path)) {
			$lastUpdate = \filemtime($path);
		}

		$form->addText('lastProductFileUpload', 'Poslední aktualizace souboru')->setDisabled()->setDefaultValue($lastUpdate ? \date('d.m.Y G:i', $lastUpdate) : null);

		$allowedColumns = '';

		foreach ($this::IMPORT_SET_COLUMNS as $key => $value) {
			$allowedColumns .= "$key, $value<br>";
		}

		$filePicker = $form->addFilePicker('file', 'Soubor (CSV)')
			->setRequired()
			->addRule($form::MIME_TYPE, 'Neplatný soubor!', 'text/csv');

		if (isset(static::CONFIGURATION['importSetExampleFile']) && static::CONFIGURATION['importSetExampleFile']) {
			$filePicker->setHtmlAttribute('data-info', 'Vzorový soubor: <a href="' . $this->link('downloadImportSetExampleFile!') . '">' . static::CONFIGURATION['importSetExampleFile'] . '</a><br
>Podporuje <b>pouze</b> formátování Windows a Linux (UTF-8)!');
		}

		$form->addSelect('delimiter', 'Oddělovač', [
			';' => 'Středník (;)',
			',' => 'Čárka (,)',
			'	' => 'Tab (\t)',
			' ' => 'Mezera ( )',
			'|' => 'Pipe (|)',
		]);

		$form->addCheckbox('autoSet', 'Automaticky převést na set')->setHtmlAttribute('data-info', 'Pokud zaškrtnete, tak se produkty, které nejsou označeny jako set automaticky označí. V opačném případě bude takový záznam přeskočen.');
		$form->addCheckbox('addNew', 'Vytvářet nové záznamy')->setDefaultValue(true);
		$form->addCheckbox('overwriteExisting', 'Přepisovat existující záznamy')->setDefaultValue(true)->setHtmlAttribute('data-info', '<h5 class="mt-2">Nápověda</h5>
Soubor <b>musí obsahovat</b> hlavičku, jeden ze sloupců "Kód setu" nebo "EAN setu" resp. "Kód produktu" nebo "EAN produktu" pro jednoznačné rozlišení setů a produktů a sloupec Množství. Jako prioritní se hledá kód a pokud není nalezen tak EAN.<br><br>
Povolené sloupce hlavičky (lze použít obě varianty kombinovaně):<br>
' . $allowedColumns . '<br>
<br>
<b>Pozor!</b> Pokud pracujete se souborem na zařízeních Apple, ujistětě se, že vždy při ukládání použijete možnost uložit do formátu Windows nebo Linux (UTF-8)!');;

		$form->addSubmit('submit', 'Importovat');

		$form->onValidate[] = function (AdminForm $form) {
			$values = $form->getValues('array');

			/** @var FileUpload $file */
			$file = $values['file'];

			if (!$file->hasFile()) {
				$form['file']->addError('Neplatný soubor!');
			}
		};

		$form->onSuccess[] = function (AdminForm $form) {
			$values = $form->getValues('array');

			/** @var FileUpload $file */
			$file = $values['file'];

			$file->move(\dirname(__DIR__, 5) . '/userfiles/sets.csv');
			\touch(\dirname(__DIR__, 5) . '/userfiles/sets.csv');

			$connection = $this->productRepository->getConnection();

			$connection->getLink()->beginTransaction();

			try {
				$this->importSetCsv(\dirname(__DIR__, 5) . '/userfiles/sets.csv',
					$values['delimiter'],
					$values['addNew'],
					$values['overwriteExisting'],
					$values['autoSet']
				);

				$connection->getLink()->commit();
				$this->flashMessage('Provedeno', 'success');
			} catch (\Exception $e) {
				FileSystem::delete(\dirname(__DIR__, 5) . '/userfiles/sets.csv');
				$connection->getLink()->rollBack();

				$this->flashMessage($e->getMessage() != '' ? $e->getMessage() : 'Import dat se nezdařil!', 'error');
			}

			$this->redirect('this');
		};

		return $form;
	}

	public function handleDropzoneRemovePhoto()
	{
		$filename = $this->getPresenter()->getParameter('file');

		/** @var Photo $photo */
		$photo = $this->photoRepository->many()->where('fileName', $filename)->first();

		if (!$photo) {
			return;
		}

		$basePath = $this->container->parameters['wwwDir'] . '/userfiles/' . Product::GALLERY_DIR;
		FileSystem::delete($basePath . '/origin/' . $photo->fileName);
		FileSystem::delete($basePath . '/detail/' . $photo->fileName);
		FileSystem::delete($basePath . '/thumb/' . $photo->fileName);

		if ($photo->product->imageFileName === $photo->fileName) {
			$photo->product->update(['imageFileName' => null]);
		}

		$photo->delete();
	}

	public function handleDropzoneUploadPhoto()
	{
		$this->createDirectories();

		/** @var Product $product */
		$product = $this->getParameter('product');

		/** @var FileUpload $fileUpload */
		$fileUpload = $this->getPresenter()->getHttpRequest()->getFile('file');
		$uuid = Connection::generateUuid();
		$filename = $uuid . '.' . $fileUpload->getImageFileExtension();

		/** @var Photo $photo */
		$photo = $this->photoRepository->createOne([
			'uuid' => $uuid,
			'product' => $product->getPK(),
			'fileName' => $filename,
			'priority' => 999
		]);

		$basePath = $this->container->parameters['wwwDir'] . '/userfiles/' . Product::GALLERY_DIR;

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

	public function handleDropzoneSetMain(?string $filename)
	{
		if (!$filename) {
			return;
		}

		/** @var Photo $photo */
		$photo = $this->photoRepository->many()->where('filename', $filename)->first();

		if (!$photo) {
			return;
		}

		$photo->product->update(['imageFileName' => $filename]);

		$this->redirect('this');
	}

	public function handleDropzoneSetOrder()
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
}
