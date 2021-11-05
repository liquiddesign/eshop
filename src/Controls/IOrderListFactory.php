<?php

declare(strict_types=1);

namespace Eshop\Controls;

use StORM\Collection;

interface IOrderListFactory
{
	public function create(?Collection $orders = null): OrderList;
}
