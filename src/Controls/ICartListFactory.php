<?php

declare(strict_types=1);

namespace Eshop\Controls;

use StORM\Collection;

interface ICartListFactory
{
	public function create(Collection $carts): CartList;
}
