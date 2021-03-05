<?php

declare(strict_types=1);

namespace Eshop\Admin\Controls;

interface IProductFormFactory
{
	public function create(): ProductForm;
}