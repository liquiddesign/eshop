<?php

declare(strict_types=1);

namespace Eshop\DB;

use Carbon\Carbon;
use Eshop\Exceptions\InvalidCouponException;

/**
 * Slevový kupón
 * @table
 * @index{"name":"discount_coupon_unique","unique":true,"columns":["code","fk_discount"]}
 */
class DiscountCoupon extends \StORM\Entity
{
	/**
	 * Kód
	 * @column
	 */
	public string $code;
	
	/**
	 * Popisek
	 * @column
	 */
	public ?string $label;
	
	/**
	 * Sleva v měně
	 * @column
	 */
	public ?float $discountValue;
	
	/**
	 * Sleva v měně s DPH
	 * @column
	 */
	public ?float $discountValueVat;
	
	/**
	 * Sleva (%)
	 * @column
	 */
	public ?float $discountPct;
	
	/**
	 * Poslední využití
	 * @column{"type":"timestamp"}
	 */
	public ?string $usedTs;
	
	/**
	 * Vytvořen
	 * @column{"type":"timestamp","default":"CURRENT_TIMESTAMP"}
	 */
	public ?string $createdTs;
	
	/**
	 * Kolikrát je možné využít
	 * @column
	 */
	public ?int $usageLimit;
	
	/**
	 * Kolikrát je již využito
	 * @column
	 */
	public int $usagesCount = 0;

	/**
	 * Last usage datetime
	 * @column{"type":"datetime"}
	 */
	public ?string $lastUsageTs;

	/**
	 * Minimální objednávka
	 * @column
	 */
	public ?float $minimalOrderPrice;

	/**
	 * Maximální objednávka
	 * @column
	 */
	public ?float $maximalOrderPrice;

	/**
	 * Typ
	 * @column{"type":"enum","length":"'or','and'"}
	 */
	public string $conditionsType;

	/**
	 * Maximální objednávka
	 * @column
	 */
	public bool $targitoExport = false;
	
	/**
	 * Exkluzivně pro zákazníka
	 * @constraint{"onUpdate":"CASCADE","onDelete":"CASCADE"}
	 * @relation
	 */
	public ?Customer $exclusiveCustomer;
	
	/**
	 * Měna
	 * @relation
	 * @constraint
	 */
	public Currency $currency;
	
	/**
	 * Akce
	 * @relation
	 * @constraint{"onUpdate":"CASCADE","onDelete":"CASCADE"}
	 */
	public Discount $discount;

	/**
	 * Try if coupon is valid.
	 * @param \Eshop\DB\Currency $currency
	 * @param float|null $cartPrice Test only if not null
	 * @param \Eshop\DB\Customer|null $customer Always testing!
	 * @throws \Eshop\Exceptions\InvalidCouponException
	 */
	public function tryIsValid(Currency $currency, ?float $cartPrice = null, ?Customer $customer = null): void
	{
		if ($this->getValue('currency') !== $currency->getPK()) {
			throw new InvalidCouponException(code: InvalidCouponException::INVALID_CURRENCY);
		}

		if (($this->discount->validFrom && Carbon::parse($this->discount->validFrom)->lessThan(Carbon::now())) ||
			($this->discount->validTo && Carbon::parse($this->discount->validTo)->greaterThanOrEqualTo(Carbon::now()))) {
			throw new InvalidCouponException(code: InvalidCouponException::NOT_ACTIVE);
		}

		if ($this->usageLimit && ($this->usagesCount < $this->usageLimit)) {
			throw new InvalidCouponException(code: InvalidCouponException::MAX_USAGE);
		}

		if ($this->getValue('exclusiveCustomer') && (!$customer || $this->getValue('exclusiveCustomer') !== $customer->getPK())) {
			throw new InvalidCouponException(code: InvalidCouponException::LIMITED_TO_EXCLUSIVE_CUSTOMER);
		}

		if ($cartPrice && $this->minimalOrderPrice && $this->minimalOrderPrice > $cartPrice) {
			throw new InvalidCouponException(code: InvalidCouponException::LOW_CART_PRICE);
		}

		if ($cartPrice && $this->maximalOrderPrice && $this->maximalOrderPrice < $cartPrice) {
			throw new InvalidCouponException(code: InvalidCouponException::HIGH_CART_PRICE);
		}
	}
}
