<?php

namespace Eshop\Services\Product;

use Base\Bridges\AutoWireService;
use Base\ShopsConfig;
use Eshop\DB\Category;
use Eshop\DB\CategoryType;
use Eshop\DB\Product;
use Eshop\DB\ProductRepository;
use Eshop\ShopperUser;

class ProductGettersService implements AutoWireService
{
	public function __construct(
		protected readonly ProductRepository $productRepository,
		protected readonly ShopsConfig $shopsConfig,
		protected readonly ShopperUser $shopperUser,
	) {
	}

	/**
	 * Get product primary category based on category tree. If no tree supplied, use main category tree based on current shop.
	 * If no shops available, act like basic primary category getter.
	 */
	public function getPrimaryCategory(Product $product, CategoryType|null $categoryType = null): Category|null
	{
		$categoryType ??= $this->shopperUser->getMainCategoryType();

		return $product->getPrimaryCategory($categoryType);
	}
}
