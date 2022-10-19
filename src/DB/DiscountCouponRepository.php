<?php

declare(strict_types=1);

namespace Eshop\DB;

use Eshop\Shopper;
use Nette\Utils\Arrays;
use StORM\DIConnection;
use StORM\SchemaManager;

/**
 * @extends \StORM\Repository<\Eshop\DB\DiscountCoupon>
 */
class DiscountCouponRepository extends \StORM\Repository
{
	private Shopper $shopper;

	private CartItemRepository $cartItemRepository;

	private DiscountConditionRepository $discountConditionRepository;

	public function __construct(
		DIConnection $connection,
		SchemaManager $schemaManager,
		Shopper $shopper,
		CartItemRepository $cartItemRepository,
		DiscountConditionRepository $discountConditionRepository
	) {
		parent::__construct($connection, $schemaManager);

		$this->shopper = $shopper;
		$this->cartItemRepository = $cartItemRepository;
		$this->discountConditionRepository = $discountConditionRepository;
	}

	/**
	 * @deprecated use DiscountRepository::getValidCoupon
	 * @TODO use DiscountRepository::getActiveDiscounts for discounts
	*/
	public function getValidCoupon(string $code, Currency $currency, ?Customer $customer = null): ?DiscountCoupon
	{
		$collection = $this->many()
			->where('code', $code)
			->where('fk_currency', $currency->getPK())
			->where('discount.validFrom IS NULL OR discount.validFrom <= now()')
			->where('discount.validTo IS NULL OR discount.validTo >= now()')
			->where('this.usageLimit IS NULL OR (this.usagesCount < this.usageLimit)');

		if ($customer) {
			$collection->where('fk_exclusiveCustomer IS NULL OR fk_exclusiveCustomer = :customer', ['customer' => $customer]);
		} else {
			$collection->where('fk_exclusiveCustomer IS NULL');
		}

		return $collection->first();
	}

	/**
	 * @TODO use DiscountRepository::getActiveDiscounts for discounts and move to DiscountRepository
	 */
	public function getValidCouponByCart(string $code, Cart $cart, ?Customer $customer = null): ?DiscountCoupon
	{
		$showPrice = $this->shopper->getShowPrice();
		$priceType = $showPrice === 'withVat' ? 'priceVat' : 'price';

		$collection = $this->many()
			->where('code', $code)
			->where('fk_currency', $cart->getValue('currency'))
			->where('discount.validFrom IS NULL OR discount.validFrom <= now()')
			->where('discount.validTo IS NULL OR discount.validTo >= now()')
			->where('this.usageLimit IS NULL OR (this.usagesCount < this.usageLimit)')
			->where(
				'(this.minimalOrderPrice IS NULL OR this.minimalOrderPrice <= :cartPrice) AND (this.maximalOrderPrice IS NULL OR this.maximalOrderPrice >= :cartPrice)',
				['cartPrice' => $this->cartItemRepository->getSumProperty([$cart->getPK()], $priceType)],
			);

		if ($customer) {
			$collection->where('fk_exclusiveCustomer IS NULL OR fk_exclusiveCustomer = :customer', ['customer' => $customer]);
		} else {
			$collection->where('fk_exclusiveCustomer IS NULL');
		}

		/** @var \Eshop\DB\DiscountCoupon|null $coupon */
		$coupon = $collection->first();

		if (!$coupon) {
			return null;
		}

		$productsInCart = \array_keys($this->cartItemRepository->many()->where('this.fk_cart', $cart->getPK())->setIndex('fk_product')->toArrayOf('product'));
		$conditions = $this->discountConditionRepository->many()->where('this.fk_discountCoupon', $coupon->getPK())->toArray();

		$conditionType = $coupon->conditionsType;
		$valid = $conditionType === 'and';

		/** @var \Eshop\DB\DiscountCondition $condition */
		foreach ($conditions as $condition) {
			$conditionValid = true;

			$required = \array_values($condition->products->toArrayOf('uuid'));

			if ($condition->cartCondition === 'isInCart') {
				if ($condition->quantityCondition === 'all') {
					foreach ($required as $requiredProduct) {
						if (!Arrays::contains($productsInCart, $requiredProduct)) {
							$conditionValid = false;

							break;
						}
					}
				} elseif ($condition->quantityCondition === 'atLeastOne') {
					$found = false;

					foreach ($required as $requiredProduct) {
						if (Arrays::contains($productsInCart, $requiredProduct)) {
							$found = true;

							break;
						}
					}

					if (!$found) {
						$conditionValid = false;
					}
				}
			} elseif ($condition->cartCondition === 'notInCart') {
				if ($condition->quantityCondition === 'all') {
					foreach ($required as $requiredProduct) {
						if (Arrays::contains($productsInCart, $requiredProduct)) {
							$conditionValid = false;

							break;
						}
					}
				} elseif ($condition->quantityCondition === 'atLeastOne') {
					$found = false;

					foreach ($required as $requiredProduct) {
						if (!Arrays::contains($productsInCart, $requiredProduct)) {
							$found = true;

							break;
						}
					}

					if (!$found) {
						$conditionValid = false;
					}
				}
			}

			if (!$conditionValid && $conditionType === 'and') {
				$valid = false;

				break;
			}

			if ($conditionValid && $conditionType === 'or') {
				$valid = true;

				break;
			}

			if ($conditionType === 'and') {
				$valid = $valid && $conditionValid;

				continue;
			}

			$valid = $valid || $conditionValid;
		}

		return !$conditions ? $coupon : ($valid ? $coupon : null);
	}
}
