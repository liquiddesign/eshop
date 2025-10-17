<?php

namespace Eshop\DB\Extensions\Order;

/** @phpstan-ignore trait.unused */
trait HasDropShippingTrait
{
	/**
	 * @column
	 */
	public bool $dropShipping = false;
}
