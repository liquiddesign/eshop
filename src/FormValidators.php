<?php

declare(strict_types=1);

namespace Eshop;

use Eshop\DB\ProductRepository;
use Nette\Forms\IControl;

class FormValidators
{
	public static function isPercent(IControl $control): bool
	{
		return ($control->getValue() >= 0 && $control->getValue() <= 100);
	}
	
	public static function isPercentNoMax(IControl $control): bool
	{
		return $control->getValue() >= 0;
	}
	
	public static function isProductExists(IControl $control, array $args): bool
	{
		/** @var ProductRepository $repository */
		[$repository] = $args;
		$value = $control->getValue();
		
		return (bool) $repository->getProductByCodeOrEAN($value);
	}
	
	public static function amountProductCheck(IControl $control, array $args): bool
	{
		/** @var ProductRepository $repository */
		[$repository, $amountRepo, $store] = $args;
		$value = $control->getValue();
		
		$product = $repository->getProductByCodeOrEAN($value);
		
		if (!$product) {
			return false;
		}
		
		$available = $amountRepo->many()
				->where('fk_product', $product->getPK())
				->where('fk_store', $store->getPK())
				->count() == 0;
		
		if (!$available) {
			return false;
		}
		
		return true;
	}
}
