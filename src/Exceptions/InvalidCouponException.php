<?php

namespace Eshop\Exceptions;

class InvalidCouponException extends \Exception
{
	public const NOT_FOUND = 0;
	public const NOT_ACTIVE = 1;

	public const INVALID_CONDITIONS = 2;
	public const MAX_USAGE = 3;

	public const LIMITED_TO_EXCLUSIVE_CUSTOMER = 4;
	public const LOW_CART_PRICE = 5;
	public const HIGH_CART_PRICE = 6;

	public const INVALID_CURRENCY = 7;

	public const INVALID_CONDITIONS_CATEGORY = 8;
}
