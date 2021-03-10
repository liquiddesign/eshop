<?php

declare(strict_types=1);

namespace Eshop\Controls;

use Eshop\CheckoutManager;
use Eshop\DB\ParameterRepository;
use Eshop\DB\ProductRepository;
use Eshop\Shopper;
use Eshop\DB\WatcherRepository;
use App\Web\Controls\IImageControlFactory;
use App\Web\Controls\ImageControl;
use Forms\FormFactory;
use Grid\Datalist;
use Nette\Application\UI\Multiplier;
use Nette\Localization\Translator;
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

	private Shopper $shopper;

	public IImageControlFactory $imageControlFactory;

	private ?array $templateFilters = null;

	private Translator $translator;

	private FormFactory $formFactory;

	private ParameterRepository $parameterRepository;

	public function __construct(
		ProductRepository $productRepository,
		WatcherRepository $watcherRepository,
		ParameterRepository $parameterRepository,
		CheckoutManager $checkoutManager,
		Shopper $shopper,
		IImageControlFactory $imageControlFactory,
		Translator $translator,
		FormFactory $formFactory
	)
	{
		parent::__construct($productRepository->getProducts()->where('this.hidden', false));

		$this->setDefaultOnPage(20);
		$this->setDefaultOrder('priority');
		$this->setAllowedOrderColumns(['price' => 'price']);
		$this->setItemCountCallback(function (ICollection $filteredSource) {
			// @TODO: cache?
			return $filteredSource->count();
		});

		$this->setAllowedRepositoryFilters(['category', 'tag', 'producer', 'related', 'recommended', 'q']);
		$this->addFilterExpression('priceFrom', function (ICollection $collection, $value): void {
			$collection->where('price >= :priceFrom', ['priceFrom' => (float)$value]);
		}, '');
		$this->addFilterExpression('priceTo', function (ICollection $collection, $value): void {
			$collection->where('price <= :priceTo', ['priceTo' => (float)$value]);
		}, '');
		$this->addFilterExpression('inStock', function (ICollection $collection, $value): void {
			if ($value) {
				$collection
					->join(['displayAmount' => 'eshop_displayamount'], 'displayAmount.uuid=this.fk_displayAmount')
					->where('fk_displayAmount IS NULL OR displayAmount.isSold = 0');
			}
		});
		$this->addFilterExpression('query', function (ICollection $collection, $value): void {
			$collection->filter(['q' => $value]);
		});

		$this->addFilterExpression('toners', function (ICollection $collection, $value): void {
			$collection->join(['related' => 'eshop_related'], 'this.uuid = related.fk_master');
			$collection->where('related.fk_slave', $value);
			$collection->where('related.fk_type = "tonerForPrinter"');
		});

		$this->addFilterExpression('compatiblePrinters', function (ICollection $collection, $value): void {
			$collection->join(['related' => 'eshop_related'], 'this.uuid = related.fk_slave');
			$collection->where('related.fk_master', $value);
			$collection->where('related.fk_type = "tonerForPrinter"');
		});

		$this->addFilterExpression('parameters', function (ICollection $collection, $groups): void {
			$suffix = $collection->getConnection()->getMutationSuffix();

			/** @var \Eshop\DB\Parameter[] $parameters */
			$parameters = $this->parameterRepository->getCollection()->toArray();

			if ($groups) {
				$query = '';

				foreach ($groups as $key => $group) {
					foreach ($group as $pKey => $parameter) {
						if ($parameters[$pKey]->type == 'list') {
							if (\count($parameter) == 0) {
								continue;
							}
							// list
							$implodedValues = "'" . \implode("','", $parameter) . "'";
							$query .= "(parametervalue.fk_parameter = '$pKey' AND parametervalue.metaValue IN ($implodedValues))";
							$query .= ' OR ';
						}elseif ($parameters[$pKey]->type == 'bool') {
							if($parameter === '1'){
								$query .= "(parametervalue.fk_parameter = '$pKey' AND parametervalue.metaValue = '1')";
								$query .= ' OR ';
							}
						} else {
							// text
							$query .= "(parametervalue.fk_parameter = '$pKey' AND parametervalue.content$suffix = '$parameter')";
							$query .= ' OR ';
						}
					}
				}

				if (\strlen($query) > 0) {
					$query = \substr($query, 0, -3);

					$collection
						->join(['parametervalue' => 'eshop_parametervalue'], 'this.uuid = parametervalue.fk_product')
						->where($query);

				}
			}
		});

		$this->productRepository = $productRepository;
		$this->watcherRepository = $watcherRepository;
		$this->shopper = $shopper;
		$this->checkoutManager = $checkoutManager;
		$this->imageControlFactory = $imageControlFactory;
		$this->translator = $translator;
		$this->formFactory = $formFactory;
		$this->parameterRepository = $parameterRepository;
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

		$this->template->render($this->template->getFile() ?: __DIR__ . '/productList.latte');
	}

	public function createComponentImage(): ImageControl
	{
		return $this->imageControlFactory->create();
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
		$filters = $this->getFilters()['parameters'] ?? [];
		$templateFilters = [];

		/** @var \Eshop\DB\Parameter[] $parameters */
		$parameters = $this->parameterRepository->getCollection()->toArray();

		foreach ($filters as $key => $group) {
			foreach ($group as $pKey => $parameter) {
				if ($parameters[$pKey]->type == 'list') {
					// list
					if (\count($parameter) == 0) {
						continue;
					}

					$allowed = \array_combine(\explode(';', $parameters[$pKey]->allowedKeys), \explode(';', $parameters[$pKey]->allowedValues));
					foreach ($parameter as $pvKey => $item) {
						$parameter[$pvKey] = $allowed[$item];
					}

					$templateFilters[$pKey] = $parameters[$pKey]->name . ': ' . \implode(', ', $parameter);
				} elseif ($parameters[$pKey]->type == 'bool') {
					// bool
					if($parameter === '1'){
						$templateFilters[$pKey] = $parameters[$pKey]->name;
					}
				} else {
					// text
					if ($parameter) {
						$templateFilters[$pKey] = $parameters[$pKey]->name . ': ' . $parameter;
					}
				}
			}
		}

		return $templateFilters;
	}
}
