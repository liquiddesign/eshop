<?php

declare(strict_types=1);

namespace Eshop\Controls;

use Eshop\DB\Product;

interface IBuyFormFactory
{
	public function create(Product $product): BuyForm;
}
