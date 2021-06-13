<?php


namespace Eshop\Admin\Controls;


use Admin\Controls\AdminForm;
use Admin\Controls\AdminFormFactory;
use Eshop\DB\PricelistRepository;
use Eshop\DB\PriceRepository;
use Eshop\DB\Product;
use Eshop\DB\ProductRepository;
use Eshop\DB\RelatedRepository;
use Eshop\DB\Set;
use Eshop\DB\SetRepository;
use Eshop\DB\SupplierProductRepository;
use Eshop\DB\SupplierRepository;
use Eshop\DB\TaxRepository;
use Eshop\DB\VatRateRepository;
use Eshop\FormValidators;
use Eshop\Shopper;
use Nette\Application\UI\Control;
use Nette\Application\UI\Multiplier;
use Nette\DI\Container;
use Nette\Http\Request;
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

	private Request $request;

	public array $sets = [];

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
		Request $request,
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
		$this->request = $request;
		bdump('constructor');

		$form = $this->adminFormFactory->create();

		$form->addContainer('setItems');
		$form->addContainer('newRow');

		$form->addSubmit('submitSet');

		$form->onSuccess[] = function (AdminForm $form) {
			$values = $this->request->getPost();
			bdump($values, 'success');

			$this->setRepository->many()->where('fk_set', $this->product->getPK())->delete();

			if ($values['newRow']['product'] ?? false) {
				$newItemValues = $values['newRow'];
				$newItemValues['set'] = $this->product->getPK();
				$newItemValues['product'] = $this->productRepository->getProductByCodeOrEAN($newItemValues['product']);
				$newItemValues['amount'] = isset($newItemValues['amount']) ? \intval($newItemValues['amount']) : 1;
				$newItemValues['priority'] = isset($newItemValues['priority']) ? \intval($newItemValues['priority']) : 1;
				$newItemValues['discountPct'] = isset($newItemValues['discountPct']) ? \floatval(\str_replace(',', '.', $newItemValues['discountPct'])) : 0;

				unset($newItemValues['submitSet']);

				$this->setRepository->createOne($newItemValues);
			}

			unset($values['newRow']);

			foreach ($values['setItems'] ?? [] as $key => $item) {
				$item['uuid'] = $key;
				$item['set'] = $this->product->getPK();
				$item['product'] = $this->productRepository->getProductByCodeOrEAN($item['product']);
				$item['amount'] = isset($item['amount']) ? \intval($item['amount']) : 1;
				$item['priority'] = isset($item['priority']) ? \intval($item['priority']) : 1;
				$item['discountPct'] = isset($item['discountPct']) ? \floatval(\str_replace(',', '.', $item['discountPct'])) : 0;

				$this->setRepository->syncOne($item);
			}

			$form->reset();

			if ($this->getPresenter()->isAjax()) {
				$this->redrawControl('setFormSnippet');
			} else {
				$this->redirect('this');
			}
		};

		$this->addComponent($form, 'setForm');
	}

	public function createComponentSetItemForm()
	{
		return new Multiplier(function ($id) {
			bdump($id);

			/** @var AdminForm $form */
			$form = $this->getComponent('setForm');

			if ($id === 'null') {
				/** @var \Forms\Container $newRowContainer */
				$newRowContainer = $form->getComponent('newRow');

				$newRowContainer->addText('product')
					->addRule([FormValidators::class, 'isProductExists'], 'Produkt neexistuje!',
						[$this->productRepository]);
				$newRowContainer->addText('priority')->setDefaultValue(1)->addConditionOn($newRowContainer['product'],
					$form::FILLED)->addRule($form::INTEGER);
				$newRowContainer->addText('amount')->addConditionOn($newRowContainer['product'],
					$form::FILLED)->addRule($form::INTEGER);
				$newRowContainer->addText('discountPct')->setDefaultValue(0)
					->addConditionOn($newRowContainer['product'], $form::FILLED)

					->addRule($form::FLOAT)
					->addRule([FormValidators::class, 'isPercent'], 'Zadaná hodnota není procento!');

				$newRowContainer->addSubmit('submitSet');
			} else {
				$setItemsContainer = $form->getComponent('setItems');

				/** @var Set $item */
				$item = $this->setRepository->one($id);

				$itemContainer = $setItemsContainer->addContainer($item->getPK());
				$itemContainer->addText('product')
					->addRule([FormValidators::class, 'isProductExists'], 'Produkt neexistuje!',
						[$this->productRepository])

					->setDefaultValue($item->product->getFullCode());
				$itemContainer->addInteger('priority')->setDefaultValue($item->priority);
				$itemContainer->addInteger('amount')->setDefaultValue($item->amount);
				$itemContainer->addText('discountPct')->setDefaultValue($item->discountPct)
					->addRule($form::FLOAT)
					->addRule([FormValidators::class, 'isPercent'], 'Zadaná hodnota není procento!');
			}

			return $this->adminFormFactory->create();
		});
	}

	public function render()
	{
		bdump('render');
		$this->template->existingSets = $this->productRepository->getSetProducts($this->product);
		$this->template->render(__DIR__ . '/productSetForm.latte');
	}

	public function handleDeleteSetItem($uuid)
	{
		bdump('handle');
		$this->setRepository->many()->where('uuid', $uuid)->delete();

		if ($this->getPresenter()->isAjax()) {
			$this->redrawControl('setFormSnippet');
		} else {
			$this->redirect('this');
		}
	}
}