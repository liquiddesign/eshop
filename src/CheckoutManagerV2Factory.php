<?php

namespace Eshop;

interface CheckoutManagerV2Factory
{
	public function create(ShopperUser $shopperUser): CheckoutManagerV2;
}
