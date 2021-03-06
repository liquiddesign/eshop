<?php

declare(strict_types=1);

namespace Eshop\Admin;

use Admin\BackendPresenter;
use App\Admin\Controls\AdminForm;
use App\Admin\Controls\AdminGrid;
use App\Admin\PresenterTrait;
use Eshop\Admin\Controls\IProductFormFactory;
use Eshop\Admin\Controls\IProductParametersFormFactory;
use Eshop\Admin\Controls\ProductGridFactory;
use Eshop\DB\File;
use Eshop\DB\FileRepository;
use Eshop\DB\ParameterRepository;
use Eshop\DB\ParameterValueRepository;
use Eshop\DB\Photo;
use Eshop\DB\PhotoRepository;
use Eshop\DB\PricelistRepository;
use Eshop\DB\PriceRepository;
use Eshop\DB\Product;
use Eshop\DB\VatRateRepository;
use Forms\Form;
use Nette\Application\Responses\FileResponse;
use Nette\Forms\Controls\TextInput;
use Nette\Utils\ArrayHash;
use Nette\Utils\DateTime;
use Nette\Utils\FileSystem;
use Nette\Utils\Image;
use Nette\Utils\Random;
use Pages\DB\PageRepository;
use StORM\DIConnection;

class ProductPresenter extends BackendPresenter
{
	/** @inject */
	public ProductGridFactory $productGridFactory;

	/** @inject */
	public IProductFormFactory $productFormFatory;

	/** @inject */
	public IProductParametersFormFactory $productParametersFormFatory;

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

	public function createComponentProductGrid()
	{
		return $this->productGridFactory->create();
	}

	public function createComponentProductForm()
	{
		return $this->productFormFatory->create();
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
			->join(['rates' => 'eshop_vatRate'], 'rates.uuid = :rate AND rates.fk_country=this.fk_country', ['rate' => $product->vatRate]);
		
		
		$grid = $this->gridFactory->create($collection, 20, 'code', 'ASC');

		$grid->addColumnText('Kód', 'code', '%s', 'code');
		$grid->addColumnText('Ceník', 'name', '%s', 'name');
		$grid->addColumnText('Měna', 'currency.code', '%s', 'currency.code');
		$grid->addColumnInputPrice('Cena', 'price');
		$grid->addColumnInputPrice('Cena s DPH', 'priceVat');
		$grid->addColumnInputPrice('Původní', 'priceBefore');
		$grid->addColumnInputPrice('Původní s DPH', 'priceVatBefore');
		
		$submit = $grid->getForm()->addSubmit('submit', 'Uložit');
		$submit->setHtmlAttribute('class', 'btn btn-sm btn-primary');
		$submit->onClick[] = function ($button) use ($grid, $product) {
			foreach ($grid->getInputData() as $id => $data) {
				if (!isset($data['price'])) {
					continue;
				}

				$this->priceRepository->syncOne([
					'price' => (float)$data['price'],
					'priceVat' => isset($data['priceVat']) ? (float)$data['priceVat'] : null,
					'priceBefore' => isset($data['priceBefore']) ? (float)$data['priceBefore'] : null,
					'priceVatBefore' => isset($data['priceVatBefore']) ? (float)$data['priceVatBefore'] : null,
					'product' => $product,
					'pricelist' => $id,
				]);
			}

			$grid->getPresenter()->flashMessage('Uloženo', 'success');
			$grid->getPresenter()->redirect('this');
		};

		$grid->addFilterTextInput('search', ['code'], null, 'Kód ceníku');
		$grid->addFilterButtons(['productFiles', $this->getParameter('product')]);

		return $grid;
	}

	public function createComponentFileForm(): Form
	{
		$form = $this->formFactory->create();
		$form->addFilePicker('fileName', 'Vybrat soubor', \DIRECTORY_SEPARATOR . Product::FILE_DIR)->setRequired();
		
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

			$values['fileName'] = $form['fileName']->upload($values['uuid'] . '.%2$s');

			$file = $this->fileRepository->syncOne($values);

			$this->flashMessage('Uloženo', 'success');
			$form->processRedirect('fileDetail', 'files', [$file, $this->getParameter('product')], [$this->getParameter('product')]);
		};

		return $form;
	}

	public function createComponentPhotoForm(): Form
	{
		$form = $this->formFactory->create();

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
				FileSystem::delete($this->context->getParameters()['wwwDir'] . \DIRECTORY_SEPARATOR . 'userfiles' . \DIRECTORY_SEPARATOR . $key . \DIRECTORY_SEPARATOR . $photo->fileName);
			}
			
			$photo->delete();
			$this->redirect('productPhotos', $product);
		};*/

		$form->addInteger('priority', 'Priorita')->setDefaultValue(10);
		$form->addCheckbox('hidden', 'Skryto');
		$form->addHidden('product', (string)$this->getParameter('product'));

		$form->addSubmits(false, false);

		$form->onSuccess[] = function (AdminForm $form) {
			$values = $form->getValues('array');

			if (!$values['uuid']) {
				$values['uuid'] = DIConnection::generateUuid();
			}

			$values['fileName'] = $form['fileName']->upload($values['uuid'] . '.%2$s');

			$photo = $this->photoRepository->syncOne($values);

			$this->flashMessage('Uloženo', 'success');
			$form->processRedirect('photoDetail', 'photos', [$photo], [$this->getParameter('product')]);
		};

		return $form;
	}

	public function createComponentParameterForm()
	{
		return $this->productParametersFormFatory->create($this->getParameter('product'));
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

	public function actionEdit(Product $product)
	{
		/** @var \Forms\Form $form */
		$form = $this->getComponent('productForm')['form'];

		$prices = $this->pricelistRepository->getDefaultPricelists()
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
		
		$form->setDefaults($product->toArray(['categories', 'tags', 'ribbons', 'parameterGroups']));
		
		if ($page = $this->pageRepository->getPageByTypeAndParams('product_detail', null, ['product' => $product])) {
			$form['page']->setDefaults($page->toArray());
			
			$form['page']['url']->forAll(function (TextInput $text, $mutation) use ($page, $form) {
				$text->getRules()->reset();
				$text->addRule([$form, 'validateUrl'], 'URL již existuje', [$this->pageRepository, $mutation, $page->getPK()]);
			});
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
		$this->template->displayControls = [$this->getComponent('productGrid')];
	}

	public function deleteFile(File $file)
	{
		$dir = File::FILE_DIR;
		$rootDir = $this->context->parameters['wwwDir'] . \DIRECTORY_SEPARATOR . 'userfiles' . \DIRECTORY_SEPARATOR . $dir;
		FileSystem::delete($rootDir . \DIRECTORY_SEPARATOR . $file->fileName);
	}

	public function deleteGalleryPhoto(Photo $photo)
	{
		$subDirs = ['origin', 'detail', 'thumb'];
		$dir = Photo::IMAGE_DIR;

		foreach ($subDirs as $subDir) {
			$rootDir = $this->context->parameters['wwwDir'] . \DIRECTORY_SEPARATOR . 'userfiles' . \DIRECTORY_SEPARATOR . $dir;
			FileSystem::delete($rootDir . \DIRECTORY_SEPARATOR . $subDir . \DIRECTORY_SEPARATOR . $photo->fileName);
		}

		$photo->update(['fileName' => null]);
	}

	protected function createDirectories()
	{
		$subDirs = ['origin', 'detail', 'thumb'];
		$dirs = [Product::IMAGE_DIR, Photo::IMAGE_DIR];

		foreach ($dirs as $dir) {
			$rootDir = $this->context->parameters['wwwDir'] . \DIRECTORY_SEPARATOR . 'userfiles' . \DIRECTORY_SEPARATOR . $dir;
			FileSystem::createDir($rootDir);

			foreach ($subDirs as $subDir) {
				FileSystem::createDir($rootDir . \DIRECTORY_SEPARATOR . $subDir);
			}
		}

		FileSystem::createDir($this->context->parameters['wwwDir'] . \DIRECTORY_SEPARATOR . 'userfiles' . \DIRECTORY_SEPARATOR . File::FILE_DIR);
	}

}