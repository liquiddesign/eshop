<?php

declare(strict_types=1);

namespace Eshop\Admin\Controls;

interface IProductFormFactory
{
	/**
	 * @param \Eshop\DB\Product|string|null $product
	 * @param array<mixed> $configuration
	 * @param callable(\Eshop\DB\Product|null $product): array<\Eshop\DB\Pricelist>|null $onRenderGetPriceLists
	 */
	public function create(\Eshop\DB\Product|string|null $product = null, array $configuration = [], callable|null $onRenderGetPriceLists = null): ProductForm;
}
