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

	public function handleGetProductsForSelect2(?string $q = null, ?int $page = null)
	{
		if (!$q) {
			$this->payload->results = [];
			$this->sendPayload();
		}

		$suffix = $this->productRepository->getConnection()->getMutationSuffix();

		/** @var Product[] $products */
		$products = $this->productRepository->getCollection(true)
			->where("this.name$suffix LIKE :q OR this.code = :exact OR this.ean = :exact", ['q' => "%$q%", 'exact' => $q])
			->setPage($page ?? 1, 5)
			->toArrayOf('name');

		$results = [];

		foreach ($products as $pk => $name) {
			$results[] = [
				'id' => $pk,
				'text' => $name
			];
		}

		$this->payload->results = $results;
		$this->payload->pagination = ['more' => \count($products) === 5];

		$this->sendPayload();
	}

	public function handleGetTonerProductsForSelect2(?string $q = null, ?Product $product = null, ?int $page = null)
	{
		if (!$q) {
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
			->where("this.name$suffix LIKE :q OR this.code = :exact OR this.ean = :exact", ['q' => "%$q%", 'exact' => $q])
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