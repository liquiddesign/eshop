<?php

declare(strict_types=1);

namespace Eshop\Controls;

interface ICartItemListFactory
{
	public function create(): CartItemList;
}