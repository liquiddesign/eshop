<?php

declare(strict_types=1);

namespace Eshop\DB;

use Common\DB\IGeneralRepository;
use Eshop\Exceptions\InvalidCouponException;
use Eshop\Shopper;
use Nette\Utils\Arrays;
use StORM\Collection;
use StORM\DIConnection;
use StORM\Exception\NotFoundException;
use StORM\SchemaManager;

/**
 * @extends \StORM\Repository<\Eshop\DB\DiscountCoupon>
 */
class DiscountCouponRepository extends \StORM\Repository implements IGeneralRepository
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
	 * @inheritDoc
	 */
	public function getArrayForSelect(bool $includeHidden = true): array
	{
		return $this->getCollection($includeHidden)
			->select(['fullName' => "CONCAT(label, ' (', code, ')')"])
			->toArrayOf('fullName');
	}

	public function getCollection(bool $includeHidden = false): Collection
	{
		$collection = $this->many();

		unset($includeHidden);

		return $collection->orderBy(['label']);
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
	 * @TODO use DiscountRepository::getActiveDiscounts for discounts
	 * @throws \Eshop\Exceptions\InvalidCouponException
	 */
	public function getValidCouponByCart(string $code, Cart $cart, ?Customer $customer = null, bool $throw = false): ?DiscountCoupon
	{
		$showPrice = $this->shopper->getShowPrice();
		$priceType = $showPrice === 'withVat' ? 'priceVat' : 'price';

		try {
			$coupon = $this->many()->where('code', $code)->first(true);
		} catch (NotFoundException $e) {
			if ($throw) {
				throw new InvalidCouponException(code: InvalidCouponException::NOT_FOUND);
			}

			return null;
		}

		$cartPrice = $this->cartItemRepository->getSumProperty([$cart->getPK()], $priceType);

		try {
			$coupon->tryIsValid($cart->currency, $cartPrice, $customer);
		} catch (InvalidCouponException $e) {
			if ($throw) {
				throw $e;
			}

			return null;
		}

		$productsInCart = \array_keys($this->cartItemRepository->many()->where('this.fk_cart', $cart->getPK())->setIndex('fk_product')->toArrayOf('product'));
		$conditions = $this->discountConditionRepository->many()->where('this.fk_discountCoupon', $coupon->getPK());

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

		if (!$valid && $throw) {
			throw new InvalidCouponException(code: InvalidCouponException::INVALID_CONDITIONS);
		}

		return $valid ? $coupon : null;
	}
}
