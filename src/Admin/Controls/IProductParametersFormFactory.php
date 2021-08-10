<?php

declare(strict_types=1);

namespace Eshop\Admin\Controls;

use Eshop\DB\Product;

/**
 * Interface IProductParametersFormFactory
 * @package Eshop\Admin\Controls
 * @deprecated
 */
interface IProductParametersFormFactory
{
	public function create(Product $product): ProductParametersForm;
}