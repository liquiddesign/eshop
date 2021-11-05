<?php

declare(strict_types=1);

namespace Eshop\Controls;

interface ICouponFormFactory
{
	public function create(): CouponForm;
}
