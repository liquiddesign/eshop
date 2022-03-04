<?php

namespace Eshop\Admin\Controls;

use Eshop\DB\ApiGeneratorDiscountCoupon;

interface IApiGeneratorDiscountCouponFormFactory
{
	public function create(?ApiGeneratorDiscountCoupon $apiGeneratorDiscountCoupon): ApiGeneratorDiscountCouponForm;
}
