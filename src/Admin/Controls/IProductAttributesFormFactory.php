<?php

declare(strict_types=1);

namespace Eshop\Admin\Controls;

use Eshop\DB\Product;

interface IProductAttributesFormFactory
{
	public function create(Product $product): ProductAttributesForm;
}