<?php

declare(strict_types=1);

namespace Eshop\Admin\Controls;

use Admin\Controls\AdminForm;
use Admin\Controls\AdminFormFactory;
use Eshop\Admin\ProductPresenter;
use Eshop\DB\CategoryRepository;
use Eshop\DB\CategoryTypeRepository;
use Eshop\DB\DisplayAmountRepository;
use Eshop\DB\DisplayDeliveryRepository;
use Eshop\DB\InternalRibbonRepository;
use Eshop\DB\LoyaltyProgramProductRepository;
use Eshop\DB\LoyaltyProgramRepository;
use Eshop\DB\PricelistRepository;
use Eshop\DB\PriceRepository;
use Eshop\DB\ProducerRepository;
use Eshop\DB\Product;
use Eshop\DB\ProductRepository;
use Eshop\DB\RibbonRepository;
use Eshop\DB\SetRepository;
use Eshop\DB\SupplierProductRepository;
use Eshop\DB\SupplierRepository;
use Eshop\DB\TaxRepository;
use Eshop\DB\VatRateRepository;
use Eshop\FormValidators;
use Eshop\Shopper;
use Forms\Form;
use Nette\Application\UI\Control;
use Nette\Application\UI\Presenter;
use Nette\DI\Container;
use Nette\Utils\Arrays;
use Nette\Utils\FileSystem;
use StORM\ICollection;
use Web\DB\PageRepository;

class ProductForm extends Control
{
	/** @persistent */
	public string $tab = 'menu0';

	private ProductRepository $productRepository;

	private Container $container;

	private PricelistRepository $pricelistRepository;

	private PriceRepository $priceRepository;

	private SupplierRepository $supplierRepository;

	private SupplierProductRepository $supplierProductRepository;

	private PageRepository $pageRepository;

	private AdminFormFactory $adminFormFactory;

	private SetRepository $setRepository;

	private ?Product $product;

	private Shopper $shopper;

	private VatRateRepository $vatRateRepository;

	private IProductSetFormFactory $productSetFormFactory;

	private CategoryTypeRepository $categoryTypeRepository;

	private LoyaltyProgramRepository $loyaltyProgramRepository;

	private LoyaltyProgramProductRepository $loyaltyProgramProductRepository;

	/** @var string[] */
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
		RibbonRepository $ribbonRepository,
		InternalRibbonRepository $internalRibbonRepository,
		ProducerRepository $producerRepository,
		VatRateRepository $vatRateRepository,
		DisplayAmountRepository $displayAmountRepository,
		DisplayDeliveryRepository $displayDeliveryRepository,
		TaxRepository $taxRepository,
		SetRepository $setRepository,
		Shopper $shopper,
		IProductSetFormFactory $productSetFormFactory,
		CategoryTypeRepository $categoryTypeRepository,
		LoyaltyProgramRepository $loyaltyProgramRepository,
		LoyaltyProgramProductRepository $loyaltyProgramProductRepository,
		$product = null,
		array $configuration = []
	) {
		$this->product = $product = $productRepository->get($product);
		$this->productRepository = $productRepository;
		$this->container = $container;
		$this->pricelistRepository = $pricelistRepository;
		$this->priceRepository = $priceRepository;
		$this->supplierRepository = $supplierRepository;
		$this->supplierProductRepository = $supplierProductRepository;
		$this->pageRepository = $pageRepository;
		$this->adminFormFactory = $adminFormFactory;
		$this->setRepository = $setRepository;
		$this->configuration = $configuration;
		$this->shopper = $shopper;
		$this->vatRateRepository = $vatRateRepository;
		$this->productSetFormFactory = $productSetFormFactory;
		$this->categoryTypeRepository = $categoryTypeRepository;
		$this->loyaltyProgramRepository = $loyaltyProgramRepository;
		$this->loyaltyProgramProductRepository = $loyaltyProgramProductRepository;

		$form = $adminFormFactory->create(true);

		$this->monitor(Presenter::class, function (ProductPresenter $productPresenter) use ($form): void {
			$form->addHidden('editTab')->setDefaultValue($productPresenter->editTab);
		});

		$form->addGroup('Hlavní atributy');

		$form->addText('code', 'Kód a podsklad')->setRequired();
		$form->addText('subCode', 'Kód podskladu');
		$form->addText('ean', 'EAN')->setNullable();
		$nameInput = $form->addLocaleText('name', 'Název');

		$form->addSelect('vatRate', 'Úroveň DPH (%)', $vatRateRepository->getDefaultVatRates());

		/** @var \Eshop\DB\CategoryType[] $categoryTypes */
		$categoryTypes = $this->categoryTypeRepository->getCollection(true)->toArray();

		$categoriesContainer = $form->addContainer('categories');

		foreach ($categoryTypes as $categoryType) {
			$categoriesContainer->addDataMultiSelect($categoryType->getPK(), 'Kategorie: ' . $categoryType->name, $categoryRepository->getTreeArrayForSelect(true, $categoryType->getPK()));
		}

		$form->addSelect2('producer', 'Výrobce', $producerRepository->getArrayForSelect())->setPrompt('Nepřiřazeno');

		$form->addDataMultiSelect('ribbons', 'Veřejné štítky', $ribbonRepository->getArrayForSelect());
		$form->addDataMultiSelect('internalRibbons', 'Interní štítky', $internalRibbonRepository->getArrayForSelect());

		$form->addSelect2(
			'displayAmount',
			'Dostupnost',
			$displayAmountRepository->getArrayForSelect(),
		)->setPrompt('Nepřiřazeno');
		$form->addSelect2(
			'displayDelivery',
			'Doručení',
			$displayDeliveryRepository->getArrayForSelect(),
		)->setPrompt('Automaticky');
		$form->addLocalePerexEdit('perex', 'Popisek');
		$form->addLocaleRichEdit('content', 'Obsah');

		if (isset($configuration['suppliers']) && $configuration['suppliers'] && $this->supplierRepository->many()->count() > 0) {
			$locks = [];

			if ($product) {
				/** @var \Eshop\DB\Supplier $supplier */
				foreach ($supplierRepository->many()->join(['products' => 'eshop_supplierproduct'], 'products.fk_supplier=this.uuid')->where('products.fk_product', $product) as $supplier) {
					$locks[$supplier->getPK()] = $supplier->name;
				}
			}

			$locks[0] = '! Nikdy nepřebírat';

			$form->addSelect('supplierContent', 'Přebírat obsah', $locks)->setPrompt('S nejvyšší prioritou');
		}

		$form->addInteger('priority', 'Priorita')->setDefaultValue(10);
		$form->addCheckbox('hidden', 'Skryto');
		$form->addCheckbox('recommended', 'Doporučeno');

		if (isset($configuration['sets']) && $configuration['sets']) {
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

		if (isset($configuration['loyaltyProgram']) && $configuration['loyaltyProgram']) {
			$loyaltyProgramContainer = $form->addContainer('loyaltyProgram');

			if ($this->product !== null) {
				$productLoyaltyPrograms = $this->loyaltyProgramProductRepository->many()->setIndex('fk_loyaltyProgram')->where('fk_product', $this->product->getPK())->toArrayOf('points');
			}

			/** @var \Eshop\DB\LoyaltyProgram $loyaltyProgram */
			foreach ($this->loyaltyProgramRepository->many() as $loyaltyProgram) {
				$input = $loyaltyProgramContainer->addText($loyaltyProgram->getPK(), $loyaltyProgram->name)->setNullable();
				$input->addCondition($form::FILLED)->addRule($form::FLOAT)->endCondition();

				if (!isset($productLoyaltyPrograms[$loyaltyProgram->getPK()])) {
					continue;
				}

				$input->setDefaultValue($productLoyaltyPrograms[$loyaltyProgram->getPK()]);
			}
		}

		if (isset($configuration['taxes']) && $configuration['taxes']) {
			$form->addDataMultiSelect('taxes', 'Poplatky a daně', $taxRepository->getArrayForSelect());
		}

		if (isset($configuration['upsells']) && $configuration['upsells']) {
			$this->monitor(Presenter::class, function () use ($form): void {
				$form->addMultiSelect2('upsells', 'Upsell produkty', [], [
					'ajax' => [
						'url' => $this->getPresenter()->link('getProductsForSelect2!'),
					],
					'placeholder' => 'Zvolte produkty',
				])->checkDefaultValue(false);
			});
		}

		$this->monitor(Presenter::class, function () use ($form): void {
			$alternative = $form->addSelect2('alternative', 'Alternativa k produktu', [], [
				'ajax' => [
					'url' => $this->getPresenter()->link('getProductsForSelect2!'),
				],
				'allowClear' => true,
				'placeholder' => "Nepřiřazeno",
			])->checkDefaultValue(false);

			if (!$this->product || !$this->product->getValue('alternative')) {
				return;
			}

			$this->getPresenter()->template->select2AjaxDefaults[$alternative->getHtmlId()] = [$this->product->getValue('alternative') => $this->product->alternative->name];
		});

		$prices = $form->addContainer('prices');

		foreach ($pricelistRepository->many() as $prc) {
			$pricelist = $prices->addContainer($prc->getPK());
			$pricelist->addText('price')->setNullable()->addCondition(Form::FILLED)->addRule(Form::FLOAT);
			$pricelist->addText('priceVat')->setNullable()->addCondition(Form::FILLED)->addRule(Form::FLOAT);
			$pricelist->addText('priceBefore')->setNullable()->addCondition(Form::FILLED)->addRule(Form::FLOAT);
			$pricelist->addText('priceVatBefore')->setNullable()->addCondition(Form::FILLED)->addRule(Form::FLOAT);
		}

		$setItemsContainer = $form->addContainer('setItems');


		$this->monitor(Presenter::class, function () use ($setItemsContainer, $form): void {
			for ($i = 0; $i < 6; $i++) {
				$itemContainer = $setItemsContainer->addContainer("s$i");

				$itemContainer->addText('product')
					->addCondition($form::FILLED)
					->addRule([FormValidators::class, 'isProductExists'], 'Produkt neexistuje!', [$this->productRepository]);
//				$itemContainer->addSelect2('product', null, [], [
//					'ajax' => [
//						'url' => $this->getPresenter()->link('getProductsForSelect2!')
//					]
//				]);


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

			if (!$this->product) {
				return;
			}

			foreach ($this->productRepository->getSetProducts($this->product) as $setItem) {
				$itemContainer = $form['setItems']['s' . $i++];

				$itemContainer->setDefaults([
					'product' => $setItem->product->getFullCode(),
					'priority' => $setItem->priority,
					'amount' => $setItem->amount,
					'discountPct' => $setItem->discountPct,
				]);

				if ($i === 6) {
					break;
				}
			}
		});

		if (isset($configuration['buyCount']) && $configuration['buyCount']) {
			$form->addIntegerNullable('buyCount', 'Počet prodaných')->addFilter('intval')->addCondition($form::FILLED)->addRule($form::MIN, 'Zadejte číslo rovné nebo větší než 0!', 0);
		}

		$form->addPageContainer('product_detail', ['product' => $product], $nameInput);

		$form->addSubmits(!$product);

		$form->onValidate[] = [$this, 'validate'];
		$form->onSuccess[] = [$this, 'submit'];

		$this->addComponent($form, 'form');
	}

	public function validate(AdminForm $form): void
	{
		if (!$form->isValid()) {
			return;
		}

		$values = $form->getValues('array');

		if ($values['ean']) {
			if ($product = $this->productRepository->many()->where('ean', $values['ean'])->first()) {
				if ($this->product) {
					if ($product->getPK() !== $this->product->getPK()) {
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

		if ((!$values['code'] && !$values['subCode']) || !$product) {
			return;
		}

		if ($this->product) {
			if ($product->getPK() !== $this->product->getPK()) {
				$form['code']->addError('Již existuje produkt s touto kombinací kódu a subkódu');
			}
		} else {
			$form['code']->addError('Již existuje produkt s touto kombinací kódu a subkódu');
		}
	}

	public function submit(AdminForm $form): void
	{
		$data = $this->getPresenter()->getHttpRequest()->getPost();
		$values = $form->getValues('array');
		$editTab = Arrays::pick($values, 'editTab', null);

		$this->createImageDirs();

		if (!$values['uuid']) {
			$values['uuid'] = ProductRepository::generateUuid(
				$values['ean'],
				$values['subCode'] ? $values['code'] . '.' . $values['subCode'] : $values['code'],
				null,
			);
		} else {
			$this->product->upsells->unrelateAll();
		}

		$newCategories = [];

		if (\count($values['categories']) > 0) {
			foreach ($values['categories'] as $categories) {
				foreach ($categories as $category) {
					$newCategories[] = $category;
				}
			}
		}

		$this->loyaltyProgramProductRepository->many()->where('fk_product', $values['uuid'])->delete();

		foreach (Arrays::pick($values, 'loyaltyProgram', []) as $loyaltyProgram => $points) {
			if ($points === null) {
				continue;
			}

			$this->loyaltyProgramProductRepository->createOne([
				'points' => $points,
				'product' => $values['uuid'],
				'loyaltyProgram' => $loyaltyProgram,
			]);
		}

		$values['categories'] = $newCategories;

		$values['primaryCategory'] = \count($values['categories']) > 0 ? Arrays::first($values['categories']) : null;
//		$values['imageFileName'] = $form['imageFileName']->upload($values['uuid'] . '.%2$s');

		$values['alternative'] = isset($data['alternative']) ? $this->productRepository->one($data['alternative']) : null;

		if (isset($values['supplierContent'])) {
			if ($values['supplierContent'] === 0) {
				$values['supplierContentLock'] = true;
				$values['supplierContent'] = null;
			} else {
				$values['supplierContentLock'] = false;
			}
		}

		/** @var \Eshop\DB\Product $product */
		$product = $this->productRepository->syncOne($values, null, true);

		if (isset($data['upsells'])) {
			$this->product->upsells->relate($data['upsells']);
		}

		$changeColumns = ['name', 'perex', 'content'];

		if ($product->getParent() instanceof ICollection && $product->getParent()->getAffectedNumber() > 0) {
			foreach ($form->getMutations() as $mutation) {
				foreach ($changeColumns as $column) {
					if ($this->product->getValue($column, $mutation) !== $values[$column][$mutation]) {
						$product->update(['supplierContentLock' => true]);

						break 2;
					}
				}
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

			$prices['priceVat'] = $prices['priceVat'] ? \floatval(\str_replace(',', '.', $prices['priceVat'])) :
				$prices['price'] + ($prices['price'] * \fdiv(\floatval($this->vatRateRepository->getDefaultVatRates()[$product->vatRate]), 100));

			$conditions = [
				'pricelist' => $pricelistId,
				'product' => $values['uuid'],
			];

			$this->priceRepository->syncOne($conditions + $prices);
		}

		unset($values['prices']);

		$form->syncPages(function () use ($product, $values): void {
			$this->pageRepository->syncPage($values['page'], ['product' => $product->getPK()]);
		});

		$this->setRepository->many()->where('fk_set', $product->getPK())->delete();

		if (isset($values['productsSet']) && $values['productsSet']) {
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
		$form->processRedirect('edit', 'default', ['product' => $product, 'editTab' => $editTab]);
	}

	public function render(): void
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

		$this->template->supplierProducts = $this->getPresenter()->getParameter('product') ? $this->supplierProductRepository->many()->where(
			'fk_product',
			$this->getPresenter()->getParameter('product'),
		)->toArray() : [];

		$this->template->render(__DIR__ . '/productForm.latte');
	}

	public function createComponentSetForm(): AdminForm
	{
		if ($this->product && $this->configuration['sets']) {
			return $this->productSetFormFactory->create($this->product);
		}

		return $this->adminFormFactory->create();
	}

	public function handleDeleteSetItem($setItem): void
	{
		if ($setItem = $this->productRepository->getProductByCodeOrEAN($setItem)) {
			$this->setRepository->many()->where('fk_product', $setItem->getPK())->where('fk_set', $this->product->getPK())->delete();
		}

		$this->redirect('this');
	}

	protected function deleteImages(): void
	{
		$product = $this->getPresenter()->getParameter('product');

		if (!$product->imageFileName) {
			return;
		}

		$subDirs = ['origin', 'detail', 'thumb'];
		$dir = Product::IMAGE_DIR;

		foreach ($subDirs as $subDir) {
			$rootDir = $this->container->parameters['wwwDir'] . \DIRECTORY_SEPARATOR . 'userfiles' . \DIRECTORY_SEPARATOR . $dir;
			FileSystem::delete($rootDir . \DIRECTORY_SEPARATOR . $subDir . \DIRECTORY_SEPARATOR . $product->imageFileName);
		}

		$product->update(['imageFileName' => null]);
	}

	private function createImageDirs(): void
	{
		$subDirs = ['origin', 'detail', 'thumb'];
		$dir = Product::IMAGE_DIR;
		$rootDir = $this->container->parameters['wwwDir'] . \DIRECTORY_SEPARATOR . 'userfiles' . \DIRECTORY_SEPARATOR . $dir;
		FileSystem::createDir($rootDir);

		foreach ($subDirs as $subDir) {
			FileSystem::createDir($rootDir . \DIRECTORY_SEPARATOR . $subDir);
		}
	}
}
