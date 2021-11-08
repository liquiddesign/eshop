<?php

declare(strict_types=1);

namespace Eshop\Controls;

interface IProductsFilterFactory
{
	public function create(): ProductFilter;
}
