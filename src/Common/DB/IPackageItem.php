<?php

namespace Eshop\Common\DB;

use Eshop\DB\Product;

interface IPackageItem
{
	public function getProduct(): Product|null;

	public function getAmount(): int;
}
