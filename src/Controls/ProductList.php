<?php

declare(strict_types=1);

namespace Eshop\Controls;

use Eshop\CheckoutManager;
use Eshop\DB\AttributeRepository;
use Eshop\DB\AttributeValueRangeRepository;
use Eshop\DB\AttributeValueRepository;
use Eshop\DB\CategoryRepository;
use Eshop\DB\DisplayAmountRepository;
use Eshop\DB\DisplayDeliveryRepository;
use Eshop\DB\ProducerRepository;
use Eshop\DB\ProductRepository;
use Eshop\DB\WatcherRepository;
use Eshop\Shopper;
use Forms\FormFactory;
use Grid\Datalist;
use Nette\Application\UI\Multiplier;
use Nette\Localization\Translator;
use Nette\Utils\Arrays;
use StORM\Collection;
use StORM\ICollection;

/**
 * Class Products
 * @package Eshop\Controls
 * @method onWatcherCreated(\Eshop\DB\Watcher $watcher)
 * @method onWatcherDeleted(\Eshop\DB\Watcher $watcher)
 */
class ProductList extends Datalist
{
	/**
	 * @var callable[]&callable(\Eshop\DB\Watcher): void; Occurs after order create
	 */
	public $onWatcherCreated;

	/**
	 * @var callable[]&callable(\Eshop\DB\Watcher): void; Occurs after order create
	 */
	public $onWatcherDeleted;

	public CheckoutManager $checkoutManager;

	public Shopper $shopper;

	protected ProductRepository $productRepository;

	protected WatcherRepository $watcherRepository;

	protected Translator $translator;

	protected FormFactory $formFactory;

	protected AttributeRepository $attributeRepository;

	protected AttributeValueRepository $attributeValueRepository;

	protected AttributeValueRangeRepository $attributeValueRangeRepository;

	protected IBuyFormFactory $buyFormFactory;

	protected ProducerRepository $producerRepository;

	protected DisplayAmountRepository $displayAmountRepository;

	protected DisplayDeliveryRepository $displayDeliveryRepository;

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
		IBuyFormFactory $buyFormFactory,
		ProducerRepository $producerRepository,
		DisplayAmountRepository $displayAmountRepository,
		DisplayDeliveryRepository $displayDeliveryRepository,
		?array $order = null,
		?Collection $source = null
	) {
		$source ??= $productRepository->getProducts()
			->join(['displayAmount' => 'eshop_displayamount'], 'this.fk_displayAmount = displayAmount.uuid')
			->where('this.hidden', false);

		if ($order) {
			$source->orderBy($order);
		}

		parent::__construct($source);

		$this->setDefaultOnPage(20);
		$this->setDefaultOrder('priority');

		$this->setAllowedOrderColumns(['price' => 'price', 'priority' => 'priority']);
		$this->setItemCountCallback(function (ICollection $filteredSource) use ($categoryRepository) {
			$prefetchedCount = isset($this->getFilters()['category']) && \count($this->getFilters()) === 1 ? $categoryRepository->getCounts($this->getFilters()['category']) : null;
			
			return $prefetchedCount ?? $filteredSource->setOrderBy([])->count();
		});

		$this->addOrderExpression('crossSellOrder', function (ICollection $collection, $value): void {
			$this->setDefaultOnPage(5);
			$collection->join(['eshop_product_nxn_eshop_category'], 'eshop_product_nxn_eshop_category.fk_product=this.uuid');
			$collection->join(['categories' => 'eshop_category'], 'categories.uuid=eshop_product_nxn_eshop_category.fk_category');
			$collection->orderBy(['LENGTH(categories.path)' => $value]);
		});

		$this->addOrderExpression('availabilityAndPrice', function (ICollection $collection, $value): void {
			$collection->orderBy([
				'case COALESCE(displayAmount.isSold, 2)
					 when 0 then 0
					 when 2 then 1
					 when 1 then 2
					 else 2 end' => $value,
				'price' => $value,
			]);
		});

		$this->setAllowedRepositoryFilters(['category', 'tag', 'producer', 'related', 'recommended', 'q', 'hidden']);

		$this->addFilterExpression('crossSellFilter', function (ICollection $collection, $value): void {
			$this->productRepository->filterCrossSellFilter($value, $collection);
		});
		$this->addFilterExpression('priceFrom', function (ICollection $collection, $value): void {
			$this->shopper->getShowPrice() === 'withVat' ? $this->productRepository->filterPriceVatFrom($value, $collection) : $this->productRepository->filterPriceFrom($value, $collection);
		}, '');
		$this->addFilterExpression('priceTo', function (ICollection $collection, $value): void {
			$this->shopper->getShowPrice() === 'withVat' ? $this->productRepository->filterPriceVatTo($value, $collection) : $this->productRepository->filterPriceTo($value, $collection);
		}, '');
		$this->addFilterExpression('producer', function (ICollection $collection, $value): void {
			$this->productRepository->filterProducer($value, $collection);
		}, '');
		$this->addFilterExpression('producers', function (ICollection $collection, $value): void {
			$this->productRepository->filterProducers($value, $collection);
		}, '');
		$this->addFilterExpression('inStock', function (ICollection $collection, $value): void {
			$this->productRepository->filterInStock($value, $collection);
		});
		$this->addFilterExpression('query', function (Collection $collection, $value): void {
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
		$this->addFilterExpression('relatedTypeMaster', function (ICollection $collection, $value): void {
			$this->productRepository->filterRelatedTypeMaster($value, $collection);
		});
		$this->addFilterExpression('relatedTypeSlave', function (ICollection $collection, $value): void {
			$this->productRepository->filterRelatedTypeSlave($value, $collection);
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
		$this->buyFormFactory = $buyFormFactory;
		$this->producerRepository = $producerRepository;
		$this->displayAmountRepository = $displayAmountRepository;
		$this->displayDeliveryRepository = $displayDeliveryRepository;
	}

	public function handleWatchIt(string $product): void
	{
		if ($customer = $this->shopper->getCustomer()) {
			$watcher = $this->watcherRepository->createOne([
				'product' => $product,
				'customer' => $customer,
				'amountFrom' => 1,
				'beforeAmountFrom' => 0,
			]);

			$this->onWatcherCreated($watcher);
		}

		$this->redirect('this');
	}

	public function handleUnWatchIt(string $product): void
	{
		if ($customer = $this->shopper->getCustomer()) {
			$watcher = $this->watcherRepository->many()
				->where('fk_product', $product)
				->where('fk_customer', $customer)
				->first();

			$this->onWatcherDeleted($watcher);

			$watcher->delete();
		}

		$this->redirect('this');
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
		$productRepository = $this->productRepository;

		return new Multiplier(function ($itemId) use ($productRepository) {
			/** @var \Eshop\DB\Product $product */
			$product = $this->itemsOnPage !== null ? ($this->itemsOnPage[$itemId] ?? null) : $productRepository->getProduct($itemId);

			$form = $this->buyFormFactory->create($product);
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

		/** @var \Nette\Bridges\ApplicationLatte\Template $template */
		$template = $this->template;

		$template->render($template->getFile() ?: __DIR__ . '/productList.latte');
	}

	protected function createComponentFilterForm(): \Forms\Form
	{
		$filterForm = $this->formFactory->create();
		$filterForm->addText('priceFrom');
		$filterForm->addText('priceTo');
		$filterForm->addCheckbox('inStock');
		$filterForm->addSubmit('submit');
		$this->makeFilterForm($filterForm);
		
		$filterForm->onSuccess[] = function (): void {
			// init onSuccess to prevent "no associated handlers"
		};

		return $filterForm;
	}

	/**
	 * @return string[]
	 * @throws \StORM\Exception\NotFoundException
	 */
	private function getFiltersForTemplate(): array
	{
		$filters = $this->getFilters();
		$templateFilters = [];

		foreach (Arrays::pick($filters, 'attributes', []) as $attributeKey => $attributeValues) {
			if ($attributeKey === 'producer') {
				/** @var \Eshop\DB\Producer $producer */
				foreach ($this->producerRepository->getCollection()->where('this.uuid', $attributeValues) as $producer) {
					$templateFilters['producer'][$producer->getPK()] = $this->translator->translate('.producer', 'Výrobce') . ": $producer->name";
				}

				continue;
			}

			if ($attributeKey === 'availability') {
				/** @var \Eshop\DB\DisplayAmount $displayAmount */
				foreach ($this->displayAmountRepository->getCollection()->where('this.uuid', $attributeValues) as $displayAmount) {
					$templateFilters['availability'][$displayAmount->getPK()] = $this->translator->translate('.availability', 'Dostupnost') . ": $displayAmount->label";
				}

				continue;
			}

			if ($attributeKey === 'delivery') {
				/** @var \Eshop\DB\DisplayDelivery $displayDelivery */
				foreach ($this->displayDeliveryRepository->getCollection()->where('this.uuid', $attributeValues) as $displayDelivery) {
					$templateFilters['delivery'][$displayDelivery->getPK()] = $this->translator->translate('.delivery', 'Doprava') . ": $displayDelivery->label";
				}

				continue;
			}

			/** @var \Eshop\DB\Attribute $attribute */
			$attribute = $this->attributeRepository->one($attributeKey);

			$attributeValues = $attribute->showRange ?
				$this->attributeValueRangeRepository->getCollection()
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
