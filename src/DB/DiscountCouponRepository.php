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
//			->join(['conditions' => 'eshop_discountconditions'], 'this.uuid = conditions.fk_discountCoupon')
//			->join(['conditionsProducts' => 'eshop_discountcondition_nxn_eshop_product'], 'conditions.uuid = conditionsProducts.fk_discountcondition')
//			->join(['cartItems' => 'eshop_cartItem'], 'cartItems.fk_product = conditionsProducts.fk_product')
//			->where('cartItems.fk_cart', $cart->getPK())
//			->where('(conditions.cartCondition = "isInCart" AND ((conditions.quantityCondition = "all" AND ...) OR (conditions.quantityCondition = "atLeastOne" AND ...))) OR
//			(conditions.cartCondition = "notInCart" AND ((conditions.quantityCondition = "all" AND ...) OR (conditions.quantityCondition = "atLeastOne" AND ...)))');

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
		$conditions = $this->discountConditionRepository->many()->where('this.fk_discountCoupon', $coupon->getPK());

		$valid = true;

		/** @var \Eshop\DB\DiscountCondition $condition */
		foreach ($conditions as $condition) {
			$required = \array_values($condition->products->toArrayOf('uuid'));

			if ($condition->cartCondition === 'isInCart') {
				if ($condition->quantityCondition === 'all') {
					foreach ($required as $requiredProduct) {
						if (!Arrays::contains($productsInCart, $requiredProduct)) {
							$valid = false;

							break;
						}
					}

					if (!$valid) {
						break;
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
						$valid = false;

						break;
					}
				}
			} elseif ($condition->cartCondition === 'notInCart') {
				if ($condition->quantityCondition === 'all') {
					foreach ($required as $requiredProduct) {
						if (Arrays::contains($productsInCart, $requiredProduct)) {
							$valid = false;

							break;
						}
					}

					if (!$valid) {
						break;
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
						$valid = false;

						break;
					}
				}
			}
		}

		return $valid ? $coupon : null;
	}
}
