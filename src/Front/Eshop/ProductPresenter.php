<?php

declare(strict_types=1);

namespace Eshop\Front\Eshop;

use Eshop\Admin\SettingsPresenter;
use Eshop\CompareManager;
use Eshop\Controls\BuyForm;
use Eshop\Controls\IProductsFactory;
use Eshop\Controls\IProductsFilterFactory;
use Eshop\Controls\ProductFilter;
use Eshop\Controls\ProductList;
use Eshop\DB\AttributeValueRepository;
use Eshop\DB\Category;
use Eshop\DB\CategoryRepository;
use Eshop\DB\FileRepository;
use Eshop\DB\Producer;
use Eshop\DB\ProducerRepository;
use Eshop\DB\Product;
use Eshop\DB\ProductRepository;
use Eshop\Front\FrontendPresenter;
use Forms\Form;
use Nette\Application\Attributes\Persistent;
use Nette\Application\BadRequestException;
use Nette\Application\Responses\FileResponse;
use Nette\DI\Attributes\Inject;
use Nette\Utils\Arrays;
use StORM\Exception\NotFoundException;
use Web\DB\SettingRepository;

abstract class ProductPresenter extends FrontendPresenter
{
	/** @var array<callable(\Eshop\DB\Watcher): bool> */
	public array $onProductWatched = [];

	/** @var array<callable(\Eshop\DB\Product): bool> */
	public array $onProductUnWatched = [];

	#[Inject]
	public IProductsFactory $productsFactory;
	
	#[Inject]
	public ProductRepository $productsRepository;
	
	#[Inject]
	public ProducerRepository $producerRepository;
	
	#[Inject]
	public CategoryRepository $categoryRepository;
	
	#[Inject]
	public FileRepository $fileRepository;

	#[Inject]
	public CompareManager $compareManager;

	#[Inject]
	public IProductsFilterFactory $productsFilterFactory;

	#[Inject]
	public AttributeValueRepository $attributeValueRepository;

	#[Inject]
	public SettingRepository $settingRepository;

	#[Persistent]
	public string $selectedCompareCategory;

	#[Persistent]
	public string|null $display = null;
	
	protected ?Category $category = null;
	
	protected ?Producer $producer = null;
	
	protected ?Product $product = null;
	
	protected ?string $query = null;

	public function createComponentProducts(): ProductList
	{
		$products = $this->productsFactory->create(null);
		$products->setAutoCanonicalize(true);
		$products->onAnchor[] = function (ProductList $products): void {
			$products->template->setFile(\dirname(__DIR__, 6) . '/app/Eshop/Controls/productList.latte');
		};

		$filterForm = $this->productsFilterFactory->create();
		$filterForm->onAnchor[] = function (ProductFilter $filterForm): void {
			$filterForm->template->setFile(\dirname(__DIR__, 6) . '/app/Eshop/Controls/productFilter.latte');
		};
		$filterForm->onFormSuccess[] = function (array $parameters): void {
			$this->getPresenter()->redirect('this', $parameters);
		};

		// replace native filter
		$products->addComponent($filterForm, 'filterForm');

		return $products;
	}
	
	public function actionList(
		?string $category = null,
		?string $producer = null,
		?string $ribbon = null,
		?string $query = null,
		?string $priceFrom = null,
		?string $priceTo = null,
		?string $attributeValue = null,
		?array $attributes = null
	): void {
		if ($this->shopperUser->getCatalogPermission() === 'none') {
			$this->error('You dont have permission to view catalog!', 403);
		}
		
		/** @var \Eshop\Controls\ProductList $products */
		$products = $this->getComponent('products');
		$filters = ['producer' => $producer, 'ribbon' => $ribbon, 'query' => $query];
		
		if ($category) {
			try {
				$categoryCollection = $this->categoryRepository->many()->where('this.uuid', $category);

				if ($this->shopsConfig->getSelectedShop()) {
					$categoryType = $this->settingRepository->getValueByName(SettingsPresenter::MAIN_CATEGORY_TYPE . '_' . $this->shopsConfig->getSelectedShop()->getPK());

					if ($categoryType) {
						$categoryCollection->where('this.fk_type', $categoryType);
					}
				}

				$this->category = $categoryCollection->first(true);
			} catch (NotFoundException $x) {
				throw new BadRequestException();
			}

			$filters['category'] = $this->category->path;
		}

		if ($producer) {
			$this->producer = $this->producerRepository->one($producer, true);
		}
		
		if ($query) {
			$this->query = $query;
			$filters['query'] = $query;
		}

		if ($priceFrom) {
			$filters['priceFrom'] = $priceFrom;
		}

		if ($priceTo) {
			$filters['priceTo'] = $priceTo;
		}

		if ($attributeValue) {
			$filters['attributes'] = [];
			$attributeValues = \explode(';', $attributeValue);

			foreach ($attributeValues as $attributeValue) {
				/** @var \Eshop\DB\AttributeValue $attributeValue */
				$attributeValue = $this->attributeValueRepository->one($attributeValue);

				$filters['attributes'][$attributeValue->getValue('attribute')][] = $attributeValue->getPK();
			}
		}

		if ($attributes) {
			$filters['attributes'] = $attributes;
		}
		
		$products->setFilters($filters);
	}
	
	public function renderList(?string $category = null, ?string $producer = null, ?string $ribbon = null): void
	{
		unset($category);
		unset($producer);
		unset($ribbon);

		/** @var \Eshop\Controls\ProductList $products */
		$products = $this->getComponent('products');
		$categories = $this->categoryRepository->getCategories();
		
		$this->template->category = $this->category;
		$this->template->display = $this->getDisplay($this->category);
		$this->template->perex = null;
		$this->template->content = null;
		$this->template->categories = [];
		$this->template->breadcrumb = [];
		
		if ($this->category) {
			$this->template->categories = $categories
				->where('path LIKE :path', ['path' => $this->category->path . '%'])
				->where('fk_ancestor', !$this->category->isBottom() ? $this->category : $this->category->getValue('ancestor'))
				->where('fk_type', $this->category->getValue('type'));
			$this->template->head = $this->category->name;
			$this->template->perex = $this->category->perex;
			$this->template->content = $this->category->content;
			$this->template->imageDir = Category::IMAGE_DIR;
			$this->template->imageFileName = $this->category->imageFileName;
			
			/** @var \Web\Controls\Breadcrumb $breadcrumb */
			$breadcrumb = $this['breadcrumb'];
			
			/** @var \Eshop\DB\Category $branch */
			foreach ($this->category->getFamilyTree() as $branchId => $branch) {
				$breadcrumb->addItem($branch->name, $branchId !== $this->category->getPK() ? $this->link('list', ['category' => $branchId]) : null);
			}
		}
		
		if ($this->producer) {
			$this->template->head = $this->producer->name;
			$this->template->perex = $this->producer->perex;
			$this->template->content = $this->producer->content;
			$this->template->imageDir = Producer::IMAGE_DIR;
			$this->template->imageFileName = $this->producer->imageFileName;
		}
		
		if ($this->query) {
			$this->template->head = $this->translator->translate('.searchQuery', 'Vyhledávací dotaz') . ': "' . $this->query . '"';
		}
		
		$this->template->paginator = $products->getPaginator();
	}
	
	public function actionDetail(string $product): void
	{
		if ($this->shopperUser->getCatalogPermission() === 'none') {
			$this->error('You dont have permission to view catalog!', 403);
		}

		/** @var \Eshop\Controls\ProductList $products */
		$products = $this->getComponent('products');

		try {
			/** @var \Eshop\DB\Product $product */
			$product = $this->productsRepository->getProducts()->where('this.uuid', $product)->filter(['hidden' => false])->first(true);
			$this->product = $product;
		} catch (NotFoundException $e) {
			$this->error('Product can\'t be viewed', 404);
		}

		$this->category = $this->product->getPrimaryCategory();
		
		$form = new BuyForm($this->product, $this->shopperUser);
		$this->addComponent($form, 'buyForm');
		$form->onSuccess[] = function ($form, $values): void {
			$form->getPresenter()->redirect('this');
		};
		
		$products->setFilters(['related' => ['category' => $this->product->getPrimaryCategory(), 'uuid' => $this->product->getPK()]]);
		$products->setDefaultOnPage(4);
	}
	
	public function handleAddToCart(string $product, int $amount): void
	{
		if (!$this->shopperUser->getBuyPermission()) {
			throw new BadRequestException();
		}
		
		$this->shopperUser->getCheckoutManager()->addItemToCart($this->productRepository->getProduct($product), null, $amount);
		
		$this->redirect('this');
	}
	
	public function renderDetail(string $product): void
	{
		/** @var \Web\Controls\Breadcrumb $breadcrumb */
		$breadcrumb = $this['breadcrumb'];

		if ($this->category) {
			foreach ($this->category->getFamilyTree() as $branchId => $branch) {
				/** @var \Eshop\DB\Category $branch */

				$breadcrumb->addItem($branch->name, $this->link('list', ['category' => $branchId]));
			}
		}

		$breadcrumb->addItem($this->product->name);
		
		$this->template->product = $this->product;
		$this->template->category = $this->category;
		$this->template->isInCompare = $this->compareManager->isProductInList($product);
		$this->template->similar = $this->productsRepository->getSimilarProductsByProduct($product);
		$this->template->loyaltyProgramPointsGain = null;

		if (!($loyaltyProgram = ($this->shopperUser->getCustomer() ? $this->shopperUser->getCustomer()->loyaltyProgram : null))) {
			return;
		}

		$this->template->loyaltyProgramPointsGain = $this->product->getLoyaltyProgramPointsGain($loyaltyProgram);
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

			Arrays::invoke($this->onProductWatched, $watcher);
		}
		
		$this->redirect('this');
	}
	
	public function handleUnWatchIt(string $product): void
	{
		if ($customer = $this->shopperUser->getCustomer()) {
			$this->watcherRepository->many()
				->where('fk_product', $product)
				->where('fk_customer', $customer)
				->delete();

			Arrays::invoke($this->onProductUnWatched, $this->productRepository->one($product, true));
		}

		$this->redirect('this');
	}
	
	public function handleDownloadFile(string $fileId): void
	{
		/** @var \Eshop\DB\File $file */
		$file = $this->fileRepository->one($fileId);
		
		$filePath = $this->userDir . \DIRECTORY_SEPARATOR . Product::FILE_DIR . \DIRECTORY_SEPARATOR . $file->fileName;
		
		if (!\is_file($filePath)) {
			$this->flashMessage($this->translator->translate('Product.cantDownload', 'Soubor se nepodařilo stáhnout'), 'danger');
			
			$this->redirect('this');
		}
		
		$response = new FileResponse($filePath, $file->originalFileName);
		
		$this->sendResponse($response);
	}

	public function actionCompare(): void
	{
		/** @var \Forms\Form $categoryForm */
		$categoryForm = $this->getComponent('categoryForm');
		$categories = $this->compareManager->getCategories();

		if (isset($this->selectedCompareCategory) && \Nette\Utils\Arrays::contains(\array_keys($categories), $this->selectedCompareCategory)) {
			$categoryForm->setDefaults(['category' => $this->selectedCompareCategory]);
		} else {
			$this->selectedCompareCategory = \Nette\Utils\Arrays::first(\array_keys($categories));
		}
	}

	public function renderCompare(): void
	{
		$this->template->categories = $this->compareManager->getParsedProductsWithPrimaryCategories($this->selectedCompareCategory);
	}

	public function handleAddToCompare(string $product): void
	{
		$this->compareManager->addProductToCompare($product);
		$this->redirect('this');
	}

	public function handleRemoveFromCompare(string $product): void
	{
		$this->compareManager->removeProductFromCompare($product);
		$this->redirect('this');
	}

	public function createComponentCategoryForm(): Form
	{
		$form = $this->formFactory->create();

		$categories = $this->compareManager->getCategoriesNames();

		$form->addSelect('category', $this->translator->translate('productCompare.category', 'Kategorie'), $categories);
		$form->addSubmit('submit', $this->translator->translate('.submit', $this->translator->translate('.send', 'Odeslat')))->setHtmlAttribute('class', 'btn btn-primary');

		$form->onSuccess[] = function (Form $form): void {
			$values = $form->getValues('array');

			$this->selectedCompareCategory = $values['category'];
			$this->redirect('this');
		};

		return $form;
	}

	/**
	 * @param \Eshop\DB\Category|null $category
	 * @return 'card'|'row'
	 */
	protected function getDisplay(Category|null $category = null): string
	{
		if ($this->display) {
			return $this->display;
		}

		if ($category?->defaultViewType) {
			return $category->defaultViewType;
		}

		return 'card';
	}
}
