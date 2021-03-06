<?php

declare(strict_types=1);

namespace Eshop\Controls;

interface IProductsFactory
{
	public function create(): ProductList;
}