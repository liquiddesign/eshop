<?php
declare(strict_types=1);

namespace Eshop\Admin\Controls;

use Eshop\DB\Product;

/** @deprecated */
interface IProductSetFormFactory
{
	public function create(Product $product): ProductSetForm;
}
