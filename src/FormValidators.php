<?php

declare(strict_types=1);

namespace Eshop;

use Nette\Forms\IControl;

class FormValidators
{
	public static function isPercent(IControl $control): bool
	{
		return $control->getValue() >= 0 && $control->getValue() <= 100;
	}

	public static function isPercentNoMax(IControl $control): bool
	{
		return $control->getValue() >= 0;
	}

	public static function isProductExists(IControl $control, array $args): bool
	{
		/** @var \Eshop\DB\ProductRepository $repository */
		[$repository] = $args;
		$value = $control->getValue();

		return (bool) $repository->getProductByCodeOrEAN($value);
	}

	/**
	 * @param \Nette\Forms\IControl $control
	 * @param array $args Repository on index 0
	 */
	public static function isMultipleProductsExists(IControl $control, array $args): bool
	{
		try {
			$values = \explode(';', $control->getValue());
		} catch (\Exception $e) {
			return false;
		}

		/** @var \Eshop\DB\ProductRepository $repository */
		[$repository] = $args;

		foreach ($values as $value) {
			if (!$repository->getProductByCodeOrEAN($value)) {
				return false;
			}
		}

		return true;
	}

	public static function amountProductCheck(IControl $control, array $args): bool
	{
		/** @var \Eshop\DB\ProductRepository $repository */
		[$repository, $amountRepo, $store] = $args;
		$value = $control->getValue();

		$product = $repository->getProductByCodeOrEAN($value);

		if (!$product) {
			return false;
		}

		return $amountRepo->many()
				->where('fk_product', $product->getPK())
				->where('fk_store', $store->getPK())
				->count() === 0;
	}
}
