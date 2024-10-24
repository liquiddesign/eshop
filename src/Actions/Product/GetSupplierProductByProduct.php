<?php

namespace Eshop\Actions\Product;

use Base\BaseAction;
use Eshop\Actions\Supplier\GetSupplier;
use Eshop\DB\Product;
use Eshop\DB\Supplier;
use Eshop\DB\SupplierProduct;
use Eshop\DB\SupplierProductRepository;

class GetSupplierProductByProduct extends BaseAction
{
	public function __construct(
		private readonly GetMergedProductsByProduct $getMergedProductsByProduct,
		private readonly GetSupplier $getSupplier,
		private readonly SupplierProductRepository $supplierProductRepository
	) {
	}

	public function execute(Product $product, Supplier|string $supplier): SupplierProduct|null
	{
		$index = $product->getPK() . '-' . ($supplier instanceof Supplier ? $supplier->getPK() : $supplier);

		return $this->getLocalCachedOutput($index, function () use ($product, $supplier): SupplierProduct|null {
			$supplier = $supplier instanceof Supplier ? $supplier : $this->getSupplier->execute($supplier);

			if (!$supplier) {
				return null;
			}

			$supplierProduct = $this->supplierProductRepository->many()
				->where('this.fk_product', $product->getPK())
				->where('this.fk_supplier', $supplier->getPK())
				->first();

			if ($supplierProduct) {
				return $supplierProduct;
			}

			$merged = $this->getMergedProductsByProduct->execute($product);

			foreach ($merged as $product) {
				$supplierProduct = $this->supplierProductRepository->many()
					->where('this.fk_product', $product->getPK())
					->where('this.fk_supplier', $supplier->getPK())
					->first();

				if ($supplierProduct) {
					return $supplierProduct;
				}
			}

			return null;
		});
	}
}
