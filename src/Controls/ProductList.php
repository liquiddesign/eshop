<?php

declare(strict_types=1);

namespace Eshop\Controls;

use Eshop\CheckoutManager;
use Eshop\DB\Attribute;
use Eshop\DB\AttributeRepository;
use Eshop\DB\AttributeValue;
use Eshop\DB\AttributeValueRangeRepository;
use Eshop\DB\AttributeValueRepository;
use Eshop\DB\CategoryRepository;
use Eshop\DB\ParameterAvailableValueRepository;
use Eshop\DB\ParameterRepository;
use Eshop\DB\ProductRepository;
use Eshop\Shopper;
use Eshop\DB\WatcherRepository;
use Forms\Form;
use Forms\FormFactory;
use Grid\Datalist;
use Nette\Application\UI\Multiplier;
use Nette\Localization\Translator;
use StORM\Collection;
use StORM\ICollection;

/**
 * Class Products
 * @package Eshop\Controls
 */
class ProductList extends Datalist
{
	public CheckoutManager $checkoutManager;

	private ProductRepository $productRepository;

	private WatcherRepository $watcherRepository;

	public Shopper $shopper;

	private ?array $templateFilters = null;

	private Translator $translator;

	private FormFactory $formFactory;

	private AttributeRepository $attributeRepository;

	private AttributeValueRepository $attributeValueRepository;

	private AttributeValueRangeRepository $attributeValueRangeRepository;

	public function __construct(
		ProductRepository $productRepository,
		CategoryRepository $categoryRepository,
		WatcherRepository $watcherRepository,
		CheckoutManager $checkoutManager,
		Shopper $shopper,
		Translator $translator,
		FormFactory $formFactory,
		AttributeRepository $attributeRepository,
		AttributeValueRepository $attributeValueRepository,
		AttributeValueRangeRepository $attributeValueRangeRepository,
		array $order = null,
		?Collection $source = null
	)
	{
		$source = $source ?? $productRepository->getProducts()->where('this.hidden', false);

		if ($order) {
			$source->orderBy($order);
		}

		parent::__construct($source);

		$this->setDefaultOnPage(20);
		$this->setDefaultOrder('priority');

		$this->setAllowedOrderColumns(['price' => 'price', 'priority' => 'priority']);
		$this->setItemCountCallback(function (ICollection $filteredSource) use ($categoryRepository) {
			if (isset($this->getFilters()['category'])) {
				return (int) ($categoryRepository->getCountsGrouped(null, $this->getFilters())[$this->getFilters()['category']] ?? 0);
			}
			
			return $filteredSource->setOrderBy([])->count();
		});

		$this->addOrderExpression('crossSellOrder', function (ICollection $collection, $value): void {
			$this->setDefaultOnPage(5);
			$collection->join(['eshop_product_nxn_eshop_category'], 'eshop_product_nxn_eshop_category.fk_product=this.uuid');
			$collection->join(['categories' => 'eshop_category'], 'categories.uuid=eshop_product_nxn_eshop_category.fk_category');
			$collection->orderBy(['LENGTH(categories.path)' => $value]);
		});

		$this->setAllowedRepositoryFilters(['category', 'tag', 'producer', 'related', 'recommended', 'q']);

		$this->addFilterExpression('crossSellFilter', function (ICollection $collection, $value): void {
			$this->productRepository->filterCrossSellFilter($value, $collection);
		});
		$this->addFilterExpression('priceFrom', function (ICollection $collection, $value): void {
			$this->productRepository->filterPriceFrom($value, $collection);
		}, '');
		$this->addFilterExpression('priceTo', function (ICollection $collection, $value): void {
			$this->productRepository->filterPriceTo($value, $collection);
		}, '');
		$this->addFilterExpression('producer', function (ICollection $collection, $value): void {
			$this->productRepository->filterProducer($value, $collection);
		}, '');
		$this->addFilterExpression('inStock', function (ICollection $collection, $value): void {
			$this->productRepository->filterInStock($value, $collection);
		});
		$this->addFilterExpression('query', function (ICollection $collection, $value): void {
			$this->productRepository->filterQuery($value, $collection);
		});
		$this->addFilterExpression('relatedSlave', function (ICollection $collection, $value): void {
			$this->productRepository->filterRelatedSlave($value, $collection);
		});
		$this->addFilterExpression('toners', function (ICollection $collection, $value): void {
			$this->productRepository->filterToners($value, $collection);
		});
		$this->addFilterExpression('compatiblePrinters', function (ICollection $collection, $value): void {
			$this->productRepository->filterCompatiblePrinters($value, $collection);
		});
		$this->addFilterExpression('similarProducts', function (ICollection $collection, $value): void {
			$this->productRepository->filterSimilarProducts($value, $collection);
		});
		$this->addFilterExpression('attributes', function (ICollection $collection, $attributes): void {
			$this->productRepository->filterAttributes($attributes, $collection);
		});
		$this->addFilterExpression('parameters', function (ICollection $collection, $groups): void {
			$this->productRepository->filterParameters($groups, $collection);
		});
		$this->addFilterExpression('attributeValue', function (ICollection $collection, $value): void {
			$this->productRepository->filterAttributeValue($value, $collection);
		});
		$this->addFilterExpression('availability', function (ICollection $collection, $value): void {
			$this->productRepository->filterAvailability($value, $collection);
		});
		$this->addFilterExpression('delivery', function (ICollection $collection, $value): void {
			$this->productRepository->filterDelivery($value, $collection);
		});

		$this->productRepository = $productRepository;
		$this->watcherRepository = $watcherRepository;
		$this->shopper = $shopper;
		$this->checkoutManager = $checkoutManager;
		$this->translator = $translator;
		$this->formFactory = $formFactory;
		$this->attributeRepository = $attributeRepository;
		$this->attributeValueRepository = $attributeValueRepository;
		$this->attributeValueRangeRepository = $attributeValueRangeRepository;
	}

	public function handleWatchIt(string $product): void
	{
		if ($customer = $this->shopper->getCustomer()) {
			$this->watcherRepository->createOne([
				'product' => $product,
				'customer' => $customer,
				'amountFrom' => 1,
				'beforeAmountFrom' => 0
			]);
		}

		$this->redirect('this');

		// @TODO call event
	}

	public function handleUnWatchIt(string $product): void
	{
		if ($customer = $this->shopper->getCustomer()) {
			$this->watcherRepository->many()
				->where('fk_product', $product)
				->where('fk_customer', $customer)
				->delete();
		}

		$this->redirect('this');

		// @TODO call event
	}

	public function handleBuy(string $productId): void
	{
		/** @var \Eshop\DB\Product $product */
		$product = $this->itemsOnPage !== null ? ($this->itemsOnPage[$productId] ?? null) : $this->productRepository->getProduct($productId);

		$amount = $product->defaultBuyCount >= $product->minBuyCount ? $product->defaultBuyCount : $product->minBuyCount;
		$this->checkoutManager->addItemToCart($product, null, $amount);

		$this->redirect('this');
	}

	public function createComponentBuyForm(): Multiplier
	{
		$shopper = $this->shopper;
		$checkoutManager = $this->checkoutManager;
		$productRepository = $this->productRepository;

		return new Multiplier(function ($itemId) use ($checkoutManager, $shopper, $productRepository) {
			/** @var \Eshop\DB\Product $product */
			$product = $this->itemsOnPage !== null ? ($this->itemsOnPage[$itemId] ?? null) : $productRepository->getProduct($itemId);

			$form = new BuyForm($product, $shopper, $checkoutManager, $this->translator);
			$form->onSuccess[] = function ($form, $values): void {
				$form->getPresenter()->redirect('this');
				// @TODO call event
			};

			return $form;
		});
	}

	public function render(string $display = 'card'): void
	{
		$this->template->templateFilters = $this->getFiltersForTemplate();
		$this->template->display = $display === 'card' ? 'Card' : 'Row';
		$this->template->paginator = $this->getPaginator();
		$this->template->shopper = $this->shopper;
		$this->template->checkoutManager = $this->checkoutManager;

		$this->template->render($this->template->getFile() ?: __DIR__ . '/productList.latte');
	}

	protected function createComponentFilterForm(): \Forms\Form
	{
		$filterForm = $this->formFactory->create();
		$filterForm->addText('priceFrom');
		$filterForm->addText('priceTo');
		$filterForm->addCheckbox('inStock');
		$filterForm->addSubmit('submit');
		$this->makeFilterForm($filterForm);

		return $filterForm;
	}

	private function getFiltersForTemplate(): array
	{
		$filters = $this->getFilters()['attributes'] ?? [];
		$templateFilters = [];

		foreach ($filters as $attributeKey => $attributeValues) {
			/** @var Attribute $attribute */
			$attribute = $this->attributeRepository->one($attributeKey);

			$attributeValues = $attribute->showRange ?
				$this->attributeValueRangeRepository->many()
					->where('this.uuid', $attributeValues)
					->join(['attributeValue' => 'eshop_attributevalue'], 'attributeValue.fk_attributeValueRange = this.uuid')
					->join(['attribute' => 'eshop_attribute'], 'attributeValue.fk_attribute = attribute.uuid')
					->toArrayOf('name') :
				$this->attributeValueRepository->many()->where('uuid', $attributeValues)->toArrayOf('label');

			foreach ($attributeValues as $attributeValueKey => $attributeValueLabel) {
				$templateFilters[$attributeKey][$attributeValueKey] = "$attribute->name: $attributeValueLabel";
			}
		}

		return $templateFilters;
	}
}
