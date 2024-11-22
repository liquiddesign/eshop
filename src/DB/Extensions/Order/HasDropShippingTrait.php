<?php

namespace Eshop\DB\Extensions\Order;

trait HasDropShippingTrait
{
	/**
	 * @column
	 */
	public bool $dropShipping = false;
}
