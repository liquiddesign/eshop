<?php

declare(strict_types=1);

namespace Eshop\Admin\Controls;

interface IProductFormFactory
{
	/**
	 * @param \Eshop\DB\Product|string|null $product
	 * @param mixed[] $configuration
	 * @return \Eshop\Admin\Controls\ProductForm
	 */
	public function create($product = null, array $configuration = []): ProductForm;
}