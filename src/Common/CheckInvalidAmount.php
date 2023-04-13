<?php

namespace Eshop\Common;

enum CheckInvalidAmount
{
	/**
	 * No checks
	 */
	case NO_CHECK;
	/**
	 * Check amount but don't throw exception if invalid
	 */
	case CHECK_NO_THROW;
	/**
	 * Check amount and throw exception if invalid
	 */
	case CHECK_THROW;

	/**
	 * Set default amount of product if supplied amount is invalid
	 */
	case SET_DEFAULT_AMOUNT;
}
