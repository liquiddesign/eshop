<?php

namespace Eshop;

interface CartManagerFactory
{
	public function create(ShopperUser $shopperUser): CartManager;
}
