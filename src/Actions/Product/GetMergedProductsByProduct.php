<?php

namespace Eshop\Actions\Product;

use Base\Bridges\AutoWireAction;
use Eshop\DB\Product;
use Eshop\DB\ProductRepository;

readonly class GetMergedProductsByProduct implements AutoWireAction
{
	public function __construct(protected ProductRepository $productRepository)
	{
	}

	/**
	 * Return all descendant products
	 * If $onlyDescendants is false, return all descendant and ascendant products
	 * If $includeDescendantsOfAscendants is true, return all descendant and ascendant products and their descendants. Only works if $onlyDescendants is false.
	 * @return array<\Eshop\DB\Product>
	 */
	public function execute(Product $product, bool $onlyDescendants = true, bool $includeDescendantsOfAscendants = false): array
	{
		$down = $this->doGetAllMergedProducts($product);

		if ($onlyDescendants) {
			return $down;
		}

		$up = [];

		while ($masterProduct = $product->masterProduct) {
			$up[$masterProduct->getPK()] = $masterProduct;

			if ($includeDescendantsOfAscendants) {
				$up = \array_merge($up, $this->doGetAllMergedProducts($masterProduct));
			}

			$product = $masterProduct;
		}

		return \array_merge(\array_reverse($up), $down);
	}

	/**
	 * @return array<\Eshop\DB\Product>
	 */
	protected function doGetAllMergedProducts(\Eshop\DB\Product $product): array
	{
		$products = [];

		foreach ($product->slaveProducts as $mergedProduct) {
			$products[$mergedProduct->getPK()] = $mergedProduct;

			$products = \array_merge($products, $this->doGetAllMergedProducts($mergedProduct));
		}

		return $products;
	}
}
