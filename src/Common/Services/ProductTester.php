<?php

namespace Eshop\Common\Services;

use Base\ShopsConfig;
use Eshop\DB\CountryRepository;
use Eshop\DB\CurrencyRepository;
use Eshop\DB\Customer;
use Eshop\DB\CustomerGroup;
use Eshop\DB\PricelistRepository;
use Eshop\DB\PriceRepository;
use Eshop\DB\Product;
use Eshop\DB\ProductRepository;
use Eshop\DB\VisibilityListItemRepository;
use Eshop\DB\VisibilityListRepository;
use Web\DB\PageRepository;

class ProductTester
{
	public function __construct(
		protected readonly ProductRepository $productRepository,
		protected readonly PricelistRepository $pricelistRepository,
		protected readonly CountryRepository $countryRepository,
		protected readonly CurrencyRepository $currencyRepository,
		protected readonly VisibilityListRepository $visibilityListRepository,
		protected readonly PriceRepository $priceRepository,
		protected readonly VisibilityListItemRepository $visibilityListItemRepository,
		protected readonly ShopsConfig $shopsConfig,
		protected readonly PageRepository $pageRepository,
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
	 *     usedPriceList: \Eshop\DB\Pricelist|null,
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
		$this->shopsConfig->filterShopsInShopEntityCollection($visibilityLists);
		$visibilityLists = $visibilityLists->toArray();

		$productFromGetProducts = $this->productRepository->getProducts($priceLists, $customer, visibilityLists: $visibilityLists, currency: $currency)->where('this.uuid', $product->getPK())->first();

		$usedPrice = null;

		foreach ($priceLists as $priceList) {
			/** @var \Eshop\DB\Price|null $price */
			$price = $this->priceRepository->many()->where('this.fk_product', $product->getPK())->where('this.fk_pricelist', $priceList->getPK())->first();

			if ($price && !$usedPrice) {
				$usedPrice = $price;
			}

			/** @phpstan-ignore-next-line */
			$priceLists[$priceList->getPK()]->price = $price;
		}

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

		/** @var \Web\DB\Page|null $page */
		$page = $this->pageRepository->getPageByTypeAndParams('product_detail', null, parameters: ['product' => $product->getPK()], selectedShop: $this->shopsConfig->getSelectedShop());

		return [
			'fastTest' => (bool) $productFromGetProducts,
			'availablePriceLists' => $priceLists,
			'availableVisibilityLists' => $visibilityLists,
			'usedPriceList' => $usedPrice?->pricelist,
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
	 *  }
	 * @throws \StORM\Exception\NotFoundException
	 */
	public function testProductByGroup(Product $product, CustomerGroup $customerGroup): array
	{
		$productFromGetProducts = $this->productRepository->getProducts(customerGroup: $customerGroup)->where('this.uuid', $product->getPK())->first();

		return [
			'fastTest' => (bool) $productFromGetProducts,
		];
	}
}
