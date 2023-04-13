<?php

declare(strict_types=1);

namespace Eshop\Admin\Controls;

interface IProductFormFactory
{
	/**
	 * @param \Eshop\DB\Product|string|null $product
	 * @param array<mixed> $configuration
	 */
	public function create($product = null, array $configuration = []): ProductForm;
}
