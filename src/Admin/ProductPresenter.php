<?php

declare(strict_types=1);

namespace Eshop\Admin;

use Admin\BackendPresenter;
use Admin\Controls\AdminForm;
use Eshop\Admin\Controls\IProductAttributesFormFactory;
use Eshop\Admin\Controls\IProductFormFactory;
use Eshop\Admin\Controls\IProductParametersFormFactory;
use Eshop\Admin\Controls\ProductGridFactory;
use Eshop\DB\AttributeRepository;
use Eshop\DB\File;
use Eshop\DB\FileRepository;
use Eshop\DB\NewsletterTypeRepository;
use Eshop\DB\ParameterRepository;
use Eshop\DB\ParameterValueRepository;
use Eshop\DB\Photo;
use Eshop\DB\PhotoRepository;
use Eshop\DB\Pricelist;
use Eshop\DB\PricelistRepository;
use Eshop\DB\PriceRepository;
use Eshop\DB\Product;
use Eshop\DB\ProductRepository;
use Eshop\DB\SetRepository;
use Eshop\DB\SupplierProductRepository;
use Eshop\DB\VatRateRepository;
use Eshop\FormValidators;
use Eshop\Shopper;
use Forms\Form;
use League\Csv\Writer;
use Nette\Application\Responses\FileResponse;
use Nette\Forms\Controls\TextInput;
use Nette\Http\FileUpload;
use Nette\InvalidArgumentException;
use Nette\Utils\FileSystem;
use Nette\Utils\Image;
use Pages\DB\PageRepository;
use StORM\DIConnection;
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
			'name' => 'Název',
			'perex' => 'Popisek',
			'priority' => 'Priorita',
			'recommended' => 'Doporučeno',
			'hidden' => 'Skryto'
		],
		'exportAttributes' => [],
		'defaultExportColumns' => [
			'code'
		],
		'defaultExportAttributes' => [],
		'importColumns' => [
			'code' => 'Kód',
			'name' => 'Název',
			'perex' => 'Popisek',
			'priority' => 'Priorita',
			'recommended' => 'Doporučeno',
			'hidden' => 'Skryto'
		],
		'importAttributes' => [],
		'importExampleFile' => null
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

	public function createComponentProductGrid()
	{
		return $this->productGridFactory->create(static::CONFIGURATION);
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
		$grid->addColumnInputInteger('Priorita', 'priority', '', '', 'priority', [], true);
		$grid->addColumnInputCheckbox('<i title="Skryto" class="far fa-eye-slash"></i>', 'hidden', '', '', 'hidden');
		$grid->addColumnLinkDetail('detailPhoto');
		$grid->addColumnActionDelete([$this, 'deleteGalleryPhoto']);
		$grid->addFilterTextInput('search', ['fileName'], null, 'Název');
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

		$grid->addFilterTextInput('search', ['fileName'], null, 'Jmébo souboru');
		$grid->addFilterButtons(['productFiles', $this->getParameter('product')]);

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
		$form = $this->formFactory->create(true);

		if (!$this->getParameter('file')) {
			$form->addFilePicker('fileName', 'Vybrat soubor', \DIRECTORY_SEPARATOR . Product::FILE_DIR)->setRequired();
		}

		$form->addLocaleText('label', 'Popisek');
		$form->addInteger('priority', 'Priorita')->setDefaultValue(10);
		$form->addCheckbox('hidden', 'Skryto');

		$form->addHidden('product', (string)$this->getParameter('product'));

		$form->addSubmits(false, false);

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
			$this->redirect('files', [new Product(['uuid' => $values['product']])]);
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
		return $this->productAttributesFormFactory->create($this->getParameter('product'));
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
			['Soubory', 'files', $file->product],
			['Detail'],
		];
		$this->template->displayButtons = [$this->createBackButton('files', $file->product)];
		$this->template->displayControls = [$this->getComponent('fileForm')];
	}

	public function renderNewFile(Product $product)
	{
		$this->template->headerLabel = 'Nová položka';
		$this->template->headerTree = [
			['Produkty', 'default'],
			['Soubory', 'files', $product],
			['Nová položka'],
		];
		$this->template->displayButtons = [$this->createBackButton('files', $product)];
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
		$this->template->displayButtons = [$this->createBackButton('photos', $photo->product)];
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

		$form->setDefaults($product->toArray(['categories', 'tags', 'ribbons', 'parameterGroups', 'taxes']));
		$form['alternative']->setDefaultValue($product->alternative ? $product->alternative->getFullCode() : null);

		if (isset($form['upsells'])) {
			$upsells = [];
			foreach ($product->upsells as $upsell) {
				$upsells[] = $upsell->getFullCode() ?? $upsell->ean;
			}

			$form['upsells']->setDefaultValue(\implode(';', $upsells));
		}

		if (isset($form['tonerForPrinters'])) {
			try {
				$form['tonerForPrinters']->setDefaultValue($this->productRepository->getSlaveProductsByRelationAndMaster('tonerForPrinter', $product)->setSelect(['this.uuid'])->toArray());
			} catch (InvalidArgumentException $e) {
				$form['tonerForPrinters']->setHtmlAttribute('data-error', 'Byla detekována chybná vazba! Vyberte, prosím, tiskárny znovu.');
			}
		}

		if ($product->supplierContentLock) {
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
		$this->template->displayButtons = [$this->createBackButton('default'), $this->createNewItemButton('newPhoto', [$product])];
		$this->template->displayControls = [$this->getComponent('photoGrid')];
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
		$this->template->headerLabel = 'Produkty';
		$this->template->headerTree = [
			['Produkty'],
		];
		$this->template->displayButtons = [$this->createNewItemButton('new')];

		if (isset(static::CONFIGURATION['importButton']) && static::CONFIGURATION['importButton']) {
			$this->template->displayButtons[] = $this->createButton('importCsv', '<i class="fas fa-file-upload mr-1"></i>Import');
		}

		$this->template->displayControls = [$this->getComponent('productGrid')];
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

	public function createComponentJoinForm()
	{
		/** @var \Grid\Datagrid $productGrid */
		$productGrid = $this->getComponent('productGrid');

		$ids = $this->getParameter('ids') ?: [];

		$form = $this->formFactory->create();
		$form->setAction($this->link('this', ['selected' => $this->getParameter('selected')]));

		$form->addRadioList('mainProduct', 'Hlavní produkt', $this->productRepository->many()->where('this.uuid', $ids)->toArrayOf('name'))->setRequired();

		$form->addSubmit('submit', 'Uložit');

		$form->onSuccess[] = function (AdminForm $form) use ($ids, $productGrid) {
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
					$error1 = 'Některé produkty již mají namapovaného stejného dodavatele! Mapování těchto dodavatelů nebylo změněno.';
				}

				try {
					$product->delete();
				} catch (\Exception $e) {
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

		$filePicker = $form->addFilePicker('file', 'Soubor (CSV)')
			->setRequired()
			->addRule($form::MIME_TYPE, 'Neplatný soubor!', 'text/csv');

		if (isset(static::CONFIGURATION['importExampleFile']) && static::CONFIGURATION['importExampleFile']) {
			$filePicker->setHtmlAttribute('data-info', 'Vzorový soubor: <a href="' . $this->link('downloadImportExampleFile!') . '">' . static::CONFIGURATION['importExampleFile'] . '</a>');
		}

		$form->addSelect('delimiter', 'Oddělovač', [
			';' => 'Středník (;)',
			',' => 'Čárka (,)',
			'	' => 'Tab (\t)',
			' ' => 'Mezera ( )',
			'|' => 'Pipe (|)',
		]);

		$form->addCheckbox('addNew', 'Vytvářet nové záznamy');

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
				$this->importCsv(\dirname(__DIR__, 5) . '/userfiles/products.csv', $values['delimiter'], $values['addNew']);

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
		$form->addCheckbox('header', 'Hlavička')->setDefaultValue(true);

		$columns = $form->addDataMultiSelect('columns', 'Sloupce');

		$items = [];
		$defaultItems = [];

		if (isset(static::CONFIGURATION['exportColumns'])) {
			$items += static::CONFIGURATION['exportColumns'];

			if (isset(static::CONFIGURATION['defaultExportColumns'])) {
				$defaultItems = \array_merge($defaultItems, static::CONFIGURATION['defaultExportColumns']);
			}
		}
		if (isset(static::CONFIGURATION['exportAttributes'])) {
			foreach (static::CONFIGURATION['exportAttributes'] as $key => $value) {
				if ($attribute = $this->attributeRepository->many()->where('code', $key)->first()) {
					$items[$key] = $value;
					$defaultItems[] = $key;
				}
			}
		}

		$columns->setItems($items);
		$columns->setDefaultValue($defaultItems);

		$form->addSubmit('submit', 'Exportovat');

		$form->onSuccess[] = function (AdminForm $form) use ($ids, $productGrid, $items) {
			$values = $form->getValues('array');

			$products = $values['bulkType'] == 'selected' ? $this->productRepository->many()->where('this.uuid', $ids) : $productGrid->getFilteredSource();

			$tempFilename = \tempnam($this->tempDir, "csv");

			$selectedColumns = \array_map('strval', $values['columns']);

			$columns = \array_filter($items, function ($key) use ($selectedColumns) {
				return \in_array((string)$key, $selectedColumns);
			}, ARRAY_FILTER_USE_KEY);

			$this->productRepository->csvExport(
				$products,
				Writer::createFromPath($tempFilename),
				static::CONFIGURATION,
				\array_keys($columns),
				$values['delimiter'],
				$values['header'] ? \array_values($columns) : null
			);

			$this->getPresenter()->sendResponse(new FileResponse($tempFilename, "products.csv", 'text/csv'));
		};

		return $form;
	}

	protected function importCsv(string $filePath, string $delimiter = ';', bool $addNew = false)
	{
		//@TODO implement
	}

	public function handleDownloadImportExampleFile()
	{
		if (isset(static::CONFIGURATION['importExampleFile']) && static::CONFIGURATION['importExampleFile']) {
			$this->getPresenter()->sendResponse(new FileResponse($this->wwwDir . '/userfiles/' . static::CONFIGURATION['importExampleFile'], "example.csv", 'text/csv'));
		}
	}
}
