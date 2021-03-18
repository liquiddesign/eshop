<?php

declare(strict_types=1);

namespace Eshop\Admin\Controls;

use App\Admin\Controls\AdminForm;
use App\Admin\Controls\AdminFormFactory;
use Eshop\DB\CategoryRepository;
use Eshop\DB\DisplayAmountRepository;
use Eshop\DB\DisplayDeliveryRepository;
use Eshop\DB\ParameterGroupRepository;
use Eshop\DB\PricelistRepository;
use Eshop\DB\PriceRepository;
use Eshop\DB\ProducerRepository;
use Eshop\DB\Product;
use Eshop\DB\ProductRepository;
use Eshop\DB\RelatedRepository;
use Eshop\DB\RibbonRepository;
use Eshop\DB\SupplierProductRepository;
use Eshop\DB\SupplierRepository;
use Eshop\DB\TagRepository;
use Eshop\DB\TaxRepository;
use Eshop\DB\VatRateRepository;
use Web\DB\PageRepository;
use Forms\Form;
use Nette\Application\UI\Control;
use Nette\DI\Container;
use Nette\Utils\FileSystem;
use Nette\Utils\Image;
use Pages\Helpers;

class ProductForm extends Control
{
	private ProductRepository $productRepository;

	private Container $container;

	private PricelistRepository $pricelistRepository;

	private PriceRepository $priceRepository;

	private SupplierRepository $supplierRepository;

	private SupplierProductRepository $supplierProductRepository;

	private PageRepository $pageRepository;

	private TaxRepository $taxRepository;

	private RelatedRepository $relatedRepository;

	public function __construct(
		Container $container,
		PageRepository $pageRepository,
		ProductRepository $productRepository,
		PricelistRepository $pricelistRepository,
		PriceRepository $priceRepository,
		SupplierRepository $supplierRepository,
		SupplierProductRepository $supplierProductRepository,
		CategoryRepository $categoryRepository,
		TagRepository $tagRepository,
		RibbonRepository $ribbonRepository,
		ProducerRepository $producerRepository,
		ParameterGroupRepository $parameterGroupRepository,
		VatRateRepository $vatRateRepository,
		DisplayAmountRepository $displayAmountRepository,
		DisplayDeliveryRepository $displayDeliveryRepository,
		TaxRepository $taxRepository,
		RelatedRepository $relatedRepository,
		$product = null
	)
	{
		//Form::initialize();
		$product = $productRepository->get($product);

		/** @var \App\Admin\Controls\AdminForm $form */
		$form = $container->getService(AdminFormFactory::SERVICE_NAME)->create();

		$form->addGroup('Hlavní atributy');

		$form->addText('code', 'Kód a podsklad')->setNullable();
		$form->addText('subCode', 'Kód podskladu')->setNullable();
		$form->addText('ean', 'EAN')->setNullable();
		$nameInput = $form->addLocaleText('name', 'Název');

		$imagePicker = $form->addImagePicker('imageFileName', 'Obrázek', [
			Product::IMAGE_DIR . \DIRECTORY_SEPARATOR . 'origin' => null,
			Product::IMAGE_DIR . \DIRECTORY_SEPARATOR . 'detail' => static function (Image $image): void {
				$image->resize(600, null);
			},
			Product::IMAGE_DIR . \DIRECTORY_SEPARATOR . 'thumb' => static function (Image $image): void {
				$image->resize(300, null);
			},
		]);

		$imagePicker->onDelete[] = function (array $directories, $filename) {
			$this->deleteImages();
			$this->redirect('this');
		};

		$form->addSelect('vatRate', 'Úroveň DPH', $vatRateRepository->getDefaultVatRates());
		$form->addDataMultiSelect('categories', 'Kategorie', $categoryRepository->many()->toArrayOf('name'));
		$form->addDataSelect('producer', 'Výrobce', $producerRepository->getArrayForSelect())->setPrompt('Nepřiřazeno');
		$form->addDataMultiSelect('parameterGroups', 'Skupiny parametrů', $parameterGroupRepository->getArrayForSelect());
		$form->addDataMultiSelect('tags', 'Tagy', $tagRepository->getArrayForSelect());
		$form->addDataMultiSelect('ribbons', 'Štítky', $ribbonRepository->getArrayForSelect());

		$form->addDataSelect('displayAmount', 'Dostupnost', $displayAmountRepository->getArrayForSelect())->setPrompt('Nepřiřazeno');
		$form->addDataSelect('displayDelivery', 'Doručení', $displayDeliveryRepository->getArrayForSelect())->setPrompt('Nepřiřazeno');
		$form->addLocalePerexEdit('perex', 'Popisek');
		$form->addLocaleRichEdit('content', 'Obsah');

		$locks = [0 => 'všechny', 99 => 'žádný'];
		foreach ($supplierRepository->many()->where('importPriority>0') as $supplier) {
			$locks[$supplier->importPriority] = isset($locks[$supplier->importPriority]) ? $locks[$supplier->importPriority] . ', ' . $supplier->name : $supplier->name;
		}

		$form->addSelect('supplierLock', 'Povolit pro úroveň importů', $locks)->setDefaultValue(0);
		$form->addInteger('priority', 'Priorita')->setDefaultValue(10);
		$form->addCheckbox('hidden', 'Skryto');
		$form->addCheckbox('recommended', 'Doporučeno');
		$form->addGroup('Nákup');
		$form->addText('unit', 'Prodejní jednotka')
			->setHtmlAttribute('data-info', 'Např.: ks, ml, ...');
		$form->addInteger('discountLevelPct', 'Slevová hladina (%)')->setDefaultValue(0);
		$form->addInteger('defaultBuyCount', 'Předdefinované množství')->setRequired()->setDefaultValue(1);
		$form->addInteger('minBuyCount', 'Minimální množství')->setRequired()->setDefaultValue(1);
		$form->addIntegerNullable('maxBuyCount', 'Maximální množství');
		$form->addInteger('buyStep', 'Krokové množství')->setDefaultValue(1);
		$form->addIntegerNullable('inPackage', 'Počet v balení');
		$form->addIntegerNullable('inCarton', 'Počet v kartónu');
		$form->addIntegerNullable('inPalett', 'Počet v paletě');
		$form->addIntegerNullable('roundingPackagePct', 'Zokrouhlení balení (%)');
		$form->addIntegerNullable('roundingCartonPct', 'Zokrouhlení karton (%)');
		$form->addIntegerNullable('roundingPalletPct', 'Zokrouhlení paletu (%)');
		$form->addCheckbox('unavailable', 'Neprodejné');

		/** @var \Eshop\DB\Category $printerCategory */
		$printerCategory = $categoryRepository->one('printers');

		if ($printerCategory) {
			$printers = $productRepository->many()
				->join(['nxnCategory' => 'eshop_product_nxn_eshop_category'], 'this.uuid = nxnCategory.fk_product')
				->join(['category' => 'eshop_category'], 'nxnCategory.fk_category = category.uuid')
				->where('category.path LIKE :categoryPath', ['categoryPath' => $printerCategory->path . '%']);

			if ($product) {
				$printers->where('this.uuid != :thisProduct', ['thisProduct' => $product->getPK()]);
			}

			if (\count($printers) > 0) {
				$form->addDataMultiSelect('tonerForPrinters', 'Toner pro tiskárny', $printers->orderBy(['name_cs'])->toArrayOf('name'));
			}
		}

		$form->addDataMultiSelect('taxes', 'Poplatky a daně', $taxRepository->getArrayForSelect());

		$prices = $form->addContainer('prices');

		foreach ($pricelistRepository->getDefaultPricelists() as $prc) {
			$pricelist = $prices->addContainer($prc->getPK());
			$pricelist->addText('price')->setNullable()->addCondition(Form::FILLED)->addRule(Form::FLOAT);
			$pricelist->addText('priceVat')->setNullable()->addCondition(Form::FILLED)->addRule(Form::FLOAT);
			$pricelist->addText('priceBefore')->setNullable()->addCondition(Form::FILLED)->addRule(Form::FLOAT);
			$pricelist->addText('priceVatBefore')->setNullable()->addCondition(Form::FILLED)->addRule(Form::FLOAT);
		}

		$form->addPageContainer('product_detail', ['product' => null], $nameInput);

		$form->addSubmits(!$product);

		$form->onSuccess[] = [$this, 'submit'];

		$this->addComponent($form, 'form');

		$this->productRepository = $productRepository;
		$this->container = $container;
		$this->pricelistRepository = $pricelistRepository;
		$this->priceRepository = $priceRepository;
		$this->supplierRepository = $supplierRepository;
		$this->supplierProductRepository = $supplierProductRepository;
		$this->pageRepository = $pageRepository;
		$this->taxRepository = $taxRepository;
		$this->relatedRepository = $relatedRepository;
	}

	public function submit(AdminForm $form)
	{
		$values = $form->getValues('array');

		$this->createImageDirs();

		if (!$values['uuid']) {
			$values['uuid'] = ProductRepository::generateUuid($values['ean'], $values['subCode'] ? $values['code'] . '.' . $values['subCode'] : $values['code'], null);
		}

		$values['imageFileName'] = $form['imageFileName']->upload($values['uuid'] . '.%2$s');

		$product = $this->productRepository->syncOne($values, null, true);

		if (isset($values['tonerForPrinters'])) {
			$this->relatedRepository->many()
				->where('fk_master', $product->getPK())
				->where('fk_type', 'tonerForPrinter')
				->delete();

			foreach ($values['tonerForPrinters'] as $value) {
				$this->relatedRepository->syncOne([
					'master' => $product->getPK(),
					'slave' => $value,
					'type' => 'tonerForPrinter'
				]);
			}
		}

		foreach ($values['prices'] as $pricelistId => $prices) {
			$conditions = [
				'fk_pricelist' => $pricelistId,
				'fk_product' => $values['uuid'],
			];

			if ($prices['price'] === null) {
				$this->priceRepository->many()->match($conditions)->delete();
				continue;
			}

			$conditions = [
				'pricelist' => $pricelistId,
				'product' => $values['uuid'],
			];

			$this->priceRepository->syncOne($conditions + $prices);
		}

		unset($values['prices']);

		$values['page']['params'] = Helpers::serializeParameters(['product' => $product->getPK()]);
		$this->pageRepository->syncOne($values['page']);

		$this->getPresenter()->flashMessage('Uloženo', 'success');
		$this['form']->processRedirect('edit', 'default', [$product]);
	}

	protected function deleteImages()
	{
		$product = $this->getPresenter()->getParameter('product');

		if ($product->imageFileName) {
			$subDirs = ['origin', 'detail', 'thumb'];
			$dir = Product::IMAGE_DIR;

			foreach ($subDirs as $subDir) {
				$rootDir = $this->container->parameters['wwwDir'] . \DIRECTORY_SEPARATOR . 'userfiles' . \DIRECTORY_SEPARATOR . $dir;
				FileSystem::delete($rootDir . \DIRECTORY_SEPARATOR . $subDir . \DIRECTORY_SEPARATOR . $product->imageFileName);
			}

			$product->update(['imageFileName' => null]);
		}
	}

	private function createImageDirs()
	{
		$subDirs = ['origin', 'detail', 'thumb'];
		$dir = Product::IMAGE_DIR;
		$rootDir = $this->container->parameters['wwwDir'] . \DIRECTORY_SEPARATOR . 'userfiles' . \DIRECTORY_SEPARATOR . $dir;
		FileSystem::createDir($rootDir);

		foreach ($subDirs as $subDir) {
			FileSystem::createDir($rootDir . \DIRECTORY_SEPARATOR . $subDir);
		}
	}

	public function render()
	{
		$this->template->product = $this->getPresenter()->getParameter('product');
		$this->template->pricelists = $this->pricelistRepository->getDefaultPricelists();
		$this->template->supplierProducts = $this->getPresenter()->getParameter('product') ? $this->supplierProductRepository->many()->where('fk_product', $this->getPresenter()->getParameter('product'))->toArray() : [];
		$this->template->render(__DIR__ . '/productForm.latte');
	}
}