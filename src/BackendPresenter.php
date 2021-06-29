<?php
declare(strict_types=1);

namespace Eshop;

use Eshop\DB\Product;
use Eshop\DB\ProductRepository;

class BackendPresenter extends \Admin\BackendPresenter
{
	/** @inject */
	public ProductRepository $productRepository;

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
}