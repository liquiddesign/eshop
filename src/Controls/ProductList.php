<?php

declare(strict_types=1);

namespace Eshop\Controls;

use Eshop\DB\AttributeRepository;
use Eshop\DB\AttributeValueRangeRepository;
use Eshop\DB\AttributeValueRepository;
use Eshop\DB\CurrencyRepository;
use Eshop\DB\DisplayAmountRepository;
use Eshop\DB\DisplayDeliveryRepository;
use Eshop\DB\ProducerRepository;
use Eshop\DB\ProductRepository;
use Eshop\DB\WatcherRepository;
use Eshop\Services\ProductsCache\GeneralProductsCacheProvider;
use Eshop\ShopperUser;
use Forms\FormFactory;
use Grid\Datalist;
use Nette\Application\UI\Multiplier;
use Nette\Localization\Translator;
use Nette\Utils\Arrays;
use StORM\Collection;
use StORM\ICollection;
use Tracy\Debugger;
use Tracy\ILogger;

/**
 * Class Products
 * @package Eshop\Controls
 * @method onWatcherCreated(\Eshop\DB\Watcher $watcher)
 * @method onWatcherDeleted(\Eshop\DB\Watcher $watcher)
 */
class ProductList extends Datalist
{
	/**
	 * @var array<callable>&callable(\Eshop\DB\Watcher): void ; Occurs after order create
	 */
	public $onWatcherCreated;

	/**
	 * @var array<callable>&callable(\Eshop\DB\Watcher): void ; Occurs after order create
	 */
	public $onWatcherDeleted;

	/**
	 * @var array<callable> ; Occurs after order create
	 */
	public array $onBuyFormSuccessBeforeRedirect = [];

	/** @var array<array<string, int>>|null */
	protected array|null $providerOutput = null;

	public function __construct(
		protected readonly ProductRepository $productRepository,
		protected readonly WatcherRepository $watcherRepository,
		public readonly ShopperUser $shopperUser,
		protected readonly Translator $translator,
		protected readonly FormFactory $formFactory,
		protected readonly AttributeRepository $attributeRepository,
		protected readonly AttributeValueRepository $attributeValueRepository,
		protected readonly AttributeValueRangeRepository $attributeValueRangeRepository,
		protected readonly IBuyFormFactory $buyFormFactory,
		protected readonly ProducerRepository $producerRepository,
		protected readonly DisplayAmountRepository $displayAmountRepository,
		protected readonly DisplayDeliveryRepository $displayDeliveryRepository,
		protected readonly GeneralProductsCacheProvider $productsProvider,
		protected readonly CurrencyRepository $currencyRepository,
		?array $order = null,
		?Collection $source = null
	) {
		$source ??= $productRepository->getProducts()->join(['displayAmount' => 'eshop_displayamount'], 'this.fk_displayAmount = displayAmount.uuid');

		if ($order) {
			$source->orderBy($order);
		}

		parent::__construct($source);

		$this->setDefaultOnPage(20);
		$this->setDefaultOrder('priority');

		if ($this->shopperUser->getShowZeroPrices()) {
			$this->setFilters([
				'priceFrom' => 0,
			]);
		} else {
			$this->setFilters([
				'priceGt' => 0,
			]);
		}

		$this->setAllowedOrderColumns(['price' => 'price', 'priority' => 'visibilityListItem.priority', 'name' => 'name']);

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

		$this->setAllowedRepositoryFilters(['category', 'ribbon', 'producer', 'related', 'recommended', 'q', 'hidden', 'uuids', 'pricelist']);

		$this->addFilterExpression('crossSellFilter', function (ICollection $collection, $value): void {
			$this->productRepository->filterCrossSellFilter($value, $collection);
		});
		$this->addFilterExpression('priceFrom', function (ICollection $collection, $value): void {
			$this->shopperUser->getMainPriceType() === 'withVat' ? $this->productRepository->filterPriceVatFrom($value, $collection) : $this->productRepository->filterPriceFrom($value, $collection);
		}, '');
		$this->addFilterExpression('priceTo', function (ICollection $collection, $value): void {
			$this->shopperUser->getMainPriceType() === 'withVat' ? $this->productRepository->filterPriceVatTo($value, $collection) : $this->productRepository->filterPriceTo($value, $collection);
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
	}

	/**
	 * @return array<array<string, int>>|null
	 */
	public function getProviderOutput(): array|null
	{
		return $this->providerOutput;
	}

	/**
	 * @inheritDoc
	 */
	public function getItemsOnPage(): array
	{
		if ($this->itemsOnPage !== null) {
			return $this->itemsOnPage;
		}

		\Tracy\Debugger::timer('getProductsFromCacheTable');

//		dump($this->getFilters());

		try {
			$cachedProducts = $this->productsProvider->getProductsFromCacheTable(
				$this->getFilters(),
				$this->getOrder(),
				$this->getDirection(),
				$this->shopperUser->getPricelists()->toArray(),
				$this->shopperUser->getVisibilityLists(),
			);
		} catch (\Throwable $e) {
			Debugger::barDump($e);
			Debugger::log($e, ILogger::EXCEPTION);

			$cachedProducts = false;
		}

		\Tracy\Debugger::barDump(\Tracy\Debugger::timer('getProductsFromCacheTable'), 'cacheProducts');
		\Tracy\Debugger::barDump($cachedProducts);

		try {
			/** @var \StORM\Collection<\Eshop\DB\Product> $source */
			$source = $this->getFilteredSource();
		} catch (\Exception $e) {
			Debugger::log($e, ILogger::EXCEPTION);
			Debugger::barDump($e);

			$this->error();

			return [];
		}

		if ($cachedProducts !== false) {
			$this->providerOutput = $cachedProducts;
		}

		if ($this->getOnPage()) {
			if ($cachedProducts !== false) {
				$this->setItemCountCallback(function (): null {
					return null;
				});

				$this->getPaginator()->setItemCount(\count($cachedProducts['productPKs']));

				if ($cachedProducts['productPKs']) {
					$pagedProducts = \array_slice($cachedProducts['productPKs'], ($this->getPage() - 1) * $this->getOnPage(), $this->getOnPage());

					$source->where('this.id', $pagedProducts);
				} else {
					$source->where('0 = 1');
				}
			} else {
				$this->setItemCountCallback(function (Collection $collection): int {
					$collection->setSelect([])->setGroupBy([])->setOrderBy([]);

					return $collection->enum('this.uuid');
				});

				$source->setPage($this->getPage(), $this->getOnPage());
			}
		}

		$this->onLoad($source);

		$this->itemsOnPage = $this->nestingCallback && !$this->filters ? $this->getNestedSource($source, null) : $source->toArray();

		return $this->itemsOnPage;
	}

	public function handleWatchIt(string $product): void
	{
		if ($customer = $this->shopperUser->getCustomer()) {
			$watcher = $this->watcherRepository->createOne([
				'product' => $product,
				'customer' => $customer,
				'amountFrom' => 1,
				'beforeAmountFrom' => 0,
			], ignore: true);

			$this->onWatcherCreated($watcher);
		}

		$this->redirect('this');
	}

	public function handleUnWatchIt(string $product): void
	{
		if ($customer = $this->shopperUser->getCustomer()) {
			$watcher = $this->watcherRepository->many()
				->where('fk_product', $product)
				->where('fk_customer', $customer)
				->first();

			if (!$watcher) {
				$this->redirect('this');
			}

			$this->onWatcherDeleted($watcher);

			$watcher->delete();
		}

		$this->redirect('this');
	}

	public function handleBuy(string $productId, ?int $amount = null): void
	{
		/** @var \Eshop\DB\Product $product */
		$product = $this->itemsOnPage !== null ? ($this->itemsOnPage[$productId] ?? null) : $this->productRepository->getProduct($productId);
		
		$amount = $amount ?: (\max($product->defaultBuyCount, $product->minBuyCount));
		$this->shopperUser->getCheckoutManager()->addItemToCart($product, null, $amount);

		$this->redirect('this');
	}

	public function createComponentBuyForm(): Multiplier
	{
		$productRepository = $this->productRepository;

		return new Multiplier(function ($itemId) use ($productRepository) {
			/** @var \Eshop\DB\Product|null $product */
			$product = $this->itemsOnPage !== null ? ($this->itemsOnPage[$itemId] ?? null) : $productRepository->getProduct($itemId);

			if (!$product) {
				$this->redirect('this');
			}

			$form = $this->buyFormFactory->create($product);
			$form->onSuccess[] = function ($form, $values): void {
				Arrays::invoke($this->onBuyFormSuccessBeforeRedirect);

				$form->getPresenter()->redirect('this');
			};

			return $form;
		});
	}

	public function render(string $display = 'card'): void
	{
		// Preload for all parts of list
		$this->getItemsOnPage();

		$this->template->templateFilters = $this->getFiltersForTemplate();
		$this->template->display = $display === 'card' ? 'Card' : 'Row';
		$this->template->paginator = $this->getPaginator();
		$this->template->shopper = $this->shopperUser;
		$this->template->checkoutManager = $this->shopperUser->getCheckoutManager();
		$this->template->currencies = $this->currencyRepository->many()->toArray();

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
	 * @return array<string>
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
