<?php

declare(strict_types=1);

namespace Eshop;

/**
 * Buy exception.
 */
class BuyException extends \Exception
{
	public const NOT_FOR_SELL = 1;
	public const INVALID_AMOUNT = 2;
	public const INVALID_CURRENCY = 3;
	public const PERMISSION_DENIED = 4;
}
