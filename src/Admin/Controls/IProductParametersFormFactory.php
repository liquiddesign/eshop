<?php

declare(strict_types=1);

namespace Eshop\Admin\Controls;

use Eshop\DB\Product;

interface IProductParametersFormFactory
{
	public function create(Product $product): ProductParametersForm;
}