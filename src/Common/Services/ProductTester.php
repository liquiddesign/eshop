<?php

namespace Eshop\Common\Services;

use Base\ShopsConfig;
use Eshop\DB\CountryRepository;
use Eshop\DB\CurrencyRepository;
use Eshop\DB\Customer;
use Eshop\DB\CustomerGroup;
use Eshop\DB\Price;
use Eshop\DB\PricelistRepository;
use Eshop\DB\PriceRepository;
use Eshop\DB\Product;
use Eshop\DB\ProductRepository;
use Eshop\DB\VisibilityListItem;
use Eshop\DB\VisibilityListItemRepository;
use Eshop\DB\VisibilityListRepository;
use Eshop\DevelTools;
use Web\DB\PageRepository;

readonly class ProductTester
{
	public function __construct(
		protected ProductRepository $productRepository,
		protected PricelistRepository $pricelistRepository,
		protected CountryRepository $countryRepository,
		protected CurrencyRepository $currencyRepository,
		protected VisibilityListRepository $visibilityListRepository,
		protected PriceRepository $priceRepository,
		protected VisibilityListItemRepository $visibilityListItemRepository,
		protected ShopsConfig $shopsConfig,
		protected PageRepository $pageRepository,
	) {
	}

	/**
	 * @param \Eshop\DB\Product $product
	 * @param \Eshop\DB\Customer $customer
	 * @return array{
	 *     fastTest: bool,
	 *     availablePriceLists: array<\Eshop\DB\Pricelist>,
	 *     availableVisibilityLists: array<\Eshop\DB\VisibilityList>,
	 *     visibilityList: bool,
	 *     usedPrice: \Eshop\DB\Price|null,
	 *     usedVisibilityList: \Eshop\DB\VisibilityList|null,
	 *  }
	 * @throws \StORM\Exception\NotFoundException
	 */
	public function testProductByCustomer(Product $product, Customer $customer): array
	{
		$country = $this->countryRepository->one('CZ', true);
		$currency = $this->currencyRepository->one('CZK', true);

		$priceLists = $this->pricelistRepository->getCustomerPricelists($customer, $currency, $country)->toArray();
		$visibilityLists = $customer->getVisibilityLists()->where('this.hidden', false)->orderBy(['this.priority' => 'ASC', 'this.uuid' => 'ASC']);
		$visibilityLists = $visibilityLists->toArray();

		$productFromGetProducts = $this->productRepository->getProducts($priceLists, $customer, visibilityLists: $visibilityLists, currency: $currency)->where('this.uuid', $product->getPK());
		DevelTools::bdumpCollection($productFromGetProducts);
		$productFromGetProducts = $productFromGetProducts->first();

		$usedPrice = $this->getUsedPrice($priceLists, $product);
		$usedVisibilityListItem = $this->getVisibilityListItem($visibilityLists, $product);

		/** @var \Web\DB\Page|null $page */
		$page = $this->pageRepository->getPageByTypeAndParams('product_detail', null, parameters: ['product' => $product->getPK()], selectedShop: $this->shopsConfig->getSelectedShop());

		return [
			'fastTest' => (bool) $productFromGetProducts,
			'availablePriceLists' => $priceLists,
			'availableVisibilityLists' => $visibilityLists,
			'usedPrice' => $usedPrice,
			'usedVisibilityList' => $usedVisibilityListItem?->visibilityList,
			'visibilityList' => (bool) $usedVisibilityListItem,
			'hidden' => $usedVisibilityListItem && !$usedVisibilityListItem->hidden,
			'page' => $page,
		];
	}

	/**
	 * @param \Eshop\DB\Product $product
	 * @param \Eshop\DB\CustomerGroup $customerGroup
	 * @return array{
	 *     fastTest: bool,
	 *     availablePriceLists: array<\Eshop\DB\Pricelist>,
	 *     availableVisibilityLists: array<\Eshop\DB\VisibilityList>,
	 *     visibilityList: bool,
	 *     usedPrice: \Eshop\DB\Price|null,
	 *     usedVisibilityList: \Eshop\DB\VisibilityList|null,
	 *  }
	 * @throws \StORM\Exception\NotFoundException
	 */
	public function testProductByGroup(Product $product, CustomerGroup $customerGroup): array
	{
		$productFromGetProducts = $this->productRepository->getProducts(customerGroup: $customerGroup)->where('this.uuid', $product->getPK())->first();
		$priceLists = $customerGroup->getDefaultPricelists()->toArray();
		$visibilityLists = $customerGroup->getDefaultVisibilityLists()->toArray();

		$usedPrice = $this->getUsedPrice($priceLists, $product);
		$usedVisibilityListItem = $this->getVisibilityListItem($visibilityLists, $product);

		/** @var \Web\DB\Page|null $page */
		$page = $this->pageRepository->getPageByTypeAndParams('product_detail', null, parameters: ['product' => $product->getPK()], selectedShop: $this->shopsConfig->getSelectedShop());

		return [
			'fastTest' => (bool) $productFromGetProducts,
			'availablePriceLists' => $priceLists,
			'availableVisibilityLists' => $visibilityLists,
			'usedPrice' => $usedPrice,
			'usedVisibilityList' => $usedVisibilityListItem?->visibilityList,
			'visibilityList' => (bool) $usedVisibilityListItem,
			'hidden' => $usedVisibilityListItem && !$usedVisibilityListItem->hidden,
			'page' => $page,
		];
	}

	/**
	 * @param array<\Eshop\DB\Pricelist> $priceLists
	 * @param \Eshop\DB\Product $product
	 * @throws \StORM\Exception\NotFoundException
	 */
	private function getUsedPrice(array $priceLists, Product $product): ?Price
	{
		$usedPrice = null;

		foreach ($priceLists as $priceList) {
			/** @var \Eshop\DB\Price|null $price */
			$price = $this->priceRepository->many()
				->where('this.fk_product', $product->getPK())
				->where('this.fk_pricelist', $priceList->getPK())
				->first();

			if ($price && !$usedPrice) {
				$usedPrice = $price;
			}

			/** @phpstan-ignore-next-line */
			$priceLists[$priceList->getPK()]->price = $price;
		}

		return $usedPrice;
	}

	/**
	 * @param array<\Eshop\DB\VisibilityList> $visibilityLists
	 * @param \Eshop\DB\Product $product
	 * @throws \StORM\Exception\NotFoundException
	 */
	private function getVisibilityListItem(array $visibilityLists, Product $product): ?VisibilityListItem
	{
		$usedVisibilityListItem = null;

		foreach ($visibilityLists as $visibilityList) {
			/** @var \Eshop\DB\VisibilityListItem|null $visibilityListItem */
			$visibilityListItem = $this->visibilityListItemRepository->many()->where('this.fk_product', $product->getPK())->where('this.fk_visibilityList', $visibilityList->getPK())->first();

			if ($visibilityListItem && !$usedVisibilityListItem) {
				$usedVisibilityListItem = $visibilityListItem;
			}

			/** @phpstan-ignore-next-line */
			$visibilityLists[$visibilityList->getPK()]->visibilityListItem = $visibilityListItem;
		}

		return $usedVisibilityListItem;
	}
}
