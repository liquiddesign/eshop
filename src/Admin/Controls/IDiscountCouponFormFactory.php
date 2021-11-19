<?php

namespace Eshop\Admin\Controls;

use Eshop\DB\Discount;
use Eshop\DB\DiscountCoupon;

interface IDiscountCouponFormFactory
{
	public function create(?DiscountCoupon $discountCoupon, ?Discount $discount = null): DiscountCouponForm;
}