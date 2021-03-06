<?php

declare(strict_types=1);

namespace Eshop\Controls;

interface IOrderListFactory
{
	public function create(): OrderList;
}