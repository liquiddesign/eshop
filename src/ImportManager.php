<?php
declare(strict_types=1);

namespace Eshop;


use Eshop\DB\PricelistRepository;
use Eshop\DB\ProductRepository;
use Nette\NotImplementedException;

class ImportManager
{
	protected ProductRepository $productRepository;

	protected PricelistRepository $pricelistRepository;

	public function __construct(ProductRepository $productRepository, PricelistRepository $pricelistRepository)
	{
		$this->productRepository = $productRepository;
		$this->pricelistRepository = $pricelistRepository;
	}

	public function import()
	{
		throw new NotImplementedException();
	}
}