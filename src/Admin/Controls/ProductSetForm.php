<?php


namespace Eshop\Admin\Controls;


use Admin\Controls\AdminForm;
use Admin\Controls\AdminFormFactory;
use Eshop\DB\PricelistRepository;
use Eshop\DB\PriceRepository;
use Eshop\DB\Product;
use Eshop\DB\ProductRepository;
use Eshop\DB\RelatedRepository;
use Eshop\DB\SetRepository;
use Eshop\DB\SupplierProductRepository;
use Eshop\DB\SupplierRepository;
use Eshop\DB\TaxRepository;
use Eshop\DB\VatRateRepository;
use Eshop\FormValidators;
use Eshop\Shopper;
use Nette\Application\UI\Control;
use Nette\DI\Container;
use Web\DB\PageRepository;

class ProductSetForm extends Control
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

	private Shopper $shopper;

	private VatRateRepository $vatRateRepository;

	private Product $product;

	public function __construct(
		Container $container,
		AdminFormFactory $adminFormFactory,
		PageRepository $pageRepository,
		ProductRepository $productRepository,
		PricelistRepository $pricelistRepository,
		PriceRepository $priceRepository,
		SupplierRepository $supplierRepository,
		SupplierProductRepository $supplierProductRepository,
		VatRateRepository $vatRateRepository,
		TaxRepository $taxRepository,
		RelatedRepository $relatedRepository,
		SetRepository $setRepository,
		Shopper $shopper,
		Product $product
	)
	{
		$this->product = $product;
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
		$this->shopper = $shopper;
		$this->vatRateRepository = $vatRateRepository;

		$form = $this->adminFormFactory->create();

		$setItems = $this->product ? $this->productRepository->getSetProducts($this->product) : [];

		$setItemsContainer = $form->addContainer('setItems');

		foreach ($setItems as $item) {
			$itemContainer = $setItemsContainer->addContainer($item->getPK());
			$itemContainer->addText('product')
				->addRule([FormValidators::class, 'isProductExists'], 'Produkt neexistuje!',
					[$this->productRepository])
				->setRequired()
				->setDefaultValue($item->product->getFullCode());
			$itemContainer->addInteger('priority')->setRequired()->setDefaultValue($item->priority);
			$itemContainer->addInteger('amount')->setRequired()->setDefaultValue($item->amount);
			$itemContainer->addText('discountPct')->setRequired()->setDefaultValue($item->discountPct)
				->addRule($form::FLOAT)
				->addRule([FormValidators::class, 'isPercent'], 'Zadaná hodnota není procento!');
		}

		$newRowContainer = $form->addContainer('newRow');

		$newRowContainer->addText('product')
			->addRule([FormValidators::class, 'isProductExists'], 'Produkt neexistuje!',
				[$this->productRepository]);
		$newRowContainer->addText('priority')->setDefaultValue(1)->addConditionOn($newRowContainer['product'],
			$form::FILLED)->addRule($form::INTEGER)->setRequired();
		$newRowContainer->addText('amount')->addConditionOn($newRowContainer['product'],
			$form::FILLED)->addRule($form::INTEGER)->setRequired();
		$newRowContainer->addText('discountPct')->setDefaultValue(0)
			->addConditionOn($newRowContainer['product'], $form::FILLED)
			->setRequired()
			->addRule($form::FLOAT)
			->addRule([FormValidators::class, 'isPercent'], 'Zadaná hodnota není procento!');

		$newRowContainer->addSubmit('submitSet');

		$form->onSuccess[] = function (AdminForm $form) {
			$values = $form->getValues();

			$this->setRepository->many()->where('fk_set', $this->product->getPK())->delete();

			if ($values['newRow']['product']) {
				$newItemValues = $values['newRow'];
				$newItemValues['set'] = $this->product->getPK();
				$newItemValues['product'] = $this->productRepository->getProductByCodeOrEAN($newItemValues['product']);

				$this->setRepository->createOne($newItemValues);
			}

			unset($values['newRow']);

			foreach ($values['setItems'] as $key => $item) {
				$item['uuid'] = $key;
				$item['set'] = $this->product->getPK();
				$item['product'] = $this->productRepository->getProductByCodeOrEAN($item['product']);

				$this->setRepository->syncOne($item);
			}

			if ($form->isSubmitted()->getName() == 'submitSet') {
//				if ($this->getPresenter()->isAjax()) {
//					$this->lookup(ProductForm::class)->redrawControl('form');
//				} else {
					$this->redirect('this');
//				}
			}
		};

		$this->addComponent($form, 'setForm');
	}

	public function render()
	{
		$this->template->render(__DIR__ . '/productSetForm.latte');
	}
}