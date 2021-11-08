<?php

declare(strict_types=1);

namespace Eshop\Controls;

use StORM\Collection;

interface IProductsFactory
{
	public function create(?array $order = null, ?Collection $source = null): ProductList;
}
