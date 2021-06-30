<?php
declare(strict_types=1);

namespace Eshop;

use Eshop\DB\CategoryRepository;
use Eshop\DB\Product;
use Eshop\DB\ProductRepository;

class BackendPresenter extends \Admin\BackendPresenter
{
	/** @inject */
	public ProductRepository $productRepository;

	/** @inject */
	public CategoryRepository $categoryRepository;

	public function handleGetProductsForSelect2(?string $q = null)
	{
		if (!$q || \strlen($q) < 3) {
			$this->payload->result = [];
			$this->sendPayload();
		}

		$suffix = $this->productRepository->getConnection()->getMutationSuffix();

		/** @var Product[] $products */
		$products = $this->productRepository->getCollection(true)->where("this.name$suffix LIKE :q", ['q' => "%$q%"])->setTake(5)->toArray();
		$payload = [];

		foreach ($products as $product) {
			$payload[] = [
				'id' => $product->getPK(),
				'text' => $product->name
			];
		}

		$this->payload->results = $payload;
		$this->sendPayload();
	}

	public function handleGetTonerProductsForSelect2(?string $q = null, ?Product $product = null)
	{
		if (!$q || \strlen($q) < 3) {
			$this->payload->result = [];
			$this->sendPayload();
		}

		/** @var \Eshop\DB\Category $printerCategory */
		$printerCategory = $this->categoryRepository->one('printers');

		$suffix = $this->productRepository->getConnection()->getMutationSuffix();

		$printers = $this->productRepository->getCollection(true)
			->join(['nxnCategory' => 'eshop_product_nxn_eshop_category'], 'this.uuid = nxnCategory.fk_product')
			->join(['category' => 'eshop_category'], 'nxnCategory.fk_category = category.uuid')
			->where('category.path LIKE :categoryPath', ['categoryPath' => $printerCategory->path . '%'])
			->where("this.name$suffix LIKE :q", ['q' => "%$q%"])
			->setTake(5);

		if ($product) {
			$printers->where('this.uuid != :thisProduct', ['thisProduct' => $product->getPK()]);
		}

		$payload = [];

		foreach ($printers as $product) {
			$payload[] = [
				'id' => $product->getPK(),
				'text' => $product->name
			];
		}

		$this->payload->results = $payload;
		$this->sendPayload();
	}
}