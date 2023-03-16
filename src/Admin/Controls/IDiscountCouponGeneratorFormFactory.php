<?php

namespace Eshop\Admin\Controls;

use Eshop\DB\Discount;

interface IDiscountCouponGeneratorFormFactory
{
	public function create(Discount $discount): DiscountCouponGeneratorForm;
}
