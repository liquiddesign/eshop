<?php

namespace Eshop\Admin\Configs;

class ProductFormAutoPriceConfig
{
	/**
	 * Nothing to calculate
	 */
	public const NONE = 'none';

	/**
	 * Edit only price without VAT and calculate price with VAT
	 */
	public const WITH_VAT = 'vat';

	/**
	 * Edit only price with VAT and calculate price without VAT
	 */
	public const WITHOUT_VAT = 'withoutVat';
}
