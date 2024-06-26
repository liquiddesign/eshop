<?php

declare(strict_types=1);

namespace Eshop\DB;

use Base\ShopsConfig;
use Carbon\Carbon;
use Common\DB\IGeneralRepository;
use Eshop\Exceptions\InvalidCouponException;
use Eshop\ShopperUser;
use Nette\Utils\Arrays;
use Nette\Utils\Strings;
use StORM\Collection;
use StORM\DIConnection;
use StORM\Exception\NotFoundException;
use StORM\SchemaManager;

/**
 * @extends \StORM\Repository<\Eshop\DB\DiscountCoupon>
 */
class DiscountCouponRepository extends \StORM\Repository implements IGeneralRepository
{
	public function __construct(
		DIConnection $connection,
		SchemaManager $schemaManager,
		protected readonly ShopperUser $shopperUser,
		protected readonly CartItemRepository $cartItemRepository,
		protected readonly DiscountConditionRepository $discountConditionRepository,
		protected readonly DiscountConditionCategoryRepository $discountConditionCategoryRepository,
		protected readonly CategoryRepository $categoryRepository,
		protected readonly ProductRepository $productRepository,
		protected readonly ShopsConfig $shopsConfig,
	) {
		parent::__construct($connection, $schemaManager);
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
	 * Try if coupon is valid.
	 * @param \Eshop\DB\DiscountCoupon $coupon
	 * @param \Eshop\DB\Currency $currency
	 * @param float|null $cartPrice Test only if not null
	 * @param \Eshop\DB\Customer|null $customer Always testing!
	 * @throws \Eshop\Exceptions\InvalidCouponException
	 */
	public function tryIsCouponValid(DiscountCoupon $coupon, Currency $currency, ?float $cartPrice = null, ?Customer $customer = null): void
	{
		if ($coupon->getValue('currency') !== $currency->getPK()) {
			throw new InvalidCouponException(code: InvalidCouponException::INVALID_CURRENCY);
		}

		$shop = $this->shopsConfig->getSelectedShop();

		if ($shop && ($coupon->discount->getValue('shop') !== null && $coupon->discount->getValue('shop') !== $shop->getPK() )) {
			throw new InvalidCouponException(code: InvalidCouponException::NOT_FOUND);
		}

		if (($coupon->discount->validFrom && Carbon::parse($coupon->discount->validFrom)->greaterThan(Carbon::now())) ||
			($coupon->discount->validTo && Carbon::parse($coupon->discount->validTo)->lessThan(Carbon::now()))) {
			throw new InvalidCouponException(code: InvalidCouponException::NOT_ACTIVE);
		}

		if ($coupon->usageLimit && ($coupon->usagesCount >= $coupon->usageLimit)) {
			throw new InvalidCouponException(code: InvalidCouponException::MAX_USAGE);
		}

		if ($coupon->getValue('exclusiveCustomer') && (!$customer || $coupon->getValue('exclusiveCustomer') !== $customer->getPK())) {
			throw new InvalidCouponException(code: InvalidCouponException::LIMITED_TO_EXCLUSIVE_CUSTOMER);
		}

		if ($cartPrice && $coupon->minimalOrderPrice && $coupon->minimalOrderPrice > $cartPrice) {
			throw new InvalidCouponException(code: InvalidCouponException::LOW_CART_PRICE);
		}

		if ($cartPrice && $coupon->maximalOrderPrice && $coupon->maximalOrderPrice < $cartPrice) {
			throw new InvalidCouponException(code: InvalidCouponException::HIGH_CART_PRICE);
		}
	}

	/**
	 * @TODO use DiscountRepository::getActiveDiscounts for discounts
	 * @throws \Eshop\Exceptions\InvalidCouponException
	 */
	public function getValidCouponByCart(string $code, Cart $cart, ?Customer $customer = null, bool $throw = false): ?DiscountCoupon
	{
		$showPrice = $this->shopperUser->getMainPriceType();
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
			$this->tryIsCouponValid($coupon, $cart->currency, $cartPrice, $customer);
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
				$valid = $conditionValid;

				continue;
			}

			$valid = $valid || $conditionValid;
		}

		if (!$valid) {
			if ($throw) {
				throw new InvalidCouponException(code: InvalidCouponException::INVALID_CONDITIONS);
			}

			return null;
		}

		if ($conditions = $this->discountConditionCategoryRepository->many()->where('this.fk_discountCoupon', $coupon->getPK())->toArray()) {
			/** @var array<\Eshop\DB\Product> $productsInCart */
			$productsInCart = $this->productRepository->many()->join(['eshop_cartitem'], 'this.uuid = eshop_cartitem.fk_product')
				->where('eshop_cartitem.fk_cart', $cart->getPK())
				->toArray();

			$categoriesInCart = [];

			foreach ($productsInCart as $product) {
				$categoriesInCart = \array_merge($categoriesInCart, $product->getCategories()->toArrayOf('uuid', toArrayValues: true));
			}

			$categoriesInCart = $this->categoryRepository->many()->where('this.uuid', $categoriesInCart)->toArray();

			/** @var \Eshop\DB\DiscountConditionCategory $condition */
			foreach ($conditions as $condition) {
				$conditionValid = true;

				$required = $condition->categories->toArray();

				if ($condition->cartCondition === 'isInCart') {
					if ($condition->quantityCondition === 'all') {
						foreach ($required as $requiredCategory) {
							foreach ($categoriesInCart as $categoryInCart) {
								if (Strings::startsWith($categoryInCart->path, $requiredCategory->path)) {
									break 2;
								}
							}

							$conditionValid = false;

							break;
						}
					} elseif ($condition->quantityCondition === 'atLeastOne') {
						$found = false;

						foreach ($required as $requiredCategory) {
							foreach ($categoriesInCart as $categoryInCart) {
								if (Strings::startsWith($categoryInCart->path, $requiredCategory->path)) {
									$found = true;

									break 2;
								}
							}
						}

						if (!$found) {
							$conditionValid = false;
						}
					}
				} elseif ($condition->cartCondition === 'notInCart') {
					if ($condition->quantityCondition === 'all') {
						foreach ($required as $requiredCategory) {
							foreach ($categoriesInCart as $categoryInCart) {
								if (Strings::startsWith($categoryInCart->path, $requiredCategory->path)) {
									$conditionValid = false;

									break 2;
								}
							}
						}
					} elseif ($condition->quantityCondition === 'atLeastOne') {
						$found = false;

						foreach ($required as $requiredCategory) {
							foreach ($categoriesInCart as $categoryInCart) {
								if (!Strings::startsWith($categoryInCart->path, $requiredCategory->path)) {
									$found = true;

									break 2;
								}
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
					$valid = $conditionValid;

					continue;
				}

				$valid = $conditionValid;
			}
		}

		if (!$valid && $throw) {
			throw new InvalidCouponException(code: InvalidCouponException::INVALID_CONDITIONS_CATEGORY);
		}

		return $valid ? $coupon : null;
	}
}
