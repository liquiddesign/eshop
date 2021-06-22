<?php

declare(strict_types=1);

namespace Eshop\Admin\Controls;

use Admin\Controls\AdminForm;
use Admin\Controls\AdminFormFactory;
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
use Eshop\DB\SetRepository;
use Eshop\DB\SupplierProductRepository;
use Eshop\DB\SupplierRepository;
use Eshop\DB\TagRepository;
use Eshop\DB\TaxRepository;
use Eshop\DB\VatRateRepository;
use Eshop\FormValidators;
use Eshop\Shopper;
use Nette\Utils\Arrays;
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

	private AdminFormFactory $adminFormFactory;

	private SetRepository $setRepository;

	private ?Product $product;

	private Shopper $shopper;

	private VatRateRepository $vatRateRepository;

	private IProductSetFormFactory $productSetFormFactory;

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
		TagRepository $tagRepository,
		RibbonRepository $ribbonRepository,
		ProducerRepository $producerRepository,
		ParameterGroupRepository $parameterGroupRepository,
		VatRateRepository $vatRateRepository,
		DisplayAmountRepository $displayAmountRepository,
		DisplayDeliveryRepository $displayDeliveryRepository,
		TaxRepository $taxRepository,
		RelatedRepository $relatedRepository,
		SetRepository $setRepository,
		Shopper $shopper,
		IProductSetFormFactory $productSetFormFactory,
		$product = null,
		array $configuration = []
	)
	{
		$this->product = $product = $productRepository->get($product);
		$this->productRepository = $productRepository;
		$this->container = $container;
		$this->pricelistRepository = $pricelistRepository;
		$this->priceRepository = $priceRepository;
		$this->supplierRepository = $supplierRepository;
		$this->supplierProductRepository = $supplierProductRepository;
		$this->pageRepository = $pageRepository;
		$this->taxRepository = $taxRepository;
		$this->relatedRepository = $relatedRepository;
		$this->adminFormFactory = $adminFormFactory;
		$this->setRepository = $setRepository;
		$this->configuration = $configuration;
		$this->shopper = $shopper;
		$this->vatRateRepository = $vatRateRepository;
		$this->productSetFormFactory = $productSetFormFactory;

		$form = $adminFormFactory->create(true);

		$form->addGroup('Hlavní atributy');

		$form->addText('code', 'Kód a podsklad')->setRequired();
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
		$form->addDataMultiSelect('categories', 'Kategorie', $categoryRepository->getTreeArrayForSelect());
		$form->addDataSelect('producer', 'Výrobce', $producerRepository->getArrayForSelect())->setPrompt('Nepřiřazeno');

		if ($configuration['parameters']) {
			$form->addDataMultiSelect('parameterGroups', 'Skupiny parametrů',
				$parameterGroupRepository->getArrayForSelect());
		}

		$form->addDataMultiSelect('tags', 'Tagy', $tagRepository->getArrayForSelect());
		$form->addDataMultiSelect('ribbons', 'Štítky', $ribbonRepository->getArrayForSelect());

		$form->addDataSelect('displayAmount', 'Dostupnost',
			$displayAmountRepository->getArrayForSelect())->setPrompt('Nepřiřazeno');
		$form->addDataSelect('displayDelivery', 'Doručení',
			$displayDeliveryRepository->getArrayForSelect())->setPrompt('Nepřiřazeno');
		$form->addLocalePerexEdit('perex', 'Popisek');
		$form->addLocaleRichEdit('content', 'Obsah');


		if ($configuration['suppliers']) {
			$locks = [];

			if ($product) {
				foreach (
					$supplierRepository->many()->join(['products' => 'eshop_supplierproduct'],
						'products.fk_supplier=this.uuid')->where('products.fk_product', $product) as $supplier
				) {
					$locks[$supplier->getPK()] = $supplier->name;
				}
			}

			$locks[0] = '! Nikdy nepřebírat';

			$form->addSelect('supplierContent', 'Přebírat obsah', $locks)->setPrompt('S nejvyšší prioritou');
		}

		$form->addInteger('priority', 'Priorita')->setDefaultValue(10);
		$form->addCheckbox('hidden', 'Skryto');
		$form->addCheckbox('recommended', 'Doporučeno');

		if ($configuration['sets']) {
			$form->addCheckbox('productsSet', 'Set produktů')->addCondition($form::EQUAL, true)->toggle('setItems');
		}

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
		$form->addCheckbox('unavailable', 'Neprodejné');

		if (isset($configuration['weightAndDimension']) && $configuration['weightAndDimension']) {
			$form->addText('weight', 'Váha')
				->setNullable()
				->addCondition($form::FILLED)
				->addRule($form::FLOAT);
			$form->addText('dimension', 'Rozměr')
				->setNullable()
				->addCondition($form::FILLED)
				->addRule($form::FLOAT);
		}

		if ($configuration['relations']) {
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
					$form->addDataMultiSelect('tonerForPrinters', 'Toner pro tiskárny',
						$printers->orderBy(['name_cs'])->toArrayOf('name'));
				}
			}
		}

		if ($configuration['taxes']) {
			$form->addDataMultiSelect('taxes', 'Poplatky a daně', $taxRepository->getArrayForSelect());
		}

		if ($configuration['upsells']) {
			$form->addText('upsells', 'Upsell produkty')
				->setNullable()
				->addCondition($form::FILLED)
				->addRule([FormValidators::class, 'isMultipleProductsExists'],
					'Chybný formát nebo nebyl nalezen některý ze zadaných produktů!', [$productRepository]);
		}

		$form->addText('alternative', 'Alternativa k produktu')
			->addRule([FormValidators::class, 'isProductExists'], 'Produkt neexistuje!', [$productRepository]);

		$prices = $form->addContainer('prices');

		foreach ($pricelistRepository->many() as $prc) {
			$pricelist = $prices->addContainer($prc->getPK());
			$pricelist->addText('price')->setNullable()->addCondition(Form::FILLED)->addRule(Form::FLOAT);
			$pricelist->addText('priceVat')->setNullable()->addCondition(Form::FILLED)->addRule(Form::FLOAT);
			$pricelist->addText('priceBefore')->setNullable()->addCondition(Form::FILLED)->addRule(Form::FLOAT);
			$pricelist->addText('priceVatBefore')->setNullable()->addCondition(Form::FILLED)->addRule(Form::FLOAT);
		}

		$setItemsContainer = $form->addContainer('setItems');

		for ($i = 0; $i < 5; $i++) {
			$itemContainer = $setItemsContainer->addContainer("s$i");
			$itemContainer->addText('product')
				->addCondition($form::FILLED)
				->addRule([FormValidators::class, 'isProductExists'], 'Produkt neexistuje!', [$this->productRepository]);
			$itemContainer->addInteger('priority')
				->setDefaultValue(1)
				->addConditionOn($itemContainer['product'], $form::FILLED)
				->setRequired();
			$itemContainer->addInteger('amount')
				->setDefaultValue(1)
				->addConditionOn($itemContainer['product'], $form::FILLED)
				->setRequired()
				->addRule($form::MIN, 'Množství musí být větší než 0!', 1);
			$itemContainer->addText('discountPct')
				->setDefaultValue(0)
				->addConditionOn($itemContainer['product'], $form::FILLED)
				->setRequired()
				->addRule($form::FLOAT)
				->addRule([FormValidators::class, 'isPercent'], 'Zadaná hodnota není procento!');
		}

		$i = 0;

		if ($this->product) {
			foreach ($this->productRepository->getSetProducts($this->product) as $setItem) {
				bdump($setItem);
				$itemContainer = $form['setItems']['s' . $i++];
				
				$itemContainer->setDefaults([
					'product' => $setItem->product->getFullCode(),
					'priority' => $setItem->priority,
					'amount' => $setItem->amount,
					'discountPct' => $setItem->discountPct
				]);
				
				if ($i == 5) {
					break;
				}
			}
		}

		$form->addPageContainer('product_detail', ['product' => null], $nameInput);

		$form->addSubmits(!$product);

		$form->onValidate[] = [$this, 'validate'];
		$form->onSuccess[] = [$this, 'submit'];

		$this->addComponent($form, 'form');
	}

	public function validate(AdminForm $form)
	{
		if (!$form->isValid()) {
			return;
		}

		$values = $form->getValues('array');

		if ($values['ean']) {
			if ($product = $this->productRepository->many()->where('ean', $values['ean'])->first()) {
				if ($this->product) {
					if ($product->getPK() != $this->product->getPK()) {
						$form['ean']->addError('Již existuje produkt s tímto EAN');
					}
				} else {
					$form['ean']->addError('Již existuje produkt s tímto EAN');
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

		if (($values['code'] || $values['subCode']) && $product) {
			if ($this->product) {
				if ($product->getPK() != $this->product->getPK()) {
					$form['code']->addError('Již existuje produkt s touto kombinací kódu a subkódu');
				}
			} else {
				$form['code']->addError('Již existuje produkt s touto kombinací kódu a subkódu');
			}
		}
	}

	public function submit(AdminForm $form)
	{
		$values = $form->getValues('array');

		$this->createImageDirs();

		if (!$values['uuid']) {
			$values['uuid'] = ProductRepository::generateUuid($values['ean'],
				$values['subCode'] ? $values['code'] . '.' . $values['subCode'] : $values['code'], null);
		} else {
			$this->product->upsells->unrelateAll();
		}

		$values['primaryCategory'] = \count($values['categories']) > 0 ? Arrays::first($values['categories']) : null;
		$values['imageFileName'] = $form['imageFileName']->upload($values['uuid'] . '.%2$s');

		if ($values['upsells'] ?? null) {
			$upsells = [];
			foreach (\explode(';', $values['upsells']) as $upsell) {
				$upsells[] = $this->productRepository->getProductByCodeOrEAN($upsell)->getPK();
			}

			$this->product->upsells->relate($upsells);
		}

		$values['alternative'] = $values['alternative'] ? $this->productRepository->getProductByCodeOrEAN($values['alternative']) : null;

		if (isset($values['supplierContent'])) {
			if ($values['supplierContent'] === 0) {
				$values['supplierContentLock'] = true;
				$values['supplierContent'] = null;
			} else {
				$values['supplierContentLock'] = false;
			}
		}

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

			$prices['priceVat'] = $prices['priceVat'] ? \floatval(\str_replace(',', '.', $prices['priceVat'])) : ($prices['price'] + ($prices['price'] * \fdiv(\floatval($this->vatRateRepository->getDefaultVatRates()[$product->vatRate]), 100)));

			$conditions = [
				'pricelist' => $pricelistId,
				'product' => $values['uuid'],
			];

			$this->priceRepository->syncOne($conditions + $prices);
		}

		unset($values['prices']);

		$form->syncPages(function () use ($product, $values) {
			$values['page']['params'] = Helpers::serializeParameters(['product' => $product->getPK()]);
			$this->pageRepository->syncOne($values['page']);
		});

		$this->setRepository->many()->where('fk_set', $this->product->getPK())->delete();

		if ($values['productsSet']) {
			foreach ($values['setItems'] as $setItem) {
				if ($setItem['product'] === '') {
					continue;
				}

				$setItem['product'] = $this->productRepository->getProductByCodeOrEAN($setItem['product']);

				if (!$setItem['product']) {
					continue;
				}

				$setItem['product'] = $setItem['product']->getPK();
				$setItem['set'] = $this->product->getPK();

				$this->setRepository->syncOne($setItem);
			}
		}

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
		$this->template->pricelists = $this->pricelistRepository->many()->orderBy(['this.priority']);
		$this->template->supplierProducts = [];
		$this->template->configuration = $this->configuration;
		$this->template->shopper = $this->shopper;

		$this->template->modals = [
			'name' => 'frm-productForm-form-name-cs',
			'perex' => 'frm-perex-cs',
			'content' => 'frm-content-cs',
		];

		$this->template->supplierProducts = $this->getPresenter()->getParameter('product') ? $this->supplierProductRepository->many()->where('fk_product',
			$this->getPresenter()->getParameter('product'))->toArray() : [];
		$this->template->render(__DIR__ . '/productForm.latte');
	}

	public function createComponentSetForm()
	{
		if ($this->product && $this->configuration['sets']) {
			return $this->productSetFormFactory->create($this->product);
		}

		return $this->adminFormFactory->create();
	}

	public function handleDeleteSetItem($setItem)
	{
		if ($setItem = $this->productRepository->getProductByCodeOrEAN($setItem)) {
			$this->setRepository->many()->where('fk_product', $setItem->getPK())->where('fk_set', $this->product->getPK())->delete();
		}

		$this->redirect('this');
	}
}