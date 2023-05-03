<?php

declare(strict_types=1);

namespace Eshop\Front\Web;

use Eshop\Controls\IProductsFactory;
use Eshop\Controls\ProductList;
use Eshop\DB\CategoryRepository;
use Eshop\DB\DeliveryDiscountRepository;
use Eshop\DB\DeliveryTypeRepository;
use Eshop\DB\DiscountRepository;
use Eshop\DB\PaymentTypeRepository;
use Eshop\DB\ProducerRepository;
use Eshop\Front\FrontendPresenter;
use Pages\Pages;
use Web\DB\HomepageSlideRepository;
use Web\DB\MenuItemRepository;
use Web\DB\NewsRepository;
use Web\DB\SettingRepository;

abstract class IndexPresenter extends FrontendPresenter
{
	#[\Nette\DI\Attributes\Inject]
	public IProductsFactory $productsFactory;

	#[\Nette\DI\Attributes\Inject]
	public CategoryRepository $categoryRepo;
	
	#[\Nette\DI\Attributes\Inject]
	public HomepageSlideRepository $homepageSlideRepository;
	
	#[\Nette\DI\Attributes\Inject]
	public ProducerRepository $producerRepository;
	
	#[\Nette\DI\Attributes\Inject]
	public NewsRepository $newsRepository;
	
	#[\Nette\DI\Attributes\Inject]
	public DeliveryTypeRepository $deliveryTypeRepository;
	
	#[\Nette\DI\Attributes\Inject]
	public PaymentTypeRepository $paymentTypeRepository;
	
	#[\Nette\DI\Attributes\Inject]
	public DiscountRepository $discountRepository;
	
	#[\Nette\DI\Attributes\Inject]
	public DeliveryDiscountRepository $deliveryDiscountRepository;
	
	#[\Nette\DI\Attributes\Inject]
	public SettingRepository $settingsRepository;
	
	#[\Nette\DI\Attributes\Inject]
	public MenuItemRepository $menuRepository;
	
	#[\Nette\DI\Attributes\Inject]
	public Pages $pages;

	public function createComponentProducts(): ProductList
	{
		return $this->productsFactory->create();
	}

	public function actionDefault(): void
	{
		/** @var \Eshop\Controls\ProductList $products */
		$products = $this->getComponent('products');
		$products->setDefaultOrder('priority');
		$products->setDefaultOnPage(8);
		$products->setFilters(['recommended' => 1]);
	}

	public function renderDefault(): void
	{
		$this->template->recommendedDeliveryTypes = $this->deliveryTypeRepository->getCollection()->where('recommended', true);
		$this->template->recommendedPaymentTypes = $this->paymentTypeRepository->getCollection()->where('recommended', true);
		$this->template->deliveryDiscount = $this->deliveryDiscountRepository->getDeliveryDiscount($this->shopperUser->getCurrency());
		$this->template->recommendedDiscounts = $this->discountRepository->getActiveDiscounts()->where('recommended', true);
		$this->template->recommendedProducers = $this->producerRepository->getCollection()->where('recommended', true);
		$this->template->recommendedCategories = $this->categoryRepo->getCategories()->where('recommended', true)->setTake(15);
		$this->template->homepageSlides = $this->homepageSlideRepository->getCollection();
		$this->template->supportMenu = $this->menuRepository->getCollection()->where('type', 'support');
		$this->template->settings = $this->settingsRepository->getCollection()->setIndex('name')->toArrayOf('value');
	}

	public function actionNewsletter(): void
	{
		$this->template->products = $this->productRepository->many()->where('this.hidden', false);
	}

	protected function startup(): void
	{
		parent::startup();

		if (!$this->getParameter('cleanCache')) {
			return;
		}

		$this->cleanCache();
		$this->redirect('this');
	}
}
