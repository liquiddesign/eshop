<?php

declare(strict_types=1);

namespace Eshop\Controls;

use Eshop\DB\Order;

interface IOrderItemListFactory
{
	public function create(Order $order): OrderItemList;
}